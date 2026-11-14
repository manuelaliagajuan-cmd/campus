<?php

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\Completion;
use App\Models\Course;
use App\Models\User;
use App\Services\Certificates;
use App\Services\ErpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrashTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::create(['teacher_id' => $teacher->id, 'title' => 'Curso propio de prueba', 'published' => true, 'language' => 'Inglés', 'level' => 'B1']);
        $course->students()->attach($student);
        $lesson = $course->lessons()->create(['title' => 'Lección conservada', 'unit' => 'Unidad 1']);
        $activity = $course->activities()->create(['title' => 'Actividad para eliminar', 'mode' => 'practice', 'questions' => [['type' => 'multiple', 'prompt' => 'Pregunta', 'options' => ['Sí', 'No'], 'correct' => 0]], 'published' => true]);
        $activity->lessons()->attach($lesson);

        return [$teacher, $student, $course, $lesson, $activity];
    }

    public function test_course_delete_hides_access_and_preserves_materials_progress_and_certificates_for_restore(): void
    {
        [$t,$s,$c,$l,$a] = $this->fixtures();
        Storage::fake('local');
        Storage::disk('local')->put('private.pdf', 'contenido');
        $file = $c->resources()->create(['title' => 'Archivo conservado', 'path' => 'private.pdf', 'filename' => 'private.pdf', 'mime' => 'application/pdf', 'size' => 9]);
        Completion::create(['lesson_id' => $l->id, 'user_id' => $s->id]);
        $certificate = Certificates::issueIfEligible($s, $c);
        $this->assertNotNull($certificate);
        $this->actingAs($t)->delete('/docente/cursos/'.$c->id)->assertRedirect('/campus');
        $this->assertSoftDeleted('courses', ['id' => $c->id]);
        $this->assertDatabaseHas('lessons', ['id' => $l->id]);
        $this->assertDatabaseHas('activities', ['id' => $a->id]);
        $this->assertDatabaseHas('enrollments', ['course_id' => $c->id, 'user_id' => $s->id]);
        Storage::disk('local')->assertExists('private.pdf');
        $this->actingAs($s)->get('/campus')->assertDontSee('Curso propio de prueba');
        $this->get('/cursos/'.$c->id)->assertNotFound();
        $this->get('/lecciones/'.$l->id)->assertForbidden();
        $this->get('/archivos/'.$file->id)->assertForbidden();
        $this->get('/certificados/'.$certificate->id)->assertOk();
        $this->get('/certificados/'.$certificate->id.'/pdf')->assertOk();
        $this->actingAs($t)->get('/docente/papelera')->assertOk()->assertSee('Curso propio de prueba');
        $this->post('/docente/papelera/cursos/'.$c->id.'/restaurar')->assertRedirect('/docente/cursos/'.$c->id);
        $this->assertNotSoftDeleted('courses', ['id' => $c->id]);
        $this->actingAs($s)->get('/lecciones/'.$l->id)->assertOk()->assertSee('Lección completada');
    }

    public function test_activity_delete_removes_it_from_bank_lessons_and_new_attempts_but_keeps_submitted_results(): void
    {
        [$t,$s,$c,$l,$a] = $this->fixtures();
        $this->actingAs($s)->post('/actividades/'.$a->id.'/empezar');
        $attempt = Attempt::first();
        $this->post('/intentos/'.$attempt->id, ['answers' => [0]]);
        $this->post('/actividades/'.$a->id.'/empezar');
        $open = Attempt::whereNull('submitted_at')->first();
        $this->actingAs($t)->delete('/docente/actividades/'.$a->id)->assertRedirect('/docente/actividades');
        $this->assertSoftDeleted('activities', ['id' => $a->id]);
        $this->get('/docente/actividades')->assertDontSee('Actividad para eliminar');
        $this->actingAs($s)->get('/lecciones/'.$l->id)->assertOk()->assertDontSee('Actividad para eliminar');
        $this->get('/actividades/'.$a->id)->assertNotFound();
        $this->post('/actividades/'.$a->id.'/empezar')->assertNotFound();
        $this->post('/intentos/'.$open->id, ['answers' => [0]])->assertNotFound();
        $this->get('/intentos/'.$attempt->id)->assertOk()->assertSee('Pregunta');
        $this->get('/resultados')->assertOk()->assertSee('Actividad para eliminar');
        $this->assertDatabaseCount('attempts', 2);
        $this->actingAs($t)->post('/docente/papelera/actividades/'.$a->id.'/restaurar')->assertRedirect('/docente/actividades/'.$a->id);
        $this->assertNotSoftDeleted('activities', ['id' => $a->id]);
        $this->actingAs($s)->get('/lecciones/'.$l->id)->assertSee('Actividad para eliminar');
    }

    public function test_students_and_other_teachers_cannot_delete_or_restore_someone_elses_work(): void
    {
        [$t,$s,$c,$l,$a] = $this->fixtures();
        $other = User::factory()->create(['role' => 'teacher']);
        foreach ([$s, $other] as $user) {
            $this->actingAs($user)->delete('/docente/cursos/'.$c->id)->assertForbidden();
            $this->delete('/docente/actividades/'.$a->id)->assertForbidden();
        }
        $this->actingAs($t)->delete('/docente/actividades/'.$a->id);
        $this->delete('/docente/cursos/'.$c->id);
        $this->actingAs($other)->get('/docente/papelera')->assertDontSee('Curso propio de prueba')->assertDontSee('Actividad para eliminar');
        $this->post('/docente/papelera/cursos/'.$c->id.'/restaurar')->assertForbidden();
        $this->post('/docente/papelera/actividades/'.$a->id.'/restaurar')->assertForbidden();
        $this->actingAs($s)->get('/docente/papelera')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/docente/papelera/cursos/'.$c->id.'/restaurar')->assertRedirect();
        $this->post('/docente/papelera/actividades/'.$a->id.'/restaurar')->assertRedirect();
        $this->delete('/docente/cursos/'.$c->id)->assertRedirect();
    }

    public function test_erp_sync_does_not_recreate_a_deleted_course_and_restore_uses_current_source_state(): void
    {
        $dir = sys_get_temp_dir().'/cec-trash-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        config(['erp.projection_path' => $dir.'/campus.php']);
        try {
            $admin = User::factory()->create(['role' => 'admin']);
            $student = User::factory()->create(['role' => 'student']);
            $data = ['schema' => 1, 'revision' => 1, 'updated_at' => now()->toIso8601String(), 'courses' => [['id' => 'CUR-1', 'name' => 'Curso de Gestión', 'active' => true]], 'students' => [['id' => 'A-1', 'email' => $student->email, 'active' => true, 'courses' => ['CUR-1']]], 'passes' => []];
            $write = fn () => file_put_contents($dir.'/campus.php', "<?php exit; ?>\n".json_encode($data));
            $write();
            ErpConnection::sync($student);
            $course = Course::where('erp_id', 'CUR-1')->first();
            $this->actingAs($admin)->delete('/docente/cursos/'.$course->id)->assertRedirect();
            $data['revision'] = 2;
            $data['courses'][0]['name'] = 'Nombre actualizado';
            $data['courses'][0]['active'] = false;
            file_put_contents($dir.'/campus.php', "<?php exit; ?>\n".json_encode($data));
            ErpConnection::sync($student);
            $this->assertSame(1, Course::withTrashed()->where('erp_id', 'CUR-1')->count());
            $this->assertNull(Course::where('erp_id', 'CUR-1')->first());
            $this->actingAs($admin)->post('/docente/papelera/cursos/'.$course->id.'/restaurar')->assertRedirect();
            $course = Course::findOrFail($course->id);
            $this->assertSame('Nombre actualizado', $course->title);
            $this->assertFalse($course->published);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_deleting_course_hides_its_notices_and_direct_notice_delete_fails_without_server_error(): void
    {
        [$t,$s,$c] = $this->fixtures();
        $notice = $c->announcements()->create(['author_id' => $t->id, 'title' => 'Aviso privado', 'body' => 'Contenido', 'kind' => 'notice', 'publish_at' => now()]);
        $this->actingAs($t)->delete('/docente/cursos/'.$c->id);
        $this->get('/notificaciones')->assertOk()->assertDontSee('Aviso privado');
        $this->delete('/docente/avisos/'.$notice->id)->assertForbidden();
        $this->actingAs($s)->get('/notificaciones/contador')->assertOk()->assertJson(['count' => 0]);
    }

    public function test_delete_controls_and_trash_are_visible_for_staff_only(): void
    {
        [$t,$s,$c,$l,$a] = $this->fixtures();
        $this->actingAs($t)->get('/docente/cursos/'.$c->id)->assertOk()->assertSee('Eliminar curso');
        $this->get('/docente/actividades')->assertOk()->assertSee('Eliminar actividad')->assertSee('Papelera');
        $this->get('/docente/actividades/'.$a->id)->assertOk()->assertSee('Eliminar actividad');
        $this->actingAs($s)->get('/campus')->assertDontSee('Papelera')->assertDontSee('Eliminar curso');
    }
}
