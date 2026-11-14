<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Certificate;
use App\Models\Completion;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class CourseProgress
{
    public static function many(User $user, Collection $courses): array
    {
        $courses->loadMissing(['lessons', 'activities' => fn ($q) => $q->where('published', true)->where('mode', 'test')]);
        $lessonIds = $courses->flatMap(fn ($course) => $course->lessons->pluck('id'));
        $testIds = $courses->flatMap(fn ($course) => $course->activities->where('published', true)->where('mode', 'test')->pluck('id'));
        $done = Completion::where('user_id', $user->id)->whereIn('lesson_id', $lessonIds)->pluck('lesson_id');
        $passed = Attempt::where('user_id', $user->id)->whereIn('activity_id', $testIds)->whereNotNull('submitted_at')->whereColumn('score', '>=', 'pass_percent')->distinct()->pluck('activity_id');
        $certificates = Certificate::where('user_id', $user->id)->whereIn('course_id', $courses->pluck('id'))->get()->keyBy('course_id');
        $result = [];
        foreach ($courses as $course) {
            $tests = $course->activities->where('published', true)->where('mode', 'test');
            $lessonsDone = $course->lessons->whereIn('id', $done)->count();
            $testsPassed = $tests->whereIn('id', $passed)->count();
            $total = $course->lessons->count() + $tests->count();
            $complete = $total > 0 && $lessonsDone === $course->lessons->count() && $testsPassed === $tests->count();
            $result[$course->id] = ['lessons_done' => $lessonsDone, 'lessons_total' => $course->lessons->count(), 'tests_passed' => $testsPassed, 'tests_total' => $tests->count(), 'percent' => $total ? (int) floor(100 * ($lessonsDone + $testsPassed) / $total) : 0, 'complete' => $complete, 'next_lesson' => $course->lessons->whereNotIn('id', $done)->first(), 'next_test' => $tests->whereNotIn('id', $passed)->first(), 'certificate' => $certificates->get($course->id)];
        }

return $result;
    }

    public static function for(User $user, Course $course): array
    {
        return self::many($user, new Collection([$course]))[$course->id];
    }
}
