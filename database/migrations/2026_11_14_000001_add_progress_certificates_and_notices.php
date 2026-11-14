<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('locale', 5)->default('es');
        });
        Schema::table('courses', function (Blueprint $t) {
            $t->boolean('certificate_enabled')->default(true);
            $t->decimal('duration_hours', 7, 1)->nullable();
        });
        Schema::create('certificates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->string('learner_name');
            $t->string('course_title');
            $t->string('language');
            $t->string('level');
            $t->string('teacher_name');
            $t->decimal('duration_hours', 7, 1)->nullable();
            $t->decimal('score', 5, 2)->nullable();
            $t->timestamp('issued_at');
            $t->timestamps();
            $t->unique(['user_id', 'course_id']);
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->foreignId('author_id')->constrained('users');
            $t->string('title', 180);
            $t->text('body');
            $t->string('kind')->default('notice');
            $t->timestamp('publish_at')->index();
            $t->timestamps();
        });
        Schema::create('announcement_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('certificates');
        Schema::table('courses', fn (Blueprint $t) => $t->dropColumn(['certificate_enabled', 'duration_hours']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('locale'));
    }
};
