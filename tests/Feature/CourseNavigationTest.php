<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Completion;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $teacher = User::factory()->create();
        $teacher->role = 'teacher';
        $teacher->save();
        $student = User::factory()->create();
        $course = Course::create(['teacher_id' => $teacher->id, 'title' => 'Curso autorizado', 'published' => true]);
        $course->students()->attach($student);
        $video = $course->lessons()->create(['title' => 'Presentaciones en vídeo', 'unit' => 'Unidad 1 · Primeros pasos', 'position' => 1, 'youtube_id' => 'dQw4w9WgXcQ']);
        $reading = $course->lessons()->create(['title' => 'Lectura de conversación', 'unit' => 'Unidad 2 · Conversación', 'position' => 2, 'content' => 'Lee y practica.']);
        $activity = $course->activities()->create(['title' => 'Checkpoint de conversación', 'mode' => 'test', 'published' => true, 'questions' => [['type' => 'multiple', 'prompt' => 'Choose', 'options' => ['A', 'B'], 'correct' => 1]], 'pass_percent' => 70]);
        $activity->lessons()->attach([$video->id, $reading->id]);

        return [$teacher, $student, $course, $video, $reading, $activity];
    }

    public function test_outline_shows_units_types_real_completion_and_current_lesson(): void
    {
        [$t,$s,$c,$v,$l,$a] = $this->fixtures();
        Completion::create(['user_id' => $s->id, 'lesson_id' => $v->id]);
        $response = $this->actingAs($s)->get('/lecciones/'.$l->id);
        $response->assertOk()->assertSee('course-sidebar', false)->assertSee('Unidad 1 · Primeros pasos')->assertSee('Unidad 2 · Conversación')->assertSee('data-outline-kind="video"', false)->assertSee('data-outline-kind="reading"', false)->assertSee('data-outline-kind="test"', false)->assertSee('data-outline-completed="true"', false);
        $this->assertStringContainsString('href="'.route('lessons.show', $l).'"  aria-current="page"', $response->getContent());
        $response->assertSee('Materiales del curso');
    }

    public function test_reused_activity_keeps_selected_unit_through_start_and_submission(): void
    {
        [$t,$s,$c,$v,$l,$a] = $this->fixtures();
        $this->actingAs($s)->get('/actividades/'.$a->id.'?leccion='.$l->id)->assertOk()->assertSee('data-active-unit="true"', false);
        $this->assertDatabaseCount('attempts', 0);
        $this->post('/actividades/'.$a->id.'/empezar?leccion='.$l->id)->assertRedirect(route('attempts.show', ['attempt' => 1, 'leccion' => $l->id]));
        $attempt = Attempt::first();
        $this->get('/intentos/'.$attempt->id.'?leccion='.$l->id)->assertOk()->assertSee('Unidad 2 · Conversación');
        $this->post('/intentos/'.$attempt->id.'?leccion='.$l->id, ['answers' => [1]])->assertRedirect(route('attempts.show', ['attempt' => $attempt, 'leccion' => $l->id]));
        $this->get('/intentos/'.$attempt->id.'?leccion='.$l->id)->assertOk()->assertSee('data-outline-completed="true"', false);
    }

    public function test_failed_test_is_not_marked_as_completed_and_other_students_attempt_is_not_visible(): void
    {
        [$t,$s,$c,$v,$l,$a] = $this->fixtures();
        $this->actingAs($s)->post('/actividades/'.$a->id.'/empezar');
        $attempt = Attempt::first();
        $this->post('/intentos/'.$attempt->id, ['answers' => [0]]);
        $response = $this->get('/actividades/'.$a->id);
        $response->assertOk();
        $this->assertStringNotContainsString('outline-item is-current is-done', $response->getContent());
        $other = User::factory()->create();
        $c->students()->attach($other);
        $this->actingAs($other)->get('/intentos/'.$attempt->id)->assertForbidden()->assertDontSee('Presentaciones en vídeo');
    }

    public function test_course_outline_never_leaks_courses_without_access(): void
    {
        [$t,$s,$c,$v,$l,$a] = $this->fixtures();
        $this->actingAs(User::factory()->create())->get('/lecciones/'.$l->id)->assertForbidden()->assertDontSee('Curso autorizado')->assertDontSee('Presentaciones en vídeo');
    }

    public function test_profile_accesses_show_only_authorized_courses_and_honest_erp_connection_state(): void
    {
        [$t,$s,$c,$v,$l,$a] = $this->fixtures();
        $private = Course::create(['teacher_id' => $t->id, 'title' => 'Curso de otro alumno', 'published' => true]);
        $this->actingAs($s)->get('/mis-accesos')->assertOk()->assertSee('Curso autorizado')->assertDontSee('Curso de otro alumno')->assertSee('Bonos de sesiones')->assertSee('El saldo de tus bonos todavía no está disponible');
        $this->get('/campus')->assertOk()->assertSee('profile-menu', false)->assertSee('Mis cursos y bonos');
        $c->students()->detach($s);
        $this->get('/mis-accesos')->assertOk()->assertDontSee('Curso autorizado');
    }

    public function test_contextual_resources_and_english_navigation_preserve_course_content(): void
    {
        [$t,$s,$c,$v,$l,$a] = $this->fixtures();
        $this->actingAs($s)->post('/idioma', ['locale' => 'en']);
        $this->get('/biblioteca?course='.$c->id)->assertOk()->assertSee('course-sidebar', false)->assertSee('Course resources')->assertSee('Presentaciones en vídeo')->assertSee('Reading');
    }
}
