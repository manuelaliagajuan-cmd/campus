<?php

namespace App\Services;

use App\Mail\LessonCancelled;
use App\Models\OnlineSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class OnlineLessonRecords
{
    public static function call(array $input): array
    {
        $core = config('erp.lesson_core');
        if (! $core || ! is_file($core)) {
            throw new \RuntimeException('El registro de clases de Gestión no está disponible.');
        }require_once $core;
        $prior = getenv('CEC_ACADEMIC_STORE_DIR');
        putenv('CEC_ACADEMIC_STORE_DIR='.dirname(config('erp.projection_path')));
        try {
            return \campus_lesson_change($input + ['now' => now()->toIso8601String()]);
        } finally {
            $prior === false ? putenv('CEC_ACADEMIC_STORE_DIR') : putenv('CEC_ACADEMIC_STORE_DIR='.$prior);
        }
    }

    public static function input(OnlineSession $s, array $room, string $action): array
    {
        return ['action' => $action, 'pass_id' => $s->erp_pass_id, 'student_id' => $s->erp_student_id, 'teacher_id' => $room['pass']['teacher_id'] ?? null, 'booking_id' => $s->booking_id ?? '', 'session_id' => $s->id, 'date' => $s->booking_start_at?->copy()->tz('Europe/Madrid')->toDateString(), 'start' => $s->booking_start_at?->copy()->tz('Europe/Madrid')->format('H:i'), 'end' => $s->booking_end_at?->copy()->tz('Europe/Madrid')->format('H:i'), 'teacher_seen_at' => $s->teacher_seen_at?->toIso8601String()];
    }

    public static function observe(OnlineSession $s): void
    {
        if (! $s->erp_pass_id || $s->attendance_settled_at || $s->ended_at) {
            return;
        }
        $teacher = User::find($s->host_id);
        if (! $teacher) {
            return;
        }
        $room = OnlineClassrooms::available($teacher)[$s->scope] ?? null;
        if (! $room || empty($room['pass']['teacher_id'])) {
            return;
        }
        $ids = DB::table('online_participants')->where('online_session_id', $s->id)->whereNull('left_at')->where('seen_at', '>', now()->subSeconds(40))->pluck('user_id');
        $present = $ids->contains($room['pass']['teacher_id']);
        if ($present && $s->booking_start_at && now()->betweenIncluded($s->booking_start_at, $s->booking_end_at)) {
            $s->update(['teacher_seen_at' => now()]);
        }
        $pupil = User::whereIn('id', $ids)->where('role', 'student')->where('erp_student_id', $s->erp_student_id)->exists();
        if ($present && $pupil) {
            self::consume($s, $room, 'coincidence');
        }
    }

    public static function consume(OnlineSession $s, array $room, string $reason): void
    {
        try {
            $record = self::call(self::input($s, $room, $reason));
            $s->update(['attendance_settled_at' => now(), 'consumed_at' => Carbon::parse($record['created_at']), 'consumption_reason' => $record['reason']]);
        } catch (\InvalidArgumentException $e) {
            $s->update(['attendance_settled_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function reconcile(): void
    {
        if (! ErpConnection::snapshot()) {
            return;
        }
        foreach (OnlineSession::whereNotNull('teacher_seen_at')->whereNull('attendance_settled_at')->where('booking_end_at', '<=', now())->limit(100)->get() as $s) {
            $teacher = User::find($s->host_id);
            $pass = $teacher ? collect(ErpConnection::workspaces($teacher))->firstWhere('id', $s->erp_pass_id) : null;
            $room = $pass ? ['pass' => $pass] : null;
            if ($room) {
                self::consume($s, $room, 'no_show');
            } else {
                $s->update(['attendance_settled_at' => now()]);
            }
        }
        self::deliverMail();
    }

    public static function cancel(User $student, array $room, array $entry, string $reason): array
    {
        $result = self::call(['action' => 'cancel', 'pass_id' => $room['pass']['id'], 'student_id' => $student->erp_student_id, 'teacher_id' => $room['pass']['teacher_id'] ?? null, 'booking_id' => $entry['id'], 'session_id' => 0, 'date' => $entry['start']->toDateString(), 'start' => $entry['start']->format('H:i'), 'end' => $entry['end']->format('H:i'), 'reason' => $reason, 'student_email' => $student->email, 'teacher_email' => User::find($room['pass']['teacher_id'] ?? 0)?->email, 'teacher_name' => User::find($room['pass']['teacher_id'] ?? 0)?->name]);
        $teacher = User::find($room['pass']['teacher_id'] ?? 0);
        $payload = $result + ['student_email' => $student->email, 'teacher_email' => $teacher?->email, 'teacher_name' => $teacher?->name];
        DB::table('lesson_cancellation_mail')->insertOrIgnore(['id' => $result['id'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        OnlineSession::where('scope', $room['key'])->where('booking_id', $entry['id'])->whereNull('ended_at')->update(['ended_at' => now(), 'active_key' => null]);
        self::deliverMail();

        return $result;
    }

    public static function deliverMail(): void
    {
        // Recover even if the cancellation request stopped after saving in Gestión.
        $core = config('erp.lesson_core');
        if ($core && is_file($core)) {
            require_once $core;
            $prior = getenv('CEC_ACADEMIC_STORE_DIR');
            putenv('CEC_ACADEMIC_STORE_DIR='.dirname(config('erp.projection_path')));
            try {
                $pending = \campus_pending_lesson_notices();
            } finally {
                $prior === false ? putenv('CEC_ACADEMIC_STORE_DIR') : putenv('CEC_ACADEMIC_STORE_DIR='.$prior);
            }
            foreach ($pending as $notice) {
                DB::table('lesson_cancellation_mail')->insertOrIgnore(['id' => $notice['id'], 'payload' => json_encode($notice, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return;
        }
        foreach (DB::table('lesson_cancellation_mail')->where(fn ($q) => $q->whereNull('student_sent_at')->orWhereNull('teacher_sent_at'))->limit(20)->get() as $row) {
            try {
                foreach (['student', 'teacher'] as $who) {
                    DB::transaction(function () use ($row, $who) {
                        $r = DB::table('lesson_cancellation_mail')->where('id', $row->id)->lockForUpdate()->first();
                        $field = $who.'_sent_at';
                        if ($r->$field) {
                            return;
                        }
                        $p = json_decode($r->payload, true);
                        $address = $p[$who.'_email'] ?? null;
                        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                            return;
                        }
                        Mail::to($address)->send(new LessonCancelled($p));
                        DB::table('lesson_cancellation_mail')->where('id', $r->id)->update([$field => now(), 'updated_at' => now()]);
                    });
                }
                $r = DB::table('lesson_cancellation_mail')->where('id', $row->id)->first();
                if ($r->student_sent_at && $r->teacher_sent_at && $core && is_file($core)) {
                    $prior = getenv('CEC_ACADEMIC_STORE_DIR');
                    putenv('CEC_ACADEMIC_STORE_DIR='.dirname(config('erp.projection_path')));
                    try {
                        \campus_lesson_email_status($row->id, 'sent');
                    } finally {
                        $prior === false ? putenv('CEC_ACADEMIC_STORE_DIR') : putenv('CEC_ACADEMIC_STORE_DIR='.$prior);
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
