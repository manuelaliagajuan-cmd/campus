<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseCoverTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return ['title' => 'Curso con cabecera propia', 'language' => 'Inglés', 'level' => 'B1', 'published' => 1, 'cover_color' => '#123456', 'cover_text_color' => '#ffffff', 'cover_opacity' => 65, 'cover_text' => 'CEC English', 'cover_label' => 'Aprende a tu ritmo', 'cover_font' => 'serif', 'cover_text_size' => 48, 'cover_align' => 'center'];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR3sAAAAASUVORK5CYII='));
    }

    public function test_teacher_can_create_custom_cover_and_enrolled_student_can_view_it(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($teacher)->post('/docente/cursos', $this->data() + ['cover_image' => $this->image()])->assertRedirect();
        $course = Course::first();
        $this->assertSame('#123456', $course->cover_color);
        $this->assertSame('serif', $course->cover_font);
        $this->assertSame(65, $course->cover_opacity);
        Storage::disk('local')->assertExists($course->cover_path);
        $student = User::factory()->create(['role' => 'student']);
        $course->students()->attach($student);
        $this->actingAs($student)->get('/campus')->assertOk()->assertSee('CEC English')->assertSee('Aprende a tu ritmo')->assertSee('--cover-bg:#123456', false)->assertSee('--cover-opacity:0.65', false);
        $this->get('/cursos/'.$course->id.'/cabecera')->assertOk()->assertHeader('content-type', 'image/png');
        $this->actingAs(User::factory()->create())->get('/cursos/'.$course->id.'/cabecera')->assertForbidden();
        $this->assertArrayNotHasKey('cover_path', $course->toArray());
    }

    public function test_replacing_and_removing_cover_cleans_only_the_owned_image(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($teacher)->post('/docente/cursos', $this->data() + ['cover_image' => $this->image()]);
        $course = Course::first();
        $old = $course->cover_path;
        Storage::disk('local')->put('other-file.txt', 'keep');
        $this->put('/docente/cursos/'.$course->id, $this->data() + ['cover_image' => $this->image()])->assertRedirect();
        $new = $course->fresh()->cover_path;
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($new);
        $this->put('/docente/cursos/'.$course->id, $this->data() + ['remove_cover_image' => 1])->assertRedirect();
        $this->assertNull($course->fresh()->cover_path);
        Storage::disk('local')->assertMissing($new);
        Storage::disk('local')->assertExists('other-file.txt');
    }

    public function test_other_teachers_and_students_cannot_modify_cover_and_invalid_styles_are_rejected(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'teacher']);
        $course = Course::create(['teacher_id' => $owner->id, 'title' => 'Curso privado']);
        $other = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($other)->put('/docente/cursos/'.$course->id, $this->data())->assertForbidden();
        $this->actingAs(User::factory()->create())->put('/docente/cursos/'.$course->id, $this->data())->assertForbidden();
        $bad = array_replace($this->data(), ['cover_color' => 'red;position:fixed', 'cover_opacity' => 101, 'cover_font' => 'url(https://invalid.test)', 'cover_align' => 'absolute', 'cover_text_size' => 500]);
        $this->actingAs($owner)->put('/docente/cursos/'.$course->id, $bad)->assertSessionHasErrors(['cover_color', 'cover_opacity', 'cover_font', 'cover_align', 'cover_text_size']);
        $this->assertSame('#eff0f4', $course->fresh()->cover_color);
        $this->put('/docente/cursos/'.$course->id, $this->data() + ['cover_image' => UploadedFile::fake()->create('script.svg', 1, 'image/svg+xml')])->assertSessionHasErrors('cover_image');
    }

    public function test_erp_course_can_customize_cover_without_changing_erp_name_and_old_requests_keep_existing_design(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $course = Course::create(['teacher_id' => $admin->id, 'title' => 'Nombre desde ERP', 'erp_id' => 'CUR-TEST', 'published' => true, 'language' => 'Inglés', 'level' => 'B1']);
        $this->actingAs($admin)->put('/docente/cursos/'.$course->id, $this->data())->assertRedirect();
        $course->refresh();
        $this->assertSame('Nombre desde ERP', $course->title);
        $this->assertSame('CEC English', $course->cover_text);
        $this->assertTrue($course->published);
        $this->put('/docente/cursos/'.$course->id, ['title' => 'Otro nombre', 'language' => 'Inglés', 'level' => 'B1'])->assertRedirect();
        $this->assertSame('#123456', $course->fresh()->cover_color);
    }

    public function test_deleted_course_cover_is_unavailable_until_restored_without_losing_file(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->actingAs($teacher)->post('/docente/cursos', $this->data() + ['cover_image' => $this->image()]);
        $course = Course::first();
        $this->delete('/docente/cursos/'.$course->id)->assertRedirect();
        $this->get('/cursos/'.$course->id.'/cabecera')->assertNotFound();
        Storage::disk('local')->assertExists($course->cover_path);
        $this->post('/docente/papelera/cursos/'.$course->id.'/restaurar')->assertRedirect();
        $this->get('/cursos/'.$course->id.'/cabecera')->assertOk();
    }
}
