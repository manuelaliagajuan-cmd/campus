<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $t) {
            $t->string('cover_path')->nullable();
            $t->string('cover_mime', 40)->nullable();
            $t->string('cover_color', 7)->default('#eff0f4');
            $t->string('cover_text_color', 7)->default('#212a5e');
            $t->unsignedTinyInteger('cover_opacity')->default(100);
            $t->string('cover_text', 60)->nullable()->default('Aa');
            $t->string('cover_label', 120)->nullable();
            $t->string('cover_font', 20)->default('sans');
            $t->unsignedTinyInteger('cover_text_size')->default(58);
            $t->string('cover_align', 10)->default('right');
        });
    }

    public function down(): void
    {
        Schema::table('courses', fn (Blueprint $t) => $t->dropColumn(['cover_path', 'cover_mime', 'cover_color', 'cover_text_color', 'cover_opacity', 'cover_text', 'cover_label', 'cover_font', 'cover_text_size', 'cover_align']));
    }
};
