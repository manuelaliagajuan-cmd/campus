<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Completion;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\Request;

class CourseNavigation
{
    public static function fromRequest(Request $request, User $user): ?array
    {
        if (! $request->routeIs('courses.show', 'lessons.show', 'activities.overview', 'attempts.show', 'library')) {
            return null;
        }
        $lesson = $request->route('lesson');
        $activity = $request->route('activity');
        $attempt = $request->route('attempt');
        $course = $request->route('course');
        if ($attempt instanceof Attempt) {
            if ($attempt->user_id !== $user->id) {
                return null;
            }
            $activity = $attempt->activity;
        }
        if ($lesson instanceof Lesson) {
            $course = $lesson->course;
        } elseif ($activity instanceof Activity) {
            $course = $activity->course;
        } elseif ($request->routeIs('library') && $request->integer('course')) {
            $course = Course::find($request->integer('course'));
        }
        if (! $course instanceof Course || ! $course->accessibleBy($user)) {
            return null;
        }

        return self::build($user, $course, $lesson instanceof Lesson ? $lesson : null, $activity instanceof Activity ? $activity : null, $request->integer('leccion'));
    }

    public static function build(User $user, Course $course, ?Lesson $currentLesson = null, ?Activity $currentActivity = null, int $requestedLesson = 0): array
    {
        $course->load(['lessons.activities' => fn ($q) => $q->where('activities.course_id', $course->id)->where('published', true)->orderBy('activities.id'), 'activities' => fn ($q) => $q->where('published', true)->orderBy('id')]);
        $lessonIds = $course->lessons->pluck('id');
        $activityIds = $course->activities->pluck('id');
        $done = Completion::where('user_id', $user->id)->whereIn('lesson_id', $lessonIds)->pluck('lesson_id');
        $submitted = Attempt::where('user_id', $user->id)->whereIn('activity_id', $activityIds)->whereNotNull('submitted_at')->distinct()->pluck('activity_id');
        $passed = Attempt::where('user_id', $user->id)->whereIn('activity_id', $activityIds)->whereNotNull('submitted_at')->whereColumn('score', '>=', 'pass_percent')->distinct()->pluck('activity_id');
        if ($currentActivity) {
            $candidates = $course->lessons->filter(fn ($l) => $l->activities->contains('id', $currentActivity->id));
            $currentLesson = $candidates->firstWhere('id', $requestedLesson) ?? $candidates->first();
        }
        $activityItem = function (Activity $a, ?Lesson $parent = null) use ($currentActivity, $currentLesson, $submitted, $passed) {
            $active = $currentActivity?->id === $a->id && ($parent ? $currentLesson?->id === $parent->id : ! $currentLesson);

            return ['id' => $a->id, 'title' => $a->title, 'type' => $a->mode === 'test' ? 'test' : 'practice', 'done' => $a->mode === 'test' ? $passed->contains($a->id) : $submitted->contains($a->id), 'active' => $active, 'url' => route('activities.overview', ['activity' => $a, 'leccion' => $parent?->id])];
        };
        $units = [];
        $attached = collect();
        foreach ($course->lessons->groupBy('unit') as $name => $lessons) {
            $items = [];
            $active = false;
            foreach ($lessons as $lesson) {
                $lessonActive = ! $currentActivity && $currentLesson?->id === $lesson->id;
                $children = [];
                foreach ($lesson->activities as $a) {
                    $children[] = $activityItem($a, $lesson);
                    $attached->push($a->id);
                }
                $inContext = $currentLesson?->id === $lesson->id;
                $active = $active || $inContext;
                $items[] = ['id' => $lesson->id, 'title' => $lesson->title, 'type' => $lesson->youtube_id ? 'video' : 'reading', 'done' => $done->contains($lesson->id), 'active' => $lessonActive, 'context' => $inContext, 'url' => route('lessons.show', $lesson), 'activities' => $children];
            }
            $units[] = ['key' => 'unit-'.count($units), 'title' => $name, 'active' => $active, 'lessons' => $items, 'standalone' => []];
        }
        $other = $course->activities->whereNotIn('id', $attached);
        if ($other->isNotEmpty()) {
            $units[] = ['key' => 'course-activities', 'title' => __('Actividades del curso'), 'active' => $currentActivity && ! $currentLesson, 'lessons' => [], 'standalone' => $other->map(fn ($a) => $activityItem($a))->all()];
        }
        $hasActive = collect($units)->contains('active', true);
        foreach ($units as $index => &$unit) {
            $unit['open'] = $unit['active'] || (! $hasActive && $index === 0);
        }
        unset($unit);

        return ['course' => $course, 'units' => $units, 'progress' => CourseProgress::for($user, $course), 'active_unit' => collect($units)->firstWhere('active', true)['title'] ?? null];
    }
}
