<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', fn (Blueprint $t) => $t->boolean('online_enabled')->default(false));
        Schema::create('online_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 230)->index();
            $t->string('active_key', 230)->nullable()->unique();
            $t->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('erp_pass_id', 100)->nullable();
            $t->string('erp_student_id', 100)->nullable();
            $t->foreignId('host_id')->constrained('users');
            $t->string('title', 180);
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
        });
        Schema::create('online_participants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('online_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('seen_at');
            $t->timestamp('left_at')->nullable();
            $t->timestamps();
        });
        Schema::create('online_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('online_session_id')->constrained()->cascadeOnDelete();
            $t->uuid('participant_id');
            $t->uuid('recipient_id')->nullable();
            $t->string('kind', 12);
            $t->json('payload');
            $t->timestamp('created_at');
            $t->index(['online_session_id', 'id']);
        });
        Schema::create('online_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('online_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('stars');
            $t->text('feedback')->nullable();
            $t->timestamps();
            $t->unique(['online_session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['online_ratings', 'online_events', 'online_participants', 'online_sessions'] as $n) {
            Schema::dropIfExists($n);
        }Schema::table('courses', fn (Blueprint $t) => $t->dropColumn('online_enabled'));
    }
};
