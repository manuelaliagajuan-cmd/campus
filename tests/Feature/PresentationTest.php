<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PresentationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Course $course;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = User::factory()->create(['role' => 'teacher']);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->course = Course::create(['teacher_id' => $this->teacher->id, 'title' => 'Presentaciones de prueba', 'published' => true]);
        $this->course->students()->attach($this->student);
        $this->activity = $this->course->activities()->create(['title' => 'Actividad imbricada', 'mode' => 'practice', 'published' => true, 'questions' => [['type' => 'multiple', 'prompt' => 'Choose', 'options' => ['Yes', 'No'], 'correct' => 0]]]);
    }

    private function payload(array $slides): array
    {
        return ['title' => 'Lección con diapositivas', 'unit' => 'Unidad 1', 'position' => 1, 'format' => 'presentation', 'presentation' => json_encode($slides)];
    }

    public function test_teacher_creates_mixed_slides_and_student_sees_real_embedded_activity(): void
    {
        $image = UploadedFile::fake()->createWithContent('slide.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR3sAAAAASUVORK5CYII='));
        $upload = $this->actingAs($this->teacher)->post('/docente/cursos/'.$this->course->id.'/imagenes', ['file' => $image], ['Accept' => 'application/json'])->assertOk();
        $id = $upload->json('id');
        $slides = [['title' => 'Primera', 'blocks' => [['type' => 'text', 'body' => 'Texto escrito por el docente'], ['type' => 'image', 'resource_id' => $id]]], ['title' => 'Vídeo y práctica', 'blocks' => [['type' => 'video', 'url' => 'https://youtu.be/dQw4w9WgXcQ'], ['type' => 'activity', 'activity_id' => $this->activity->id]]]];
        $this->post('/docente/cursos/'.$this->course->id.'/lecciones', $this->payload($slides))->assertRedirect();
        $lesson = Lesson::first();
        $this->assertSame('presentation', $lesson->format);
        $this->assertCount(2, $lesson->presentation);
        $this->assertTrue($lesson->activities->contains($this->activity));
        $this->actingAs($this->student)->get('/lecciones/'.$lesson->id)->assertOk()->assertSee('presentation-viewer', false)->assertSee('Texto escrito por el docente')->assertSee('slide-activity-frame', false)->assertSee('embed=1', false)->assertSee('youtube-nocookie.com');
        $this->get('/archivos/'.$id.'?preview=1')->assertOk()->assertHeader('content-type', 'image/png');
    }

    public function test_slides_cannot_embed_foreign_activities_private_images_or_invalid_video(): void
    {
        $foreign = Course::create(['teacher_id' => User::factory()->create(['role' => 'teacher'])->id, 'title' => 'Curso ajeno']);
        $other = $foreign->activities()->create(['title' => 'Privada', 'questions' => [], 'published' => true]);
        $this->actingAs($this->teacher)->post('/docente/cursos/'.$this->course->id.'/lecciones', $this->payload([['title' => 'No', 'blocks' => [['type' => 'activity', 'activity_id' => $other->id]]]]))->assertSessionHasErrors('presentation');
        $this->post('/docente/cursos/'.$this->course->id.'/lecciones', $this->payload([['title' => 'No', 'blocks' => [['type' => 'image', 'resource_id' => 999]]]]))->assertSessionHasErrors('presentation');
        $this->post('/docente/cursos/'.$this->course->id.'/lecciones', $this->payload([['title' => 'No', 'blocks' => [['type' => 'video', 'url' => 'javascript:alert(1)']]]]))->assertSessionHasErrors('presentation');
        $this->assertDatabaseCount('lessons', 0);
        $this->actingAs($this->student)->post('/docente/cursos/'.$this->course->id.'/imagenes', ['file' => UploadedFile::fake()->create('not.png', 1)])->assertForbidden();
    }

    public function test_embedded_attempts_keep_context_and_real_grading_without_relaxing_permissions(): void
    {
        $lesson = $this->course->lessons()->create(['title' => 'Contexto', 'unit' => 'Unidad 1']);
        $this->actingAs($this->student)->get('/actividades/'.$this->activity->id.'?embed=1&leccion='.$lesson->id)->assertOk()->assertSee('embedded-activity', false);
        $this->assertDatabaseCount('attempts', 0);
        $this->post('/actividades/'.$this->activity->id.'/empezar?embed=1&leccion='.$lesson->id)->assertRedirect();
        $attempt = Attempt::first();
        $this->get('/intentos/'.$attempt->id.'?embed=1')->assertSee('embedded-activity', false);
        $response = $this->post('/intentos/'.$attempt->id.'?embed=1&leccion='.$lesson->id, ['answers' => [0]]);
        $this->assertStringContainsString('embed=1', $response->headers->get('Location'));
        $this->assertNotNull($attempt->fresh()->submitted_at);
        $this->actingAs(User::factory()->create())->get('/intentos/'.$attempt->id.'?embed=1')->assertForbidden();
        $this->get('/archivos/999?preview=1')->assertNotFound();
    }

    public function test_brand_and_favicon_use_light_and_dark_assets(): void
    {
        $this->get('/acceso')->assertOk()->assertSee('favicon-campus-cec-claro.svg')->assertSee('favicon-campus-cec-oscuro.svg')->assertSee('logo-campus-cec-claro.svg')->assertSee('logo-campus-cec-oscuro.svg')->assertSee('prefers-color-scheme: dark',false);
    }
}
