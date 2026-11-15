<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('display_name', 80)->nullable();
            $t->text('bio')->nullable();
            $t->string('avatar_path')->nullable();
            $t->string('avatar_mime', 30)->nullable();
            $t->string('appearance', 10)->default('system');
            $t->string('accent', 10)->default('blue');
            $t->string('density', 12)->default('comfortable');
            $t->string('text_size', 10)->default('normal');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['display_name', 'bio', 'avatar_path', 'avatar_mime', 'appearance', 'accent', 'density', 'text_size']));
    }
};
