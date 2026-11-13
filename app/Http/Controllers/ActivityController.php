<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Course;
use App\Models\User;
use App\Services\Grader;
use App\Services\Questions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    private function courses(Request $r)
    {
        abort_unless($r->user()->isStaff(), 403);

        return Course::when($r->user()->role !== 'admin', fn ($q) => $q->where('teacher_id', $r->user()->id))->with('lessons')->get();
    }

    public function catalog(Request $r)
    {
        $courses = $this->courses($r);
        $activities = Activity::whereIn('course_id', $courses->pluck('id'))->with('course')->latest()->paginate(20);

        return view('staff.catalog', compact('courses', 'activities'));
    }

    public function create(Request $r)
    {
        $courses = $this->courses($r);

        return view('staff.activity', ['activity' => new Activity, 'courses' => $courses, 'initialType' => in_array($r->query('type'), ['multiple', 'truefalse', 'cloze', 'matching', 'ordering'], true) ? $r->query('type') : 'multiple']);
    }

    public function edit(Request $r, Activity $activity)
    {
        abort_unless($activity->course->managedBy($r->user()), 403);

        return view('staff.activity', ['activity' => $activity, 'courses' => $this->courses($r), 'initialType' => 'multiple']);
    }

    public function save(Request $r, ?Activity $activity = null)
    {
        abort_unless($r->user()->isStaff(), 403);
        if ($activity) {
            abort_unless($activity->course->managedBy($r->user()), 403);
        }
        $d = $r->validate(['title' => 'required|string|max:180', 'course_id' => 'required|integer|exists:courses,id', 'mode' => 'required|in:practice,test', 'max_attempts' => 'required|integer|min:0|max:20', 'pass_percent' => 'required|integer|min:0|max:100', 'questions_json' => 'required|string|max:150000', 'lessons' => 'nullable|array|max:100', 'lessons.*' => 'integer']);
        $course = Course::findOrFail($d['course_id']);
        abort_unless($course->managedBy($r->user()), 403);
        if ($activity) {
            abort_unless($activity->course_id === $course->id, 422);
        }
        $questions = Questions::validate($d['questions_json']);
        $lessonIds = $d['lessons'] ?? [];
        abort_if($course->lessons()->whereIn('id', $lessonIds)->count() !== count(array_unique($lessonIds)), 422);
        unset($d['questions_json'],$d['lessons']);
        $d['questions'] = $questions;
        $d['published'] = $r->boolean('published');
        DB::transaction(function () use (&$activity, $d, $lessonIds) {
            if ($activity) {
                $activity->update($d);
            } else {
                $activity = Activity::create($d);
            }$activity->lessons()->sync($lessonIds);
        });

        return redirect()->route('activities.edit', $activity)->with('status', 'Actividad guardada. Puedes probarla y reutilizarla en las lecciones seleccionadas.');
    }

    public function start(Request $r, Activity $activity)
    {
        abort_unless($activity->course->accessibleBy($r->user()) && ($activity->published || $activity->course->managedBy($r->user())), 403);
        $attempt = DB::transaction(function () use ($r, $activity) {
            User::whereKey($r->user()->id)->lockForUpdate()->first();
            $q = $activity->attempts()->where('user_id', $r->user()->id);
            $open = (clone $q)->whereNull('submitted_at')->first();
            if ($open) {
                return $open;
            }abort_if(! $activity->course->managedBy($r->user()) && $activity->max_attempts > 0 && (clone $q)->count() >= $activity->max_attempts, 403, 'Has alcanzado el límite de intentos.');

            return $activity->attempts()->create(['user_id' => $r->user()->id, 'questions' => $activity->questions, 'pass_percent' => $activity->pass_percent]);
        });

        return redirect()->route('attempts.show', $attempt);
    }

    private function own(Request $r, Attempt $attempt): void
    {
        abort_unless($attempt->user_id === $r->user()->id && $attempt->activity->course->accessibleBy($r->user()), 403);
    }

    public function show(Request $r, Attempt $attempt)
    {
        $this->own($r, $attempt);
        if ($attempt->submitted_at) {
            return view('result', compact('attempt'));
        }$questions = array_map(fn ($q) => Grader::publicQuestion($q, $attempt->id), $attempt->questions);

        return view('attempt', compact('attempt', 'questions'));
    }

    public function submit(Request $r, Attempt $attempt)
    {
        $this->own($r, $attempt);
        $r->validate(['answers' => 'nullable|array|max:50']);
        $answers = $r->input('answers', []);
        abort_if(strlen(json_encode($answers)) > 100000, 422);
        DB::transaction(function () use ($answers, $attempt) {
            $a = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($a->submitted_at) {
                return;
            }$result = Grader::grade($a->questions, $answers);
            $a->update(['answers' => $answers, 'score' => $result['score'], 'feedback' => $result['feedback'], 'submitted_at' => now()]);
        });

        return redirect()->route('attempts.show',$attempt);
    }
}
