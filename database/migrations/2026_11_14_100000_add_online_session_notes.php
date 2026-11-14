<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_sessions', function (Blueprint $t) {
            $t->longText('notes')->nullable();
            $t->unsignedInteger('notes_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('online_sessions', fn (Blueprint $t) => $t->dropColumn(['notes', 'notes_revision']));
    }
};
