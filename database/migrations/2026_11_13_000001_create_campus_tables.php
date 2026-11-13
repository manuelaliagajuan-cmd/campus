<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('student')->index();
        });
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_id')->constrained('users');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('language')->default('Inglés');
            $t->string('level')->default('B1');
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'course_id']);
        });
        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('unit')->default('Unidad 1');
            $t->text('content')->nullable();
            $t->string('youtube_id', 11)->nullable();
            $t->unsignedInteger('position')->default(1);
            $t->timestamps();
        });
        Schema::create('completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'lesson_id']);
        });
        Schema::create('resources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('path');
            $t->string('filename');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->timestamps();
        });
        Schema::create('activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('mode')->default('practice');
            $t->json('questions');
            $t->unsignedInteger('max_attempts')->default(0);
            $t->unsignedInteger('pass_percent')->default(70);
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
        Schema::create('activity_lesson', function (Blueprint $t) {
            $t->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $t->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $t->primary(['activity_id', 'lesson_id']);
        });
        Schema::create('attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->json('questions');
            $t->json('answers')->nullable();
            $t->json('feedback')->nullable();
            $t->decimal('score', 5, 2)->nullable();
            $t->unsignedInteger('pass_percent');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->index(['activity_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['attempts', 'activity_lesson', 'activities', 'resources', 'completions', 'lessons', 'enrollments', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
