<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Certificates
{
    public static function issueIfEligible(User $user, Course $course): ?Certificate
    {
        $existing = Certificate::where('user_id', $user->id)->where('course_id', $course->id)->first();
        if ($existing) {
            return $existing;
        }
        if (! $course->published || ! $course->certificate_enabled || ! $course->students()->where('users.id', $user->id)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($user, $course) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! CourseProgress::for($user, $course)['complete']) {
                return null;
            }
            $ids = $course->activities()->where('published', true)->where('mode', 'test')->pluck('id');
            $scores = Attempt::where('user_id', $user->id)->whereIn('activity_id', $ids)->whereNotNull('submitted_at')->selectRaw('activity_id, MAX(score) as best')->groupBy('activity_id')->pluck('best');

            return Certificate::firstOrCreate(['user_id' => $user->id, 'course_id' => $course->id], ['learner_name' => $user->name, 'course_title' => $course->title, 'language' => $course->language, 'level' => $course->level, 'teacher_name' => $course->teacher->name, 'duration_hours' => $course->duration_hours, 'score' => $scores->isNotEmpty() ? round($scores->avg(), 2) : null, 'issued_at' => now()]);
        });
    }
}
