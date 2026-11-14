<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('erp_link_review_id', 64)->nullable();
            $t->string('erp_link_checked_by', 100)->nullable();
            $t->timestamp('erp_link_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['erp_link_review_id', 'erp_link_checked_by', 'erp_link_reviewed_at']));
    }
};
