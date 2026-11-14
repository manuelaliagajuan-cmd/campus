<?php

namespace Tests\Feature;

use App\Models\PassContent;
use App\Models\User;
use App\Services\CampusAccessRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PassWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private array $data;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cec-pass-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        config(['erp.projection_path' => $this->dir.'/campus.php']);
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        User::factory()->create(['role' => 'admin']);
        $this->student = User::factory()->create(['role' => 'student', 'email' => 'pupil@example.test']);
        $this->data = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [], 'students' => [['id' => 'A-1', 'name' => 'Alumno privado', 'email' => $this->student->email, 'active' => true, 'courses' => []], ['id' => 'A-2', 'name' => 'Otro alumno', 'email' => 'other@example.test', 'active' => true, 'courses' => []]], 'passes' => [['id' => 'BON-1', 'student_id' => 'A-1', 'name' => 'Bono de prueba', 'sessions' => 12, 'used' => 0, 'remaining' => 12, 'start' => '', 'expires' => '', 'teacher_id' => $this->teacher->id], ['id' => 'BON-2', 'student_id' => 'A-2', 'name' => 'Bono de otra persona', 'sessions' => 8, 'used' => 0, 'remaining' => 8, 'start' => '', 'expires' => '', 'teacher_id' => null]]];
        $this->write();
        Storage::fake('local');
    }

    private function write(): void
    {
        file_put_contents($this->dir.'/campus.php', "<?php exit; ?>\n".json_encode($this->data));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_assigned_teacher_uploads_publishes_and_only_linked_student_can_download(): void
    {
        $this->actingAs($this->teacher)->get('/docente/bonos')->assertOk()->assertSee('Alumno privado')->assertDontSee('Bono de otra persona');
        $this->post('/bonos/BON-1', ['title' => 'Material individual', 'body' => 'Texto privado', 'published' => 1, 'file' => UploadedFile::fake()->create('practica.pdf', 12, 'application/pdf')])->assertRedirect();
        $content = PassContent::first();
        Storage::disk('local')->assertExists($content->path);
        $this->actingAs($this->student)->get('/bonos/BON-1')->assertOk()->assertSee('Texto privado');
        $this->get('/bonos/materiales/'.$content->id.'/archivo')->assertOk();
        $this->post('/bonos/BON-1', ['title' => 'No permitido', 'body' => 'Intento'])->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/bonos/BON-1')->assertForbidden();
        $this->get('/bonos/materiales/'.$content->id.'/archivo')->assertForbidden();
    }

    public function test_draft_and_teacher_reassignment_are_enforced_on_all_content_actions(): void
    {
        $this->actingAs($this->teacher)->post('/bonos/BON-1', ['title' => 'Borrador privado', 'body' => 'Contenido no publicado']);
        $content = PassContent::first();
        $this->actingAs($this->student)->get('/bonos/BON-1')->assertOk()->assertDontSee('Borrador privado');
        $this->get('/bonos/materiales/'.$content->id.'/archivo')->assertForbidden();
        $other = User::factory()->create(['role' => 'teacher']);
        $this->data['revision']++;
        $this->data['passes'][0]['teacher_id'] = $other->id;
        $this->write();
        $this->actingAs($this->teacher)->get('/bonos/BON-1')->assertForbidden();
        $this->put('/bonos/materiales/'.$content->id, ['published' => 1])->assertForbidden();
        $this->delete('/bonos/materiales/'.$content->id)->assertForbidden();
        $this->actingAs($other)->put('/bonos/materiales/'.$content->id, ['published' => 1])->assertRedirect();
        $this->actingAs($this->student)->get('/bonos/BON-1')->assertSee('Borrador privado');
    }

    public function test_unassigned_teacher_and_inactive_student_do_not_gain_access(): void
    {
        $other = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($other)->get('/bonos/BON-1')->assertForbidden();
        $this->post('/bonos/BON-1', ['title' => 'Intrusión', 'body' => 'No'])->assertForbidden();
        $this->data['revision']++;
        $this->data['students'][0]['active'] = false;
        $this->write();
        $this->actingAs($this->teacher)->get('/bonos/BON-1')->assertForbidden();
        $this->actingAs($this->student)->get('/bonos/BON-1')->assertForbidden();
    }

    public function test_private_registry_publishes_only_teacher_identity_fields(): void
    {
        CampusAccessRegistry::publishAll();
        $registry = json_decode(substr(file_get_contents($this->dir.'/campus-accounts.php'), 15), true);
        $teachers = $registry['teachers'];
        $this->assertCount(2, $teachers);
        $this->assertSame(['id', 'name', 'email', 'role'], array_keys($teachers[0]));
        $this->assertStringNotContainsString('password', json_encode($registry));
    }

    public function test_transferring_a_pass_cannot_expose_the_previous_students_materials(): void
    {
        $this->actingAs($this->teacher)->post('/bonos/BON-1', ['title' => 'Contenido del alumno anterior', 'body' => 'Privado', 'published' => 1]);
        $content = PassContent::first();
        $other = User::factory()->create(['email' => 'other@example.test', 'role' => 'student']);
        $this->data['revision']++;
        $this->data['passes'][0]['student_id'] = 'A-2';
        $this->write();
        $this->actingAs($other)->get('/bonos/BON-1')->assertOk()->assertDontSee('Contenido del alumno anterior');
        $this->get('/bonos/materiales/'.$content->id.'/archivo')->assertForbidden();
    }
}
