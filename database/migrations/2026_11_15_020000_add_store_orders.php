<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $t) {
            $t->boolean('store_listed')->default(false);
            $t->unsignedInteger('store_price_cents')->nullable();
        });
        Schema::create('store_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->string('erp_student_id', 100);
            $t->string('kind', 12);
            $t->string('product_key', 120);
            $t->string('product_name', 180);
            $t->json('product');
            $t->unsignedInteger('amount');
            $t->string('currency', 3)->default('eur');
            $t->string('status', 20)->default('pending');
            $t->string('stripe_session_id')->nullable()->unique();
            $t->string('payment_id')->nullable()->unique();
            $t->text('checkout_url')->nullable();
            $t->string('method', 30)->nullable();
            $t->string('buyer_name', 120);
            $t->string('buyer_email', 190);
            $t->string('payer_name', 190)->nullable();
            $t->string('payer_email', 190)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('fulfilled_at')->nullable();
            $t->json('erp_result')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_orders');
        Schema::table('courses', fn (Blueprint $t) => $t->dropColumn(['store_listed', 'store_price_cents']));
    }
};
