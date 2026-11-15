<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_sessions', function (Blueprint $t) {
            $t->string('booking_id', 100)->nullable();
            $t->string('booking_signature', 64)->nullable();
            $t->timestamp('booking_start_at')->nullable();
            $t->timestamp('booking_end_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('online_sessions', fn (Blueprint $t) => $t->dropColumn(['booking_id', 'booking_signature', 'booking_start_at', 'booking_end_at']));
    }
};
