<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CampusAccessRegistry;
use App\Services\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CampusAccessReviewTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cec-access-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        config(['erp.projection_path' => $this->dir.'/campus.php']);
        User::factory()->create(['role' => 'admin']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function readRegistry(): array
    {
        return json_decode(substr(file_get_contents($this->dir.'/campus-accounts.php'), 15), true);
    }

    private function fixture(User $u, array $review = []): void
    {
        $data = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [['id' => 'CUR-1', 'name' => 'Refuerzo escolar de prueba', 'active' => true]], 'students' => [['id' => 'A-1', 'email' => 'guardian@example.test', 'active' => true, 'courses' => ['CUR-1']]], 'passes' => [['id' => 'B-1', 'student_id' => 'A-1', 'name' => 'Doce clases de prueba', 'sessions' => 12, 'used' => 0, 'remaining' => 12, 'start' => '', 'expires' => '']]];
        file_put_contents($this->dir.'/campus.php', "<?php exit; ?>\n".json_encode($data));
        $approval = array_replace(['review_id' => 'review-example', 'student_id' => 'A-1', 'account_email' => strtolower($u->email), 'checked_by' => 'admin', 'reviewed_at' => now()->toIso8601String()], $review);
        file_put_contents($this->dir.'/campus-links.php', "<?php exit; ?>\n".json_encode(['schema' => 1, 'revision' => 1, 'links' => [(string) $u->id => $approval]]));
    }

    public function test_new_registration_is_visible_to_erp_without_password_or_tokens(): void
    {
        $this->post('/registro', ['name' => 'Alumno de prueba', 'email' => 'new@example.test', 'password' => 'PasswordExample123', 'password_confirmation' => 'PasswordExample123'])->assertRedirect('/campus');
        $data = $this->readRegistry();
        $this->assertCount(1, $data['accounts']);
        $this->assertSame('new@example.test', $data['accounts'][0]['email']);
        $this->assertFalse($data['accounts'][0]['email_verified']);
        $this->assertArrayNotHasKey('password', $data['accounts'][0]);
        $this->assertStringNotContainsString('PasswordExample', file_get_contents($this->dir.'/campus-accounts.php'));
    }

    public function test_backfill_includes_existing_students_and_removes_staff_without_duplicating_accounts(): void
    {
        $student = User::factory()->create();
        User::factory()->create(['role' => 'teacher']);
        $this->artisan('cec:publish-accesses')->assertSuccessful();
        $this->assertCount(1, $this->readRegistry()['accounts']);
        CampusAccessRegistry::publish($student);
        $this->assertCount(1, $this->readRegistry()['accounts']);
        $student->forceFill(['role' => 'teacher'])->save();
        CampusAccessRegistry::publish($student);
        $this->assertCount(0, $this->readRegistry()['accounts']);
    }

    public function test_admin_review_in_erp_links_different_email_confirms_and_grants_only_selected_pupil(): void
    {
        $u = User::factory()->create(['email' => 'pupil@example.test', 'email_verified_at' => null]);
        $this->fixture($u);
        $this->actingAs($u)->get('/mis-accesos')->assertOk()->assertSee('Refuerzo escolar de prueba')->assertSee('Doce clases de prueba')->assertDontSee('Confirma tu correo');
        $u = $u->fresh();
        $this->assertSame('A-1', $u->erp_student_id);
        $this->assertSame('admin', $u->erp_link_checked_by);
        $this->assertNotNull($u->email_verified_at);
        $this->assertSame('review-example', $u->erp_link_review_id);
        $this->assertSame('A-1', $this->readRegistry()['accounts'][0]['student_id']);
    }

    public function test_stale_account_email_or_conflicting_existing_link_never_grants_access(): void
    {
        $u = User::factory()->create(['email' => 'pupil@example.test', 'email_verified_at' => null]);
        $this->fixture($u, ['account_email' => 'old@example.test']);
        $this->actingAs($u)->get('/mis-accesos')->assertSee('Necesitamos revisar')->assertDontSee('Doce clases de prueba');
        $this->assertNull($u->fresh()->email_verified_at);
        $u->forceFill(['erp_student_id' => 'A-other'])->save();
        $this->fixture($u);
        $this->get('/mis-accesos')->assertSee('Necesitamos revisar')->assertDontSee('Doce clases de prueba');
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_review_cannot_take_a_pupil_from_another_account(): void
    {
        User::factory()->create(['erp_student_id' => 'A-1']);
        $u = User::factory()->create(['email_verified_at' => null]);
        $this->fixture($u);
        $this->actingAs($u)->get('/mis-accesos')->assertSee('Necesitamos revisar')->assertDontSee('Doce clases de prueba');
        $this->assertNull($u->fresh()->email_verified_at);
    }

    public function test_diagnostics_identifies_unconfirmed_match_without_disclosing_identity(): void
    {
        $u = User::factory()->create(['email' => 'guardian@example.test', 'email_verified_at' => null]);
        $this->fixture($u);
        $data = ErpConnection::diagnostics();
        $this->assertTrue($data['source_valid']);
        $this->assertSame(1, $data['matching_unverified_students']);
        $this->assertStringNotContainsString($u->email,json_encode($data));
    }
}
