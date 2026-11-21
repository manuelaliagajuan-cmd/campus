<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_participants', fn (Blueprint $t) => $t->index(['online_session_id', 'left_at', 'seen_at'], 'online_presence_lookup'));
        Schema::table('online_sessions', fn (Blueprint $t) => $t->index(['attendance_settled_at', 'booking_end_at'], 'online_attendance_pending'));
    }

    public function down(): void
    {
        Schema::table('online_participants', fn (Blueprint $t) => $t->dropIndex('online_presence_lookup'));
        Schema::table('online_sessions', fn (Blueprint $t) => $t->dropIndex('online_attendance_pending'));
    }
};
