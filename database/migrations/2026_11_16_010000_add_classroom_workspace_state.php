<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_sessions', function (Blueprint $table) {
            $table->boolean('board_open')->default(false);
            $table->unsignedInteger('board_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('online_sessions', fn (Blueprint $table) => $table->dropColumn(['board_open', 'board_revision']));
    }
};
