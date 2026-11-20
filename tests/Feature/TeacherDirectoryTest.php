<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TeacherDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TeacherDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cec-teacher-users-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        config(['erp.projection_path' => $this->dir.'/campus.php', 'erp.teachers_core' => base_path('tests/Fixtures/erp-teachers.php')]);
        $GLOBALS['cec_teacher_profiles'] = [];
        $GLOBALS['cec_teacher_approvals'] = [];
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        unset($GLOBALS['cec_teacher_profiles'],$GLOBALS['cec_agenda_teacher'],$GLOBALS['cec_teacher_leave'],$GLOBALS['cec_teacher_approvals']);
        parent::tearDown();
    }

    public function test_teacher_profile_creates_account_and_private_activation_without_student_or_enrolment(): void
    {
        $GLOBALS['cec_teacher_profiles'] = [['id' => 'PROF-001', 'name' => 'New teacher', 'email' => 'teacher@example.test', 'active' => true, 'createCampus' => true]];
        TeacherDirectory::sync();
        $u = User::where('email', 'teacher@example.test')->firstOrFail();
        $this->assertSame('teacher', $u->role);
        $this->assertSame('PROF-001', $u->erp_teacher_id);
        $this->assertTrue($u->teacher_access_pending);
        $this->assertNull($u->erp_student_id);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('store_orders', 0);
        $this->assertSame('invited', $GLOBALS['cec_teacher_profiles'][0]['campusStatus']);
        $raw = file_get_contents($this->dir.'/campus-teacher-access.php');
        $invites = json_decode(substr($raw, 15), true);
        $this->assertStringContainsString('/restablecer/', $invites['PROF-001']['url']);
        $this->assertSame(0600, fileperms($this->dir.'/campus-teacher-access.php') & 0777);
        $this->assertStringNotContainsString('/restablecer/', file_get_contents($this->dir.'/campus-accounts.php'));
        TeacherDirectory::sync();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($raw, file_get_contents($this->dir.'/campus-teacher-access.php'));
    }

    public function test_existing_student_is_never_promoted_by_email_match(): void
    {
        $u = User::factory()->create(['email' => 'same@example.test', 'role' => 'student']);
        $GLOBALS['cec_teacher_profiles'] = [['id' => 'PROF-001', 'name' => 'Teacher', 'email' => $u->email, 'active' => true, 'createCampus' => true]];
        TeacherDirectory::sync();
        $this->assertSame('student', $u->fresh()->role);
        $this->assertSame('review', $GLOBALS['cec_teacher_profiles'][0]['campusStatus']);
        $this->assertFileDoesNotExist($this->dir.'/campus-teacher-access.php');
    }

    public function test_explicit_existing_staff_link_preserves_role_and_password(): void
    {
        $u = User::factory()->create(['role' => 'admin']);
        $hash = $u->password;
        $GLOBALS['cec_teacher_profiles'] = [['id' => 'PROF-001', 'name' => $u->name, 'email' => $u->email, 'campusId' => $u->id, 'active' => true, 'createCampus' => true]];
        TeacherDirectory::sync();
        $this->assertSame('admin', $u->fresh()->role);
        $this->assertSame($hash, $u->fresh()->password);
        $this->assertSame('active', $GLOBALS['cec_teacher_profiles'][0]['campusStatus']);
    }

    public function test_only_owner_or_admin_can_read_agenda_and_teacher_can_only_block_their_own_days(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create();
        $this->actingAs($student)->get('/mi-horario')->assertForbidden();
        $this->getJson('/mi-horario/datos?month=2026-11')->assertForbidden();
        $this->actingAs($teacher)->getJson('/mi-horario/datos?month=2026-11&teacher_id='.$other->id)->assertForbidden();
        $this->getJson('/mi-horario/datos?month=2026-11')->assertOk()->assertJsonPath('teacher.id', $teacher->id);
        $this->postJson('/mi-horario/ausencias', ['teacher_id' => $other->id, 'action' => 'add', 'date' => '2026-12-01', 'endDate' => '2026-12-03', 'reason' => 'Holiday'])->assertOk();
        $this->assertSame($teacher->id, $GLOBALS['cec_teacher_leave']['teacher']);
        $this->assertArrayNotHasKey('teacher_id', $GLOBALS['cec_teacher_leave']['input']);
    }

    public function test_activation_link_works_once_and_stops_being_offered_after_password_setup(): void
    {
        $GLOBALS['cec_teacher_profiles'] = [['id' => 'PROF-001', 'name' => 'Teacher', 'email' => 'newteacher@example.test', 'active' => true, 'createCampus' => true]];
        TeacherDirectory::sync();
        $entry = json_decode(substr(file_get_contents($this->dir.'/campus-teacher-access.php'), 15), true)['PROF-001'];
        $url = parse_url($entry['url']);
        $token = basename($url['path']);
        $this->post('/restablecer', ['token' => $token, 'email' => 'newteacher@example.test', 'password' => 'TeacherSecure12345', 'password_confirmation' => 'TeacherSecure12345'])->assertRedirect('/acceso');
        $this->assertFalse(User::where('email', 'newteacher@example.test')->first()->teacher_access_pending);
        $this->assertSame('active', $GLOBALS['cec_teacher_profiles'][0]['campusStatus']);
        $this->assertSame([], json_decode(substr(file_get_contents($this->dir.'/campus-teacher-access.php'), 15), true));
        $this->post('/restablecer', ['token' => $token, 'email' => 'newteacher@example.test', 'password' => 'OtherPassword12345', 'password_confirmation' => 'OtherPassword12345'])->assertSessionHasErrors('email');
    }

    public function test_explicit_erp_admin_approval_promotes_only_the_bound_account_and_keeps_password(): void
    {
        $u = User::factory()->create(['email' => 'approved@example.test', 'role' => 'student', 'erp_student_id' => 'OLD-TEST']);
        $other = User::factory()->create(['role' => 'student']);
        $hash = $u->password;
        $GLOBALS['cec_teacher_profiles'] = [['id' => 'PROF-001', 'name' => 'Approved teacher', 'email' => $u->email, 'active' => true, 'createCampus' => true]];
        $GLOBALS['cec_teacher_approvals']['PROF-001'] = ['account_id' => $u->id, 'email' => $u->email, 'expiresAt' => time() + 600, 'nonce' => 'unit-nonce'];
        TeacherDirectory::sync();
        $this->assertSame('teacher', $u->fresh()->role);
        $this->assertSame($hash, $u->fresh()->password);
        $this->assertSame('PROF-001', $u->fresh()->erp_teacher_id);
        $this->assertNull($u->fresh()->erp_student_id);
        $this->assertSame('student', $other->fresh()->role);
        $this->assertSame('active', $GLOBALS['cec_teacher_profiles'][0]['campusStatus']);
        $this->assertNotEmpty($GLOBALS['cec_teacher_approvals']['PROF-001']['usedAt']);
        TeacherDirectory::sync();
        $this->assertDatabaseCount('users', 2);
        $this->actingAs($u->fresh())->get('/docente/usuarios')->assertForbidden();
        $this->get('/mi-horario')->assertOk();
    }

    public function test_approval_cannot_be_retargeted_to_another_email_or_an_admin(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $admin = User::factory()->create(['role' => 'admin']);
        $GLOBALS['cec_teacher_profiles'] = [['id' => 'PROF-001', 'name' => 'Teacher', 'email' => $student->email, 'active' => true, 'createCampus' => true]];
        $GLOBALS['cec_teacher_approvals']['PROF-001'] = ['account_id' => $admin->id, 'email' => $student->email, 'expiresAt' => time() + 600, 'nonce' => 'wrong-account'];
        TeacherDirectory::sync();
        $this->assertSame('student', $student->fresh()->role);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertSame('review', $GLOBALS['cec_teacher_profiles'][0]['campusStatus']);
        $GLOBALS['cec_teacher_approvals']['PROF-001']['account_id'] = $student->id;
        $GLOBALS['cec_teacher_approvals']['PROF-001']['expiresAt'] = time() - 1;
        TeacherDirectory::sync();
        $this->assertSame('student', $student->fresh()->role);
    }
}

