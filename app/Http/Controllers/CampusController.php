<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Attempt;
use App\Models\Completion;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Resource;
use App\Services\Certificates;
use App\Services\CourseProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CampusController extends Controller
{
    public function dashboard(Request $r)
    {
        $u = $r->user();
        $courses = $u->isStaff() ? Course::when($u->role !== 'admin', fn ($q) => $q->where('teacher_id', $u->id)) : $u->courses()->where('published', true);
        $courses = $courses->withCount('lessons')->with('teacher')->orderByDesc('courses.created_at')->get();
        $completed = Completion::where('user_id', $u->id)->whereIn('lesson_id', Lesson::whereIn('course_id', $courses->pluck('id'))->select('id'))->pluck('lesson_id');

        $progress = CourseProgress::many($u, $courses);
        $state = in_array($r->query('estado'), ['en-curso', 'completados', 'todos'], true) ? $r->query('estado') : ($u->isStaff() ? 'todos' : 'en-curso');
        $visibleCourses = $courses->filter(fn ($c) => $state === 'todos' || ($state === 'completados' ? $progress[$c->id]['complete'] : ! $progress[$c->id]['complete']));

        return view('dashboard', compact('courses', 'completed', 'progress', 'state', 'visibleCourses'));
    }

    public function course(Request $r, Course $course)
    {
        abort_unless($course->accessibleBy($r->user()), 403);
        $course->load(['lessons', 'teacher', 'resources', 'activities' => fn ($q) => $q->where('published', true)]);
        $completed = Completion::where('user_id', $r->user()->id)->pluck('lesson_id');

        $progress = CourseProgress::for($r->user(), $course);
        $notices = Announcement::visibleTo($r->user())->where('course_id', $course->id)->latest('publish_at')->limit(3)->get();

        return view('course', compact('course', 'completed', 'progress', 'notices'));
    }

    public function lesson(Request $r, Lesson $lesson)
    {
        abort_unless($lesson->course->accessibleBy($r->user()), 403);
        $lesson->load(['activities' => fn ($q) => $q->where('published', true)]);

        return view('lesson', ['lesson' => $lesson, 'completed' => Completion::where('user_id', $r->user()->id)->where('lesson_id', $lesson->id)->exists()]);
    }

    public function complete(Request $r, Lesson $lesson)
    {
        abort_unless($lesson->course->accessibleBy($r->user()), 403);
        Completion::firstOrCreate(['user_id' => $r->user()->id, 'lesson_id' => $lesson->id]);

        $certificate = Certificates::issueIfEligible($r->user(), $lesson->course);

        return back()->with('status', $certificate ? __('Curso completado. Tu certificado ya está disponible.') : __('Lección marcada como completada.'));
    }

    public function library(Request $r)
    {
        $u = $r->user();
        $courses = $u->role === 'admin' ? Course::query() : ($u->role === 'teacher' ? Course::where('teacher_id', $u->id) : $u->courses()->where('published', true));
        $courses = $courses->get();
        $resources = Resource::whereIn('course_id', $courses->pluck('id'))->when($r->integer('course'), fn ($q) => $q->where('course_id', $r->integer('course')))->with('course')->latest()->paginate(20)->withQueryString();

        return view('library', compact('resources', 'courses'));
    }

    public function file(Request $r, Resource $resource)
    {
        abort_unless($resource->course->accessibleBy($r->user()), 403);
        abort_unless(Storage::disk('local')->exists($resource->path), 404);
        $inline = ! $r->boolean('download') && in_array($resource->mime, ['application/pdf', 'image/jpeg', 'image/png', 'audio/mpeg', 'video/mp4']);

        return Storage::disk('local')->response($resource->path, $resource->filename, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'X-Frame-Options' => 'SAMEORIGIN'], $inline ? 'inline' : 'attachment');
    }

    public function results(Request $r)
    {
        $u = $r->user();
        $query = Attempt::whereNotNull('submitted_at')->with(['activity.course', 'user']);
        if (! $u->isStaff()) {
            $query->where('user_id', $u->id)->whereHas('activity.course', fn ($q) => $q->where('published', true)->whereHas('students', fn ($s) => $s->where('users.id', $u->id)));
        } elseif ($u->role === 'teacher') {
            $query->whereHas('activity.course', fn ($q) => $q->where('teacher_id', $u->id));
        }

        return view('results', ['attempts' => $query->latest()->paginate(30)]);
    }
}
