<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $t) {
            $t->string('erp_id', 100)->nullable()->unique();
        });
        Schema::table('enrollments', function (Blueprint $t) {
            $t->boolean('source_erp')->default(false);
            $t->boolean('source_manual')->default(true);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('erp_student_id', 100)->nullable()->unique();
            $t->foreignId('email_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('erp_sync_states', function (Blueprint $t) {
            $t->string('source')->primary();
            $t->unsignedBigInteger('revision')->default(0);
            $t->timestamp('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sync_states');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('email_confirmed_by');
            $t->dropColumn('erp_student_id');
        });
        Schema::table('enrollments', fn (Blueprint $t) => $t->dropColumn(['source_erp', 'source_manual']));
        Schema::table('courses', fn (Blueprint $t) => $t->dropColumn('erp_id'));
    }
};
