<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_sessions', function (Blueprint $t) {
            $t->timestamp('teacher_seen_at')->nullable();
            $t->timestamp('attendance_settled_at')->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->string('consumption_reason')->nullable();
        });
        // Older sessions stored UTC clock values but the application casts in its local timezone.
        DB::table('online_sessions')->whereNotNull('booking_start_at')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $values = [];
                foreach (['booking_start_at', 'booking_end_at'] as $field) {
                    if ($row->$field) {
                        $values[$field] = Carbon::parse($row->$field, 'UTC')->tz(config('app.timezone'))->format('Y-m-d H:i:s');
                    }
                }DB::table('online_sessions')->where('id', $row->id)->update($values);
            }
        });
        Schema::create('lesson_cancellation_mail', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->json('payload');
            $t->timestamp('student_sent_at')->nullable();
            $t->timestamp('teacher_sent_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_cancellation_mail');
        Schema::table('online_sessions', fn (Blueprint $t) => $t->dropColumn(['teacher_seen_at', 'attendance_settled_at', 'consumed_at', 'consumption_reason']));
    }
};
