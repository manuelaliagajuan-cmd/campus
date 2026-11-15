<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersonalProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function data(array $extra = []): array
    {
        return array_replace(['display_name' => 'Alex', 'bio' => 'Me gusta aprender.', 'appearance' => 'dark', 'accent' => 'teal', 'density' => 'compact', 'text_size' => 'large'], $extra);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aM1sAAAAASUVORK5CYII='));
    }

    public function test_own_profile_preferences_never_change_role_identity_or_another_account(): void
    {
        $u = User::factory()->create(['name' => 'Nombre oficial', 'role' => 'student']);
        $other = User::factory()->create();
        $this->get('/perfil')->assertRedirect('/acceso');
        $this->actingAs($u)->patch('/perfil', $this->data(['role' => 'admin', 'name' => 'Wrong identity', 'email' => 'other@example.test', 'user_id' => $other->id]))->assertRedirect('/perfil');
        $u->refresh();
        $this->assertSame('student', $u->role);
        $this->assertSame('Nombre oficial', $u->name);
        $this->assertSame('Alex', $u->display_name);
        $this->assertSame('dark', $u->appearance);
        $this->assertSame('teal', $u->accent);
        $this->assertNull($other->fresh()->display_name);
        $this->actingAs($u)->get('/perfil')->assertOk()->assertSee('data-mode="dark"', false)->assertSee('data-accent="teal"', false)->assertSee('Tu espacio personal');
    }

    public function test_avatar_is_private_raster_image_and_replacement_and_removal_clean_old_files(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->patch('/perfil', $this->data(['avatar' => $this->photo()]))->assertRedirect();
        $u->refresh();
        $old = $u->avatar_path;
        Storage::disk('local')->assertExists($old);
        $this->get(route('profile.avatar', $u))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->create())->get(route('profile.avatar', $u))->assertNotFound();
        $this->actingAs($u)->patch('/perfil', $this->data(['avatar' => $this->photo()]))->assertRedirect();
        $u->refresh();
        Storage::disk('local')->assertMissing($old);
        $current = $u->avatar_path;
        $this->patch('/perfil', $this->data(['remove_avatar' => 1]))->assertRedirect();
        $this->assertNull($u->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($current);
    }

    public function test_students_can_see_their_course_teacher_photo_but_not_unrelated_teachers(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create();
        $this->actingAs($teacher)->patch('/perfil', $this->data(['avatar' => $this->photo()]));
        $teacher->refresh();
        $this->actingAs($student)->get(route('profile.avatar', $teacher))->assertNotFound();
        $course = Course::create(['teacher_id' => $teacher->id, 'title' => 'Course', 'published' => true]);
        $course->students()->attach($student->id, ['source_manual' => true]);
        $this->get(route('profile.avatar', $teacher))->assertOk();
    }

    public function test_svg_and_unsupported_preferences_are_rejected(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->patchJson('/perfil', $this->data(['appearance' => 'evil', 'accent' => '<script>']))->assertUnprocessable()->assertJsonValidationErrors(['appearance', 'accent']);
        $this->patch('/perfil', $this->data(['avatar' => UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]))->assertSessionHasErrors('avatar');
        $this->assertNull($u->fresh()->avatar_path);
    }

    public function test_welcome_uses_profile_name_and_each_role_has_appropriate_message(): void
    {
        foreach (['student' => 'Continúa tus cursos, consulta tus bonos y prepara tu próxima clase.', 'teacher' => 'Prepara tus clases, consulta tu horario y acompaña a tus alumnos.', 'admin' => 'Organiza tus cursos, tu equipo y los accesos del campus.'] as $role => $text) {
            $u = User::factory()->create(['role' => $role, 'display_name' => 'Sam García Pérez']);
            $this->actingAs($u)->get('/campus')->assertOk()->assertSee('¡Hola, Sam!')->assertSee($text);
        }
    }

    public function test_student_preview_is_admin_only_and_never_changes_the_account_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/campus/vista-alumno')->assertOk()->assertSee('Vista de estudiante')->assertSee('Volver a administración')->assertSee('Continúa tus cursos')->assertDontSee('Crear curso →');
        $this->assertSame('admin', $admin->fresh()->role);
        $this->get('/campus')->assertOk()->assertSee('Organiza tus cursos, tu equipo');
        foreach (['teacher', 'student'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/campus/vista-alumno')->assertForbidden();
        }
    }
}
