<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Course;
use App\Models\Resource;
use App\Models\User;
use App\Services\Grader;
use App\Services\YouTube;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampusTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function course(User $teacher, bool $published = true): Course
    {
        return Course::create(['teacher_id' => $teacher->id, 'title' => 'Inglés B1', 'published' => $published]);
    }

    private function questions(): array
    {
        return [['type' => 'multiple', 'prompt' => 'Choose the correct answer', 'options' => ['am', 'is', 'are'], 'correct' => 1, 'explanation' => 'Third person singular.']];
    }

    public function test_registration_cannot_assign_staff_role(): void
    {
        $this->post('/registro', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Password12345', 'password_confirmation' => 'Password12345', 'role' => 'admin'])->assertRedirect('/campus');
        $this->assertDatabaseHas('users', ['email' => 'ana@example.com', 'role' => 'student']);
        $this->assertAuthenticated();
    }

    public function test_student_cannot_create_courses_or_access_catalog(): void
    {
        $u = $this->person();
        $this->actingAs($u)->post('/docente/cursos', ['title' => 'Illegal'])->assertForbidden();
        $this->get('/docente/actividades')->assertForbidden();
    }

    public function test_teacher_can_create_course_and_youtube_lesson(): void
    {
        $u = $this->person('teacher');
        $this->actingAs($u)->post('/docente/cursos', ['title' => 'English', 'language' => 'Inglés', 'level' => 'B1', 'published' => 1])->assertRedirect();
        $c = Course::first();
        $this->post('/docente/cursos/'.$c->id.'/lecciones', ['title' => 'Welcome', 'unit' => 'Unit 1', 'position' => 1, 'content' => 'Hello', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertRedirect();
        $this->assertDatabaseHas('lessons', ['youtube_id' => 'dQw4w9WgXcQ']);
        $this->get('/docente/cursos/'.$c->id)->assertOk();
    }

    public function test_teacher_cannot_edit_another_teachers_course(): void
    {
        $c = $this->course($this->person('teacher'));
        $this->actingAs($this->person('teacher'))->get('/docente/cursos/'.$c->id)->assertForbidden();
    }

    public function test_enrollment_controls_lessons_and_private_downloads(): void
    {
        Storage::fake('local');
        $c = $this->course($this->person('teacher'));
        $l = $c->lessons()->create(['title' => 'Hello']);
        $u = $this->person();
        Storage::disk('local')->put('courses/private.pdf', 'private data');
        $f = $c->resources()->create(['title' => 'PDF', 'path' => 'courses/private.pdf', 'filename' => 'lesson.pdf', 'mime' => 'application/pdf', 'size' => 12]);
        $this->actingAs($u)->get('/lecciones/'.$l->id)->assertForbidden();
        $this->get('/archivos/'.$f->id)->assertForbidden();
        $c->students()->attach($u);
        $this->get('/lecciones/'.$l->id)->assertOk();
        $this->get('/archivos/'.$f->id)->assertOk();
        $this->post('/lecciones/'.$l->id.'/completar')->assertRedirect();
        $this->assertDatabaseHas('completions', ['user_id' => $u->id, 'lesson_id' => $l->id]);
        $c->students()->detach($u);
        $this->get('/archivos/'.$f->id)->assertForbidden();
    }

    public function test_teacher_upload_is_private_and_student_cannot_upload(): void
    {
        Storage::fake('local');
        $u = $this->person('teacher');
        $c = $this->course($u);
        $this->actingAs($u)->post('/docente/cursos/'.$c->id.'/archivos', ['title' => 'Worksheet', 'file' => UploadedFile::fake()->create('worksheet.pdf', 10, 'application/pdf')])->assertRedirect();
        $f = Resource::first();
        $this->assertNotNull($f);
        Storage::disk('local')->assertExists($f->path);
        $this->actingAs($this->person())->post('/docente/cursos/'.$c->id.'/archivos', [])->assertForbidden();
    }

    public function test_student_dashboard_and_library_render_enrolled_courses(): void
    {
        $c = $this->course($this->person('teacher'));
        $u = $this->person();
        $c->students()->attach($u);
        $this->actingAs($u)->get('/campus')->assertOk()->assertSee('Inglés B1');
        $this->get('/biblioteca')->assertOk();
        $this->get('/resultados')->assertOk();
    }

    public function test_builder_saves_activity_and_reuses_it_in_lessons(): void
    {
        $u = $this->person('teacher');
        $c = $this->course($u);
        $l = $c->lessons()->create(['title' => 'One']);
        $l2 = $c->lessons()->create(['title' => 'Two']);
        $this->actingAs($u)->post('/docente/actividades', ['title' => 'Grammar', 'course_id' => $c->id, 'mode' => 'test', 'max_attempts' => 2, 'pass_percent' => 70, 'published' => 1, 'questions_json' => json_encode($this->questions()), 'lessons' => [$l->id, $l2->id]])->assertRedirect();
        $a = Activity::first();
        $this->assertCount(2, $a->lessons);
        $this->get('/docente/actividades/'.$a->id)->assertOk()->assertSee('Guardar actividad');
        $this->get('/docente/actividades')->assertOk();
    }

    public function test_solutions_hidden_before_submission_and_score_persisted_once(): void
    {
        $c = $this->course($this->person('teacher'));
        $u = $this->person();
        $other = $this->person();
        $c->students()->attach([$u->id, $other->id]);
        $a = $c->activities()->create(['title' => 'Test', 'questions' => $this->questions(), 'published' => 1, 'max_attempts' => 1]);
        $this->actingAs($u)->post('/actividades/'.$a->id.'/empezar')->assertRedirect();
        $attempt = Attempt::first();
        $this->get('/intentos/'.$attempt->id)->assertOk()->assertDontSee('Third person singular.');
        $this->actingAs($other)->get('/intentos/'.$attempt->id)->assertForbidden();
        $this->actingAs($u)->post('/intentos/'.$attempt->id, ['answers' => [0 => 1]])->assertRedirect();
        $this->assertEquals(100, $attempt->fresh()->score);
        $this->post('/intentos/'.$attempt->id, ['answers' => [0 => 0]])->assertRedirect();
        $this->assertEquals(100, $attempt->fresh()->score);
        $this->get('/intentos/'.$attempt->id)->assertOk()->assertSee('Third person singular.');
        $this->post('/actividades/'.$a->id.'/empezar')->assertForbidden();
    }

    public function test_started_attempt_keeps_snapshot_when_teacher_changes_activity(): void
    {
        $c = $this->course($this->person('teacher'));
        $u = $this->person();
        $c->students()->attach($u);
        $a = $c->activities()->create(['title' => 'Test', 'questions' => $this->questions(), 'published' => 1]);
        $this->actingAs($u)->post('/actividades/'.$a->id.'/empezar');
        $attempt = Attempt::first();
        $qs = $this->questions();
        $qs[0]['correct'] = 0;
        $a->update(['questions' => $qs]);
        $this->post('/intentos/'.$attempt->id, ['answers' => [1]])->assertRedirect();
        $this->assertEquals(100, $attempt->fresh()->score);
    }

    public function test_all_interactive_types_are_graded_on_server(): void
    {
        $q = [['type' => 'cloze', 'prompt' => 'Gap', 'text' => 'My name [[is]] Ana.'], ['type' => 'matching', 'prompt' => 'Match', 'pairs' => [['hello', 'hola'], ['bye', 'adiós']]], ['type' => 'ordering', 'prompt' => 'Order', 'items' => ['one', 'two', 'three']], ['type' => 'truefalse', 'prompt' => 'True?', 'options' => ['Verdadero', 'Falso'], 'correct' => 0]];
        $a = [[' IS '], ['hola', 'adiós'], ['one', 'two', 'three'], 0];
        $this->assertEquals(100, Grader::grade($q, $a)['score']);
        $this->assertStringNotContainsString('[[is]]', json_encode(Grader::publicQuestion($q[0], 1)));
        $this->assertArrayNotHasKey('pairs', Grader::publicQuestion($q[1], 1));
        $this->assertArrayNotHasKey('items', Grader::publicQuestion($q[2], 1));
    }

    public function test_cloze_and_ordering_views_render_without_solutions(): void
    {
        $c = $this->course($this->person('teacher'));
        $u = $this->person();
        $c->students()->attach($u);
        $a = $c->activities()->create(['title' => 'Mixed', 'published' => 1, 'questions' => [['type' => 'cloze', 'prompt' => 'Fill', 'text' => 'It [[secret-answer]] today.'], ['type' => 'ordering', 'prompt' => 'Order', 'items' => ['First', 'Second']]]]);
        $this->actingAs($u)->post('/actividades/'.$a->id.'/empezar');
        $this->get('/intentos/'.Attempt::first()->id)->assertOk()->assertDontSee('secret-answer')->assertSee('draggable', false);
    }

    public function test_password_reset_notification_uses_laravel_broker(): void
    {
        Notification::fake();
        $u = $this->person();
        $this->post('/recuperar', ['email' => $u->email])->assertRedirect();
        Notification::assertSentTo($u, ResetPassword::class);
    }

    public function test_only_admin_can_grant_teacher_role(): void
    {
        $u = $this->person();
        $this->actingAs($this->person('teacher'))->put('/docente/usuarios/'.$u->id, ['role' => 'teacher'])->assertForbidden();
        $this->actingAs($this->person('admin'))->put('/docente/usuarios/'.$u->id, ['role' => 'teacher'])->assertRedirect();
        $this->assertSame('teacher', $u->fresh()->role);
    }

    public function test_youtube_parser_rejects_foreign_hosts(): void
    {
        $this->assertSame('dQw4w9WgXcQ', YouTube::id('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(YouTube::id('https://evil.test/watch?v=dQw4w9WgXcQ'));
        $this->assertNull(YouTube::id('https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ'));
    }
}
