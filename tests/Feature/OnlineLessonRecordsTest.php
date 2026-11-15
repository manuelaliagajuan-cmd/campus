<?php

namespace Tests\Feature;

use App\Models\OnlineSession;
use App\Models\User;
use App\Services\OnlineLessonRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OnlineLessonRecordsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private array $source;

    private User $teacher;

    private User $student;

    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 13:00', 'Europe/Madrid'));
        $this->dir = sys_get_temp_dir().'/cec-attendance-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        config(['erp.projection_path' => $this->dir.'/campus.php', 'erp.lesson_core' => base_path('tests/Fixtures/erp-lessons.php')]);
        User::factory()->create(['role' => 'admin']);
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->student = User::factory()->create(['role' => 'student', 'erp_student_id' => 'A-1']);
        $this->source = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'cancellation_hours' => 24, 'courses' => [], 'students' => [['id' => 'A-1', 'name' => 'Pupil', 'email' => $this->student->email, 'active' => true, 'courses' => []]], 'passes' => [['id' => 'BON-1', 'student_id' => 'A-1', 'name' => 'Online pass', 'sessions' => 1, 'used' => 0, 'remaining' => 1, 'start' => '', 'expires' => '', 'teacher_id' => $this->teacher->id, 'online_enabled' => true, 'bookings' => [['id' => 'CITA-1', 'date' => '2026-11-17', 'start' => '14:00', 'end' => '14:50', 'completed' => false]]]]];
        $this->write();
        $GLOBALS['cec_pending_notices'] = [];
        $GLOBALS['cec_lesson_change'] = function ($input) {
            $this->calls[] = $input;

            return ['created_at' => now()->toIso8601String(), 'reason' => $input['action']];
        };
    }

    private function write(): void
    {
        file_put_contents($this->dir.'/campus.php', "<?php exit; ?>\n".json_encode($this->source));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        unset($GLOBALS['cec_lesson_change'],$GLOBALS['cec_pending_notices'],$GLOBALS['cec_email_status']);
        parent::tearDown();
    }

    private function start(): OnlineSession
    {
        $this->actingAs($this->student)->post(route('online.start'), ['scope' => 'pass:BON-1:A-1', 'booking_id' => 'CITA-1'])->assertRedirect();

        return OnlineSession::firstOrFail();
    }

    private function join(User $u, OnlineSession $s): string
    {
        return $this->actingAs($u)->postJson(route('online.join', $s))->assertOk()->json('participant');
    }

    public function test_only_real_overlap_of_assigned_teacher_and_linked_pupil_consumes_once_even_after_reconnect(): void
    {
        $s = $this->start();
        $p = $this->join($this->student, $s);
        $this->assertCount(0, $this->calls);
        $this->travel(41)->seconds();
        $t = $this->join($this->teacher, $s);
        $this->assertCount(0, $this->calls);
        $this->actingAs($this->student)->getJson(route('online.poll', $s).'?participant='.$p)->assertOk();
        $this->assertCount(1, $this->calls);
        $this->assertSame('coincidence', $s->fresh()->consumption_reason);
        $this->join($this->student, $s);
        $this->assertCount(1, $this->calls);
        $this->source['passes'][0]['used'] = 1;
        $this->source['passes'][0]['remaining'] = 0;
        $this->source['passes'][0]['bookings'][0]['completed'] = true;
        $this->source['passes'][0]['bookings'][0]['online_consumed'] = true;
        $this->source['revision']++;
        $this->write();
        $this->actingAs($this->student)->get(route('online.show', $s))->assertOk();
    }

    public function test_teacher_must_be_present_during_scheduled_time_and_no_show_waits_for_end(): void
    {
        $s = $this->start();
        $t = $this->join($this->teacher, $s);
        $this->assertNull($s->fresh()->teacher_seen_at);
        $this->travelTo(Carbon::parse('2026-11-17 14:05', 'Europe/Madrid'));
        $this->actingAs($this->teacher)->getJson(route('online.poll', $s).'?participant='.$t)->assertOk();
        $this->assertNotNull($s->fresh()->teacher_seen_at);
        OnlineLessonRecords::reconcile();
        $this->assertCount(0, $this->calls);
        $this->travelTo(Carbon::parse('2026-11-17 14:50', 'Europe/Madrid'));
        OnlineLessonRecords::reconcile();
        OnlineLessonRecords::reconcile();
        $this->assertCount(1, $this->calls);
        $this->assertSame('no_show', $s->fresh()->consumption_reason);
    }

    public function test_neither_teacher_absence_nor_early_device_check_alone_is_a_no_show(): void
    {
        $s = $this->start();
        $this->join($this->teacher, $s);
        $this->travelTo(Carbon::parse('2026-11-17 15:00', 'Europe/Madrid'));
        OnlineLessonRecords::reconcile();
        $this->assertCount(0, $this->calls);
    }

    public function test_cancellation_is_owner_only_requires_reason_and_enforces_current_policy_in_writer(): void
    {
        $this->source['passes'][0]['bookings'][0]['date'] = '2026-11-20';
        $this->write();
        $payload = ['scope' => 'pass:BON-1:A-1', 'booking_id' => 'CITA-1', 'reason' => 'Medical appointment'];
        $this->actingAs($this->teacher)->post(route('online.cancel'), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('online.cancel'), $payload)->assertForbidden();
        $this->actingAs($this->student)->postJson(route('online.cancel'), array_replace($payload, ['reason' => '']))->assertUnprocessable();
    }

    public function test_cancellation_creates_durable_recipient_mail_and_sends_each_recipient_only_once(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->source['passes'][0]['bookings'][0]['date'] = '2026-11-20';
        $this->write();
        $GLOBALS['cec_lesson_change'] = function ($input) {
            return ['id' => 'CANCEL-1', 'student_name' => 'Pupil', 'pass_name' => 'Online pass', 'date' => $input['date'], 'start' => $input['start'], 'end' => $input['end'], 'reason' => $input['reason'], 'cancellation_hours' => 24] + $input;
        };
        $this->actingAs($this->student)->post(route('online.cancel'), ['scope' => 'pass:BON-1:A-1', 'booking_id' => 'CITA-1', 'reason' => 'Medical appointment'])->assertRedirect(route('online.index'));
        $row = DB::table('lesson_cancellation_mail')->first();
        $this->assertNotNull($row->student_sent_at);
        $this->assertNotNull($row->teacher_sent_at);
        $this->assertSame('sent', $GLOBALS['cec_email_status']['CANCEL-1']);
        OnlineLessonRecords::deliverMail();
        Mail::assertSentCount(2);
    }

    public function test_signed_processor_rejects_unsigned_or_expired_calls(): void
    {
        config(['erp.jobs_key' => 'unit-test-key']);
        $this->post('/interno/clases/procesar')->assertForbidden();
        $stamp = (string) (time() - 600);
        $this->withHeaders(['X-CEC-Time' => $stamp, 'X-CEC-Signature' => hash_hmac('sha256', $stamp, 'unit-test-key')])->post('/interno/clases/procesar')->assertForbidden();
        $stamp = (string) time();
        $this->withHeaders(['X-CEC-Time' => $stamp, 'X-CEC-Signature' => hash_hmac('sha256', $stamp, 'unit-test-key')])->post('/interno/clases/procesar')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_cancel_button_is_disabled_within_deadline_and_writer_failure_keeps_booking(): void
    {
        $this->actingAs($this->student)->get(route('online.index'))->assertOk()->assertSee('El plazo de cancelación ha terminado')->assertDontSee('Confirmar cancelación');
        $GLOBALS['cec_lesson_change'] = fn ($input) => throw new \InvalidArgumentException('El plazo de cancelación sin penalización ha terminado.');
        $this->post(route('online.cancel'), ['scope' => 'pass:BON-1:A-1', 'booking_id' => 'CITA-1', 'reason' => 'Too late'])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('lesson_cancellation_mail', 0);
    }
}
