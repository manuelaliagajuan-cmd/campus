<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_orders', fn (Blueprint $t) => $t->string('sales_origin', 100)->default('campus.cecalicante.es'));
    }

    public function down(): void
    {
        Schema::table('store_orders', fn (Blueprint $t) => $t->dropColumn('sales_origin'));
    }
};
