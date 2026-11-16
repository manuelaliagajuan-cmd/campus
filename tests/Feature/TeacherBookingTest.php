<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TeacherBookingTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private User $teacher;

    private User $other;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cec-booking-api-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->other = User::factory()->create(['role' => 'teacher']);
        $this->student = User::factory()->create();
        User::factory()->create(['role' => 'admin']);
        config(['erp.projection_path' => $this->dir.'/campus.php', 'erp.teachers_core' => base_path('tests/Fixtures/erp-teachers.php')]);
        $data = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [], 'students' => [['id' => 'A-1', 'name' => 'Pupil', 'email' => $this->student->email, 'active' => true, 'courses' => []]], 'passes' => [['id' => 'BON-1', 'student_id' => 'A-1', 'name' => 'Bono', 'teacher_id' => $this->teacher->id, 'sessions' => 5, 'used' => 0, 'remaining' => 5, 'start' => '', 'expires' => '']]];
        file_put_contents($this->dir.'/campus.php', "<?php exit; ?>\n".json_encode($data));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        unset($GLOBALS['cec_teacher_booking']);
        parent::tearDown();
    }

    public function test_only_assigned_teacher_or_admin_can_schedule_and_financial_fields_are_never_forwarded(): void
    {
        $data = ['action' => 'create', 'pass_id' => 'BON-1', 'revision' => 1, 'date' => now('Europe/Madrid')->addDays(2)->toDateString(), 'start' => '10:00', 'end' => '10:50', 'occurrences' => 2, 'sessions' => 999, 'teacher_id' => $this->other->id, 'logs' => []];
        $this->actingAs($this->student)->postJson(route('staff.schedule.booking'), $data)->assertForbidden();
        $this->actingAs($this->other)->postJson(route('staff.schedule.booking'), $data)->assertForbidden();
        $this->actingAs($this->teacher)->postJson(route('staff.schedule.booking'), $data)->assertOk()->assertJsonPath('revision', 2);
        $saved = $GLOBALS['cec_teacher_booking'];
        $this->assertSame($this->teacher->id, $saved['teacher']);
        $this->assertArrayNotHasKey('sessions', $saved['input']);
        $this->assertArrayNotHasKey('teacher_id', $saved['input']);
        $this->assertArrayNotHasKey('logs', $saved['input']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(route('staff.schedule.booking'), $data)->assertOk();
    }

    public function test_cancellation_requires_reason_and_booking_and_schedule_form_is_staff_only(): void
    {
        $this->actingAs($this->teacher)->postJson(route('staff.schedule.booking'), ['action' => 'cancel', 'pass_id' => 'BON-1', 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors(['booking_id', 'reason']);
        $this->get('/mi-horario?bono=BON-1')->assertOk()->assertSee('Programar clase de bono')->assertSee('Los ajustes manuales del saldo se realizan en Gestión.');
        $this->actingAs($this->student)->get('/mi-horario')->assertForbidden();
    }
}
