<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Services\ErpConnection;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ErpConnectionTest extends TestCase
{
    use RefreshDatabase;

    private string $projection;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir().'/cec-erp-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $this->projection = $dir.'/campus.php';
        config(['erp.projection_path' => $this->projection]);
        User::factory()->create(['role' => 'admin']);
        $this->data = ['schema' => 1, 'revision' => 1, 'updated_at' => '2026-11-14T12:00:00Z', 'courses' => [['id' => 'CUR-001', 'name' => 'Conversación desde Gestión', 'active' => true], ['id' => 'CUR-002', 'name' => 'Otro curso', 'active' => true]], 'students' => [['id' => 'A-001', 'email' => 'student@example.test', 'active' => true, 'courses' => ['CUR-001']]], 'passes' => [['id' => 'B-001', 'student_id' => 'A-001', 'name' => 'Bono de ocho clases', 'sessions' => 8, 'used' => 3, 'remaining' => 5, 'start' => '2026-11-01', 'expires' => '2026-12-30'], ['id' => 'B-002', 'student_id' => 'A-002', 'name' => 'Bono privado de otra persona', 'sessions' => 12, 'used' => 1, 'remaining' => 11, 'start' => '', 'expires' => '']]];
        $this->write();
    }

    private function write(): void
    {
        file_put_contents($this->projection, "<?php exit; ?>\n".json_encode($this->data));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->projection));
        parent::tearDown();
    }

    private function pupil(array $attributes = []): User
    {
        return User::factory()->create(array_replace(['email' => 'student@example.test'], $attributes));
    }

    public function test_unique_confirmed_email_links_courses_and_only_own_passes_idempotently(): void
    {
        $u = $this->pupil();
        $this->actingAs($u)->get('/mis-accesos')->assertOk()->assertSee('Conversación desde Gestión')->assertSee('Bono de ocho clases')->assertSee('clases restantes de')->assertDontSee('Bono privado de otra persona')->assertDontSee('Otro curso');
        $this->assertSame('A-001', $u->fresh()->erp_student_id);
        $this->assertDatabaseCount('enrollments', 1);
        $this->get('/campus')->assertOk()->assertSee('Conversación desde Gestión');
        $this->assertDatabaseCount('courses', 2);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseHas('enrollments', ['user_id' => $u->id, 'source_erp' => true, 'source_manual' => false]);
    }

    public function test_unverified_account_does_not_receive_private_data_even_with_matching_email(): void
    {
        $u = $this->pupil(['email_verified_at' => null]);
        $this->actingAs($u)->get('/mis-accesos')->assertOk()->assertSee('Confirma tu correo')->assertDontSee('Bono de ocho clases')->assertDontSee('Conversación desde Gestión');
        $this->assertDatabaseCount('enrollments', 0);
        $u->markEmailAsVerified();
        $this->get('/mis-accesos')->assertSee('Bono de ocho clases');
    }

    public function test_duplicate_email_never_merges_students_or_leaks_their_data(): void
    {
        $this->data['students'][] = ['id' => 'A-002', 'email' => 'STUDENT@example.test', 'active' => true, 'courses' => ['CUR-002']];
        $this->write();
        $this->actingAs($this->pupil())->get('/mis-accesos')->assertOk()->assertSee('Necesitamos revisar')->assertDontSee('Bono de ocho clases')->assertDontSee('Bono privado de otra persona');
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_removals_archive_and_new_courses_reconcile_without_erasing_manual_access_or_content(): void
    {
        $u = $this->pupil();
        ErpConnection::sync($u);
        $course = Course::where('erp_id', 'CUR-001')->first();
        $course->lessons()->create(['title' => 'Contenido conservado', 'unit' => 'Unidad 1']);
        $manual = Course::create(['teacher_id' => User::first()->id, 'title' => 'Curso manual', 'published' => true]);
        $manual->students()->attach($u);
        $this->data['revision'] = 2;
        $this->data['students'][0]['courses'] = ['CUR-002'];
        $this->data['courses'][0]['name'] = 'Nombre actualizado';
        $this->write();
        $this->actingAs($u)->get('/mis-accesos')->assertSee('Otro curso')->assertSee('Curso manual')->assertDontSee('Nombre actualizado');
        $this->get('/cursos/'.$course->id)->assertForbidden();
        $this->assertDatabaseHas('lessons', ['title' => 'Contenido conservado']);
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'title' => 'Nombre actualizado']);
        $other = Course::where('erp_id', 'CUR-002')->first();
        $this->data['revision'] = 3;
        $this->data['courses'][1]['active'] = false;
        $this->write();
        $this->get('/cursos/'.$other->id)->assertForbidden();
        $this->get('/mis-accesos')->assertSee('Curso manual')->assertDontSee('Otro curso');
    }

    public function test_manual_membership_is_preserved_when_erp_assignment_disappears(): void
    {
        $u = $this->pupil();
        ErpConnection::sync($u);
        $course = Course::where('erp_id', 'CUR-001')->first();
        DB::table('enrollments')->where('user_id', $u->id)->update(['source_manual' => true]);
        $this->data['revision'] = 2;
        $this->data['students'][0]['courses'] = [];
        $this->write();
        ErpConnection::sync($u);
        $this->assertDatabaseHas('enrollments', ['user_id' => $u->id, 'course_id' => $course->id, 'source_erp' => false, 'source_manual' => true]);
    }

    public function test_revoked_or_missing_source_removes_erp_access_and_hides_balances(): void
    {
        $u = $this->pupil();
        ErpConnection::sync($u);
        $this->data['revision'] = 2;
        $this->data['students'][0]['active'] = false;
        $this->write();
        $this->actingAs($u)->get('/mis-accesos')->assertSee('No hay una ficha activa')->assertDontSee('Bono de ocho clases');
        $this->assertDatabaseCount('enrollments', 0);
        $this->data['revision'] = 3;
        $this->data['students'][0]['active'] = true;
        $this->write();
        ErpConnection::sync($u);
        unlink($this->projection);
        $this->get('/mis-accesos')->assertOk()->assertDontSee('Bono de ocho clases');
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_pass_status_consumptions_cancellations_and_expiry_match_gestion_rules(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 14)->setTime(12, 0));
        $u = $this->pupil();
        $result = ErpConnection::sync($u);
        $this->assertSame(5, $result['passes'][0]['remaining']);
        $this->assertSame('Activo', $result['passes'][0]['status']);
        $this->data['revision'] = 2;
        $this->data['passes'][0]['used'] = 2;
        $this->data['passes'][0]['expires'] = '2026-11-14';
        $this->write();
        $result = ErpConnection::sync($u);
        $this->assertSame(6, $result['passes'][0]['remaining']);
        $this->assertSame('Activo', $result['passes'][0]['status']);
        $this->data['revision'] = 3;
        $this->data['passes'][0]['expires'] = '2026-11-13';
        $this->write();
        $this->assertSame('Caducado', ErpConnection::sync($u)['passes'][0]['status']);
        $this->data['revision'] = 4;
        $this->data['passes'][0]['used'] = 8;
        $this->write();
        $this->assertSame('Agotado', ErpConnection::sync($u)['passes'][0]['status']);
        $this->data['revision'] = 5;
        $this->data['passes'][0]['used'] = 0;
        $this->data['passes'][0]['start'] = '2026-12-01';
        $this->write();
        $this->assertSame('Pendiente de inicio', ErpConnection::sync($u)['passes'][0]['status']);
    }

    public function test_email_changes_cannot_transfer_an_existing_link_to_another_student(): void
    {
        $u = $this->pupil();
        ErpConnection::sync($u);
        $this->data['revision'] = 2;
        $this->data['students'] = [['id' => 'A-002', 'email' => 'student@example.test', 'active' => true, 'courses' => ['CUR-002']]];
        $this->write();
        $this->actingAs($u)->get('/mis-accesos')->assertOk()->assertSee('Necesitamos revisar')->assertDontSee('Bono privado de otra persona')->assertDontSee('Otro curso');
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_email_confirmation_requires_owner_signed_link_or_explicit_admin_attestation(): void
    {
        $u = $this->pupil(['email_verified_at' => null]);
        $other = User::factory()->create(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $u->id, 'hash' => sha1($u->email)]);
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($u)->get(str_replace('signature=', 'signature=bad', $url))->assertForbidden();
        $this->get($url)->assertRedirect('/mis-accesos');
        $this->assertNotNull($u->fresh()->email_verified_at);
        $this->post('/docente/usuarios/'.$other->id.'/confirmar-correo', ['confirmed' => 1])->assertForbidden();
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->post('/docente/usuarios/'.$other->id.'/confirmar-correo', [])->assertSessionHasErrors('confirmed');
        $this->post('/docente/usuarios/'.$other->id.'/confirmar-correo', ['confirmed' => 1])->assertRedirect();
        $this->assertSame($admin->id, $other->fresh()->email_confirmed_by);
    }

    public function test_email_delivery_is_honest_without_smtp_and_sends_verification_when_configured(): void
    {
        $u = $this->pupil(['email_verified_at' => null]);
        $this->actingAs($u)->post('/cuenta/confirmar-correo')->assertSessionHas('status', 'Contacta con el centro para confirmar tu correo y conectar tus cursos y bonos.');
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $this->post('/cuenta/confirmar-correo')->assertRedirect();
        Notification::assertSentTo($u, VerifyEmail::class);
    }

    public function test_erp_controls_title_publication_and_membership_while_admin_assigns_instructor(): void
    {
        $u = $this->pupil();
        ErpConnection::sync($u);
        $course = Course::where('erp_id', 'CUR-001')->first();
        $admin = User::where('role', 'admin')->first();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($admin)->put('/docente/cursos/'.$course->id, ['title' => 'Nombre ajeno al ERP', 'description' => 'Contenidos propios', 'language' => 'Inglés', 'level' => 'B1', 'published' => false, 'teacher_id' => $teacher->id])->assertRedirect();
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'title' => 'Conversación desde Gestión', 'published' => true, 'teacher_id' => $teacher->id, 'description' => 'Contenidos propios']);
        $this->delete('/docente/cursos/'.$course->id.'/matriculas/'.$u->id)->assertSessionHasErrors('email');
        $this->assertDatabaseHas('enrollments', ['user_id' => $u->id, 'source_erp' => true]);
        $this->actingAs($teacher)->get('/docente/cursos/'.$course->id)->assertOk()->assertSee('Contenidos propios');
    }

    public function test_invalid_projection_fails_closed_without_showing_balances(): void
    {
        $u = $this->pupil();
        ErpConnection::sync($u);
        file_put_contents($this->projection, 'broken source');
        $this->actingAs($u)->get('/mis-accesos')->assertOk()->assertDontSee('Bono de ocho clases')->assertDontSee('Conversación desde Gestión');
        $this->assertDatabaseCount('enrollments', 0);
    }
}
