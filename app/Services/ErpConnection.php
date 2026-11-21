<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ErpConnection
{
    public static function snapshot(): ?array
    {
        $path = config('erp.projection_path');
        if (! $path || ! is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path, false, null, 0, 16 * 1024 * 1024 + 1);
        $prefix = "<?php exit; ?>\n";
        if (! is_string($raw) || strlen($raw) > 16 * 1024 * 1024 || ! str_starts_with($raw, $prefix)) {
            throw new RuntimeException('Invalid ERP projection');
        }
        $hash = hash('sha256', $raw);
        $day = now('Europe/Madrid')->toDateString();
        $memoKey = $path.'|'.$hash.'|'.$day;
        $memo = request()->attributes->get('cec.erp.snapshot');
        if (is_array($memo) && ($memo['key'] ?? null) === $memoKey) {
            return $memo['data'];
        }
        // The current source is always read and hashed: cache never grants stale access.
        $cacheKey = 'erp-validated:'.hash('sha256', $path);
        try {
            $cached = Cache::get($cacheKey);
        } catch (\Throwable $e) {
            $cached = null;
        }
        if (is_array($cached) && ($cached['hash'] ?? null) === $hash && is_array($cached['data'] ?? null)) {
            $data = $cached['data'];
        } else {
            $data = json_decode(substr($raw, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
            Validator::make($data, [
                'schema' => 'required|in:1', 'revision' => 'required|integer|min:1', 'updated_at' => 'required|date',
                'courses' => 'present|array', 'students' => 'present|array', 'passes' => 'present|array',
                'courses.*.id' => 'required|string|max:100|distinct', 'courses.*.name' => 'required|string|max:180', 'courses.*.active' => 'required|boolean',
                'students.*.id' => 'required|string|max:100|distinct', 'students.*.email' => 'present|string|max:190', 'students.*.active' => 'required|boolean', 'students.*.withdrawal_date' => 'nullable|date_format:Y-m-d', 'students.*.courses' => 'present|array', 'students.*.courses.*' => 'string|max:100',
                'passes.*.id' => 'required|string|max:100|distinct', 'passes.*.student_id' => 'required|string|max:100', 'passes.*.name' => 'required|string|max:180',
                'passes.*.sessions' => 'required|integer|min:0|max:500', 'passes.*.used' => 'required|integer|min:0', 'passes.*.remaining' => 'required|integer|min:0',
                'passes.*.online_enabled' => 'sometimes|boolean', 'passes.*.bookings' => 'sometimes|array|max:5000', 'passes.*.bookings.*.id' => 'required|string|max:100', 'passes.*.bookings.*.date' => 'required|date_format:Y-m-d', 'passes.*.bookings.*.start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'passes.*.bookings.*.end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'passes.*.bookings.*.completed' => 'required|boolean', 'passes.*.start' => 'nullable|date_format:Y-m-d', 'passes.*.expires' => 'nullable|date_format:Y-m-d',
            ])->validate();
            try {
                Cache::put($cacheKey, ['hash' => $hash, 'data' => $data], now()->addMinutes(2));
            } catch (\Throwable $e) { /* Revalidation remains safe if caching is unavailable. */
            }
        }

        $today = now('Europe/Madrid')->toDateString();
        foreach ($data['students'] as &$student) {
            if (! empty($student['withdrawal_date']) && $student['withdrawal_date'] <= $today) {
                $student['active'] = false;
            }
        }
        unset($student);

        request()->attributes->set('cec.erp.snapshot', ['key' => $memoKey, 'data' => $data]);

        return $data;
    }

    public static function workspaces(User $user): array
    {
        if (! $user->isStaff() && self::sync($user)['state'] !== 'connected') {
            return [];
        }
        try {
            $data = self::snapshot();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
        if (! $data) {
            return [];
        }
        $students = collect($data['students'])->where('active', true)->keyBy('id');
        $candidates = collect($data['passes'])->filter(fn ($p) => $students->has($p['student_id']) && ($user->role === 'admin' || ($user->role === 'teacher' && ($p['teacher_id'] ?? null) === $user->id) || ($user->role === 'student' && $p['student_id'] === $user->erp_student_id)));
        if ($candidates->isEmpty()) {
            return [];
        }
        $teachers = User::whereIn('id', $candidates->pluck('teacher_id')->filter()->unique())->whereIn('role', ['teacher', 'admin'])->get()->keyBy('id');
        $result = [];
        foreach ($candidates as $pass) {
            $student = $students->get($pass['student_id']);
            if (! $student) {
                continue;
            }
            $teacher = $teachers->get($pass['teacher_id'] ?? 0);
            $manage = $user->role === 'admin' || ($user->role === 'teacher' && $teacher?->id === $user->id);
            $own = $user->role === 'student' && $user->erp_student_id === $pass['student_id'];
            if (! $manage && ! $own) {
                continue;
            }
            $result[] = $pass + ['student_name' => $student['name'] ?? $student['id'], 'teacher_name' => $teacher?->name, 'manage' => $manage];
        }

        return $result;
    }

    public static function diagnostics(): array
    {
        $path = config('erp.projection_path');
        $result = ['configured' => (bool) $path, 'source_exists' => $path && is_file($path), 'source_readable' => $path && is_readable($path), 'source_valid' => false, 'active_records' => 0, 'matching_unverified_students' => 0, 'matching_verified_students' => 0, 'matching_staff_accounts' => 0];
        try {
            $data = self::snapshot();
        } catch (\Throwable $e) {
            $result['source_error_type'] = class_basename($e);
            $result['source_error_fields'] = $e instanceof ValidationException ? array_keys($e->errors()) : [];

            return $result;
        }
        if (! $data) {
            return $result;
        }
        $result['source_valid'] = true;
        $records = collect($data['students'])->where('active', true);
        $result['active_records'] = $records->count();
        foreach (User::all() as $user) {
            if ($records->filter(fn ($s) => strtolower(trim($s['email'])) === strtolower(trim($user->email)))->count() === 1) {
                $key = $user->role !== 'student' ? 'matching_staff_accounts' : ($user->hasVerifiedEmail() ? 'matching_verified_students' : 'matching_unverified_students');
                $result[$key]++;
            }
        }

        return $result;
    }

    public static function sync(User $user): array
    {
        $path = config('erp.projection_path');
        $lock = null;
        if ($path && is_file($path)) {
            $lock = fopen(dirname($path).'/academic.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_SH)) {
                self::reconcile($user, []);

                return ['state' => 'unavailable', 'passes' => [], 'updated_at' => null];
            }
        }
        try {
            return self::synchronize($user);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function synchronize(User $user): array
    {
        $status = ['state' => 'unavailable', 'passes' => [], 'updated_at' => null];
        // A missing or corrupt source must never leave an old ERP permission active.
        try {
            $data = self::snapshot();
        } catch (\Throwable $e) {
            report($e);
            $data = null;
        }
        if (! $data) {
            self::reconcile($user, []);

            return $status;
        }
        self::courses($data);
        $status['updated_at'] = $data['updated_at'];
        try {
            $review = CampusAccessRegistry::review($user);
        } catch (\Throwable $e) {
            report($e);
            self::reconcile($user, []);

            return array_replace($status, ['state' => 'review']);
        }
        $student = null;
        if ($review) {
            // The ERP administrator has explicitly checked this account and this pupil.
            if ($review['account_email'] !== strtolower(trim($user->email)) || ($user->erp_student_id && $user->erp_student_id !== $review['student_id'])) {
                self::reconcile($user, []);

                return array_replace($status, ['state' => 'review']);
            }
            $student = collect($data['students'])->firstWhere('id', $review['student_id']);
            if (! $student || ! $student['active']) {
                self::reconcile($user, []);

                return array_replace($status, ['state' => 'unassigned']);
            }
        } else {
            if (! $user->hasVerifiedEmail()) {
                self::reconcile($user, []);

                return array_replace($status, ['state' => 'verify']);
            }
            $email = strtolower(trim($user->email));
            $matches = collect($data['students'])->filter(fn ($s) => strtolower(trim($s['email'])) === $email)->values();
            $accounts = User::whereRaw('LOWER(TRIM(email)) = ?', [$email])->count();
            if ($matches->count() > 1 || $accounts > 1) {
                self::reconcile($user, []);

                return array_replace($status, ['state' => 'ambiguous']);
            }
            $student = $matches->first();
            if (! $student || ! $student['active']) {
                self::reconcile($user, []);

                return array_replace($status, ['state' => 'unassigned']);
            }
            if ($user->erp_student_id && $user->erp_student_id !== $student['id']) {
                self::reconcile($user, []);

                return array_replace($status, ['state' => 'review']);
            }
        }
        $owner = User::where('erp_student_id', $student['id'])->whereKeyNot($user->id)->exists();
        if ($owner) {
            self::reconcile($user, []);

            return array_replace($status, ['state' => 'review']);
        }
        if ($review && $user->erp_link_review_id !== $review['review_id']) {
            $user->forceFill(['erp_student_id' => $student['id'], 'email_verified_at' => $user->email_verified_at ?? now(), 'erp_link_review_id' => $review['review_id'], 'erp_link_checked_by' => $review['checked_by'], 'erp_link_reviewed_at' => $review['reviewed_at']])->save();
        } elseif (! $user->erp_student_id) {
            $user->forceFill(['erp_student_id' => $student['id']])->save();
        }
        $digital = collect($data['courses'])->filter(fn ($c) => ! empty($c['campus_course_id']) && $c['active'] && in_array($c['id'], $student['courses'], true))->pluck('campus_course_id')->all();
        $ids = Course::where('published', true)->where(fn ($q) => $q->whereIn('erp_id', $student['courses'])->orWhereIn('id', $digital))->pluck('id')->all();
        self::reconcile($user, $ids);
        $date = now('Europe/Madrid')->toDateString();
        $passes = collect($data['passes'])->where('student_id', $student['id'])->map(function ($p) use ($date) {
            $p['remaining'] = max(0, $p['sessions'] - $p['used']);
            $p['status'] = $p['remaining'] === 0 ? 'Agotado' : (($p['start'] ?? '') > $date ? 'Pendiente de inicio' : (! empty($p['expires']) && $p['expires'] < $date ? 'Caducado' : 'Activo'));

            return $p;
        })->values()->all();

        return array_replace($status, ['state' => 'connected', 'passes' => $passes]);
    }

    private static function courses(array $data): void
    {
        if ((int) DB::table('erp_sync_states')->where('source', 'gestion')->value('revision') === (int) $data['revision']) {
            return;
        }
        DB::transaction(function () use ($data) {
            DB::table('erp_sync_states')->insertOrIgnore(['source' => 'gestion', 'revision' => 0]);
            $state = DB::table('erp_sync_states')->where('source', 'gestion')->lockForUpdate()->first();
            if ((int) $state->revision === (int) $data['revision']) {
                return;
            }
            if ((int) $state->revision > (int) $data['revision']) {
                throw new RuntimeException('ERP revision rolled back; review backup before reconnecting');
            }
            $admin = User::where('role', 'admin')->orderBy('id')->first();
            if (! $admin) {
                throw new RuntimeException('An administrator is required to own ERP courses');
            }
            $ids = [];
            foreach ($data['courses'] as $source) {
                if (! empty($source['campus_course_id'])) {
                    continue;
                }
                $course = Course::withTrashed()->firstOrNew(['erp_id' => $source['id']]);
                if (! $course->exists) {
                    $course->forceFill(['teacher_id' => $admin->id, 'language' => '—', 'level' => '—', 'certificate_enabled' => false]);
                }
                $course->forceFill(['title' => $source['name'], 'published' => $source['active']])->save();
                $ids[] = $source['id'];
            }
            Course::withTrashed()->whereNotNull('erp_id')->whereNotIn('erp_id', $ids)->update(['published' => false]);
            DB::table('erp_sync_states')->where('source', 'gestion')->update(['revision' => $data['revision'], 'synced_at' => now()]);
        });
    }

    private static function reconcile(User $user, array $ids): void
    {
        $expected = array_values(array_unique(array_map('intval', $ids)));
        $actual = DB::table('enrollments')->where('user_id', $user->id)->where('source_erp', true)->pluck('course_id')->map(fn ($id) => (int) $id)->all();
        sort($expected);
        sort($actual);
        if ($actual === $expected) {
            return;
        }
        DB::transaction(function () use ($user, $ids) {
            // Serialize membership changes for this account; retain independent manual enrolments.
            User::whereKey($user->id)->lockForUpdate()->first();
            $q = DB::table('enrollments')->where('user_id', $user->id)->where('source_erp', true)->whereNotIn('course_id', $ids);
            (clone $q)->where('source_manual', false)->delete();
            (clone $q)->where('source_manual', true)->update(['source_erp' => false, 'updated_at' => now()]);
            foreach ($ids as $id) {
                DB::table('enrollments')->insertOrIgnore(['user_id' => $user->id, 'course_id' => $id, 'source_erp' => true, 'source_manual' => false, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('enrollments')->where('user_id', $user->id)->where('course_id', $id)->update(['source_erp' => true, 'updated_at' => now()]);
            }
        });
    }
}
