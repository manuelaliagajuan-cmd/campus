<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Course;
use Illuminate\Http\Request;

class TrashController extends Controller
{
    private function staff(Request $r): void
    {
        abort_unless($r->user()->isStaff(), 403);
    }

    private function owner(Request $r, Course $course): void
    {
        abort_unless($r->user()->role === 'admin' || ($r->user()->role === 'teacher' && $course->teacher_id === $r->user()->id), 403);
    }

    public function index(Request $r)
    {
        $this->staff($r);
        $courses = Course::onlyTrashed()->when($r->user()->role !== 'admin', fn ($q) => $q->where('teacher_id', $r->user()->id))->latest('deleted_at')->paginate(20, ['*'], 'cursos');
        $activities = Activity::onlyTrashed()->whereHas('course', function ($q) use ($r) {
            $q->whereNull('courses.deleted_at');
            if ($r->user()->role !== 'admin') {
                $q->where('teacher_id', $r->user()->id);
            }
        })->with('course')->latest('deleted_at')->paginate(20, ['*'], 'actividades');

        return view('staff.trash', compact('courses', 'activities'));
    }

    public function course(Request $r, int $courseId)
    {
        $this->staff($r);
        $course = Course::onlyTrashed()->findOrFail($courseId);
        $this->owner($r, $course);
        $course->restore();

        return redirect()->route('staff.edit', $course)->with('status', __('Curso restaurado.'));
    }

    public function activity(Request $r, int $activityId)
    {
        $this->staff($r);
        $activity = Activity::onlyTrashed()->findOrFail($activityId);
        $this->owner($r, $activity->course);
        abort_if($activity->course->trashed(), 409, __('Restaura primero el curso de esta actividad.'));
        $activity->restore();

        return redirect()->route('activities.edit', $activity)->with('status', __('Actividad restaurada.'));
    }
}
