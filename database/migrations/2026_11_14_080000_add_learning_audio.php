<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['lessons', 'activities', 'attempts'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->foreignId('audio_resource_id')->nullable()->constrained('resources')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['lessons', 'activities', 'attempts'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('audio_resource_id'));
        }
    }
};
