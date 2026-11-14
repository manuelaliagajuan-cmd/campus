<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pass_contents', function (Blueprint $t) {
            $t->id();
            $t->string('erp_pass_id', 100)->index();
            $t->string('erp_student_id', 100)->index();
            $t->foreignId('author_id')->constrained('users');
            $t->string('title', 180);
            $t->text('body')->nullable();
            $t->string('youtube_id', 11)->nullable();
            $t->string('path')->nullable();
            $t->string('filename')->nullable();
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pass_contents');
    }
};
