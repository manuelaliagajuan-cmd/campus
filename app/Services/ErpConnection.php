<?php

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ErpConnection
{
    private static function snapshot(): ?array
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
        $data = json_decode(substr($raw, strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        Validator::make($data, [
            'schema' => 'required|in:1', 'revision' => 'required|integer|min:1', 'updated_at' => 'required|date',
            'courses' => 'present|array', 'students' => 'present|array', 'passes' => 'present|array',
            'courses.*.id' => 'required|string|max:100|distinct', 'courses.*.name' => 'required|string|max:180', 'courses.*.active' => 'required|boolean',
            'students.*.id' => 'required|string|max:100|distinct', 'students.*.email' => 'present|string|max:190', 'students.*.active' => 'required|boolean', 'students.*.withdrawal_date' => 'nullable|date_format:Y-m-d', 'students.*.courses' => 'present|array', 'students.*.courses.*' => 'string|max:100',
            'passes.*.id' => 'required|string|max:100|distinct', 'passes.*.student_id' => 'required|string|max:100', 'passes.*.name' => 'required|string|max:180',
            'passes.*.sessions' => 'required|integer|min:0|max:500', 'passes.*.used' => 'required|integer|min:0', 'passes.*.remaining' => 'required|integer|min:0',
            'passes.*.start' => 'nullable|date_format:Y-m-d', 'passes.*.expires' => 'nullable|date_format:Y-m-d',
        ])->validate();

        $today = now('Europe/Madrid')->toDateString();
        foreach ($data['students'] as &$student) {
            if (! empty($student['withdrawal_date']) && $student['withdrawal_date'] <= $today) {
                $student['active'] = false;
            }
        }
        unset($student);

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
        $teachers = User::whereIn('role', ['teacher', 'admin'])->get()->keyBy('id');
        $result = [];
        foreach ($data['passes'] as $pass) {
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
        $ids = Course::whereIn('erp_id', $student['courses'])->where('published', true)->pluck('id')->all();
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
                $course = Course::firstOrNew(['erp_id' => $source['id']]);
                if (! $course->exists) {
                    $course->forceFill(['teacher_id' => $admin->id, 'language' => '—', 'level' => '—', 'certificate_enabled' => false]);
                }
                $course->forceFill(['title' => $source['name'], 'published' => $source['active']])->save();
                $ids[] = $source['id'];
            }
            Course::whereNotNull('erp_id')->whereNotIn('erp_id', $ids)->update(['published' => false]);
            DB::table('erp_sync_states')->where('source', 'gestion')->update(['revision' => $data['revision'], 'synced_at' => now()]);
        });
    }

    private static function reconcile(User $user, array $ids): void
    {
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
