<?php

namespace App\Http\Controllers;

use App\Models\Completion;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Resource;
use App\Models\User;
use App\Services\YouTube;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    private function staff(Request $r): void
    {
        abort_unless($r->user()->isStaff(), 403);
    }

    private function manage(Request $r, Course $c): void
    {
        abort_unless($c->managedBy($r->user()), 403);
    }

    public function create(Request $r)
    {
        $this->staff($r);

        return view('staff.course', ['course' => new Course]);
    }

    public function store(Request $r)
    {
        $this->staff($r);
        $d = $this->courseData($r);
        $d['teacher_id'] = $r->user()->id;
        $c = Course::create($d);

        return redirect()->route('staff.edit', $c)->with('status', 'Curso creado. Añade sus lecciones.');
    }

    private function courseData(Request $r): array
    {
        $d = $r->validate(['title' => 'required|string|max:180', 'description' => 'nullable|string|max:10000', 'language' => 'required|string|max:60', 'level' => 'required|string|max:20']);
        $d['published'] = $r->boolean('published');

        return $d;
    }

    public function edit(Request $r, Course $course)
    {
        $this->manage($r, $course);
        $course->load(['lessons', 'students', 'resources']);
        $progress = Completion::whereIn('lesson_id', $course->lessons->pluck('id'))->selectRaw('user_id, COUNT(*) as total')->groupBy('user_id')->pluck('total', 'user_id');

        return view('staff.course', compact('course', 'progress'));
    }

    public function update(Request $r, Course $course)
    {
        $this->manage($r, $course);
        $course->update($this->courseData($r));

        return back()->with('status', 'Curso actualizado.');
    }

    public function lessonForm(Request $r, Course $course, ?Lesson $lesson = null)
    {
        $this->manage($r, $course);
        if ($lesson) {
            abort_unless($lesson->course_id === $course->id, 404);
        }

return view('staff.lesson', ['course' => $course, 'lesson' => $lesson ?? new Lesson]);
    }

    public function lessonSave(Request $r, Course $course, ?Lesson $lesson = null)
    {
        $this->manage($r, $course);
        if ($lesson) {
            abort_unless($lesson->course_id === $course->id, 404);
        }$d = $r->validate(['title' => 'required|string|max:180', 'unit' => 'required|string|max:120', 'content' => 'nullable|string|max:50000', 'youtube_url' => 'nullable|url|max:500', 'position' => 'required|integer|min:1|max:10000']);
        $id = YouTube::id($d['youtube_url'] ?? null);
        if (! empty($d['youtube_url']) && ! $id) {
            return back()->withErrors(['youtube_url' => 'Introduce una URL válida de YouTube.'])->withInput();
        }unset($d['youtube_url']);
        $d['youtube_id'] = $id;
        if ($lesson) {
            $lesson->update($d);
        } else {
            $course->lessons()->create($d);
        }

return redirect()->route('staff.edit', $course)->with('status', 'Lección guardada.');
    }

    public function enroll(Request $r, Course $course)
    {
        $this->manage($r, $course);
        $r->validate(['email' => 'required|email']);
        $u = User::where('email', $r->input('email'))->first();
        if (! $u) {
            return back()->withErrors(['email' => 'El estudiante debe registrarse primero en el campus.']);
        }$course->students()->syncWithoutDetaching([$u->id]);

        return back()->with('status', 'Matrícula guardada.');
    }

    public function revoke(Request $r, Course $course, User $user)
    {
        $this->manage($r, $course);
        $course->students()->detach($user->id);

        return back()->with('status', 'Matrícula retirada.');
    }

    public function upload(Request $r, Course $course)
    {
        $this->manage($r, $course);
        $r->validate(['title' => 'required|string|max:180', 'file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,mp3,mp4,docx,xlsx,pptx']);
        $f = $r->file('file');
        $path = $f->store('courses/'.$course->id, 'local');
        try {
            $course->resources()->create(['title' => $r->input('title'), 'path' => $path, 'filename' => basename($f->getClientOriginalName()), 'mime' => $f->getMimeType(), 'size' => $f->getSize()]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

return back()->with('status', 'Material subido.');
    }

    public function deleteFile(Request $r, Resource $resource)
    {
        $this->manage($r, $resource->course);
        Storage::disk('local')->delete($resource->path);
        $resource->delete();

        return back()->with('status', 'Material eliminado.');
    }

    public function users(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);

        return view('staff.users', ['users' => User::orderBy('name')->paginate(30)]);
    }

    public function role(Request $r, User $user)
    {
        abort_unless($r->user()->role === 'admin', 403);
        abort_if($user->role === 'admin', 403);
        $d = $r->validate(['role' => ['required', Rule::in(['student', 'teacher'])]]);
        $user->role = $d['role'];
        $user->save();

        return back()->with('status','Permisos actualizados.');
    }
}
