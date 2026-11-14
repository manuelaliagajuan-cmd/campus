<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['courses', 'activities'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (['courses', 'activities'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
