<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Services\CourseProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $teacher = User::factory()->create();
        $teacher->role = 'teacher';
        $teacher->save();
        $student = User::factory()->create();
        $course = Course::create(['teacher_id' => $teacher->id, 'title' => 'Curso en español', 'language' => 'Inglés', 'level' => 'B1', 'published' => true]);
        $lesson = $course->lessons()->create(['title' => 'Lección en español', 'position' => 1, 'content' => 'Este contenido no debe traducirse.']);
        $course->students()->attach($student);

        return [$teacher, $student, $course, $lesson];
    }

    private function activity(Course $course): Activity
    {
        return $course->activities()->create(['title' => 'Test en español', 'mode' => 'test', 'questions' => [['type' => 'multiple', 'prompt' => 'Pregunta de prueba', 'options' => ['Incorrecta', 'Correcta'], 'correct' => 1]], 'published' => true, 'pass_percent' => 70, 'max_attempts' => 2]);
    }

    public function test_opening_the_test_intro_does_not_consume_an_attempt(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $a = $this->activity($c);
        $this->actingAs($s)->get('/actividades/'.$a->id)->assertOk()->assertSee('Empezar actividad')->assertSee('Qué vas a encontrar')->assertDontSee('Pregunta de prueba');
        $this->assertDatabaseCount('attempts', 0);
        $this->post('/actividades/'.$a->id.'/empezar')->assertRedirect();
        $this->get('/actividades/'.$a->id)->assertOk()->assertSee('Continuar actividad');
        $this->assertDatabaseCount('attempts', 1);
    }

    public function test_progress_includes_lessons_and_required_tests_and_issues_one_certificate(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $a = $this->activity($c);
        $this->actingAs($s)->post('/lecciones/'.$l->id.'/completar')->assertRedirect();
        $this->assertSame(50, CourseProgress::for($s, $c->fresh())['percent']);
        $this->assertDatabaseCount('certificates', 0);
        $this->post('/actividades/'.$a->id.'/empezar');
        $attempt = Attempt::first();
        $this->post('/intentos/'.$attempt->id, ['answers' => [1]])->assertRedirect();
        $this->assertSame(100, CourseProgress::for($s, $c->fresh())['percent']);
        $this->assertDatabaseCount('certificates', 1);
        $this->post('/cursos/'.$c->id.'/certificado')->assertRedirect();
        $this->assertDatabaseCount('certificates', 1);
        $certificate = Certificate::first();
        $this->assertSame($s->name, $certificate->learner_name);
        $this->assertEquals(100, $certificate->score);
        $this->get('/campus?estado=completados')->assertOk()->assertSee('Ver certificado');
    }

    public function test_failed_test_or_missing_lessons_cannot_issue_certificate(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $a = $this->activity($c);
        $this->actingAs($s)->post('/cursos/'.$c->id.'/certificado')->assertRedirect();
        $this->post('/lecciones/'.$l->id.'/completar');
        $this->post('/actividades/'.$a->id.'/empezar');
        $this->post('/intentos/'.Attempt::first()->id, ['answers' => [0]])->assertRedirect();
        $this->assertDatabaseCount('certificates', 0);
        $this->assertFalse(CourseProgress::for($s, $c->fresh())['complete']);
    }

    public function test_certificate_pdf_is_real_private_and_keeps_its_snapshot(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $this->actingAs($s)->post('/lecciones/'.$l->id.'/completar');
        $cert = Certificate::firstOrFail();
        $this->get('/certificados/'.$cert->id)->assertOk()->assertSee($s->name);
        $pdf = $this->get('/certificados/'.$cert->id.'/pdf');
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $c->update(['title' => 'Nombre nuevo']);
        $this->assertSame('Curso en español', $cert->fresh()->course_title);
        $c->students()->detach($s);
        $this->get('/certificados/'.$cert->id.'/pdf')->assertOk();
        $this->actingAs(User::factory()->create())->get('/certificados/'.$cert->id.'/pdf')->assertForbidden();
    }

    public function test_teacher_may_disable_certificates_and_empty_courses_do_not_complete(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $c->update(['certificate_enabled' => false]);
        $this->actingAs($s)->post('/lecciones/'.$l->id.'/completar');
        $this->assertDatabaseCount('certificates', 0);
        $empty = Course::create(['teacher_id' => $t->id, 'title' => 'Vacío', 'published' => true]);
        $this->assertFalse(CourseProgress::for($s, $empty)['complete']);
    }

    public function test_notices_are_scoped_scheduled_and_read_per_student(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $a = $c->announcements()->create(['author_id' => $t->id, 'title' => 'Aviso visible', 'body' => 'Trae tu libro.', 'publish_at' => now()->subMinute()]);
        $future = $c->announcements()->create(['author_id' => $t->id, 'title' => 'Aviso futuro', 'body' => 'Mañana.', 'publish_at' => now()->addDay()]);
        $other = User::factory()->create();
        $this->actingAs($other)->get('/notificaciones')->assertOk()->assertDontSee('Aviso visible');
        $this->post('/notificaciones/'.$a->id.'/leer')->assertForbidden();
        $this->actingAs($s)->get('/notificaciones/contador')->assertJson(['count' => 1]);
        $this->get('/notificaciones')->assertOk()->assertSee('Aviso visible')->assertDontSee('Aviso futuro');
        $this->post('/notificaciones/'.$a->id.'/leer')->assertRedirect();
        $this->get('/notificaciones/contador')->assertJson(['count' => 0]);
        $this->travel(2)->days();
        $this->get('/notificaciones/contador')->assertJson(['count' => 1]);
        $c->students()->detach($s);
        $this->get('/notificaciones/contador')->assertJson(['count' => 0]);
    }

    public function test_only_authorized_teacher_can_publish_course_notices(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $payload = ['title' => 'Recordatorio', 'body' => 'Practica la unidad 1.', 'kind' => 'reminder'];
        $this->actingAs($s)->post('/docente/cursos/'.$c->id.'/avisos', $payload)->assertForbidden();
        $this->actingAs($t)->post('/docente/cursos/'.$c->id.'/avisos', $payload)->assertRedirect();
        $this->assertDatabaseHas('announcements', ['title' => 'Recordatorio', 'kind' => 'reminder']);
        $this->get('/notificaciones/contador')->assertJson(['count' => 0]);
        $stranger = User::factory()->create();
        $stranger->role = 'teacher';
        $stranger->save();
        $this->actingAs($stranger)->post('/docente/cursos/'.$c->id.'/avisos', $payload)->assertForbidden();
    }

    public function test_interface_language_persists_without_translating_lesson_content(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $this->actingAs($s)->post('/idioma', ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', $s->fresh()->locale);
        $this->get('/campus')->assertOk()->assertSee('WELCOME TO YOUR CAMPUS.')->assertSee('My certificates');
        $this->get('/lecciones/'.$l->id)->assertOk()->assertSee('Este contenido no debe traducirse.')->assertSee('Mark as complete');
        $this->post('/idioma', ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->post('/idioma', ['locale' => 'es'])->assertRedirect();
        $this->get('/campus')->assertOk()->assertSee('BIENVENIDO A TU CAMPUS.');
    }

    public function test_result_uses_correct_error_and_solution_visual_states(): void
    {
        [$t,$s,$c,$l] = $this->fixtures();
        $a = $this->activity($c);
        $a->update(['questions' => [['type' => 'multiple', 'prompt' => 'Correct question', 'options' => ['No', 'Yes'], 'correct' => 1], ['type' => 'multiple', 'prompt' => 'Wrong question', 'options' => ['No', 'Yes'], 'correct' => 1]]]);
        $this->actingAs($s)->post('/actividades/'.$a->id.'/empezar');
        $attempt = Attempt::first();
        $this->post('/intentos/'.$attempt->id, ['answers' => [1, 0]]);
        $this->get('/intentos/'.$attempt->id)->assertOk()->assertSee('feedback-card is-correct', false)->assertSee('feedback-card is-error',false)->assertSee('feedback-solution',false)->assertSee('Tu respuesta')->assertSee('Solución correcta');
    }
}
