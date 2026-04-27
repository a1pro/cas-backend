<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bnpl_voucher_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('checkout_code', 32)->unique();
            $table->string('plan_key', 50);
            $table->string('plan_name');
            $table->decimal('amount_gbp', 10, 2);
            $table->string('payment_provider', 50)->default('stripe_bnpl');
            $table->string('payment_status', 30)->default('pending');
            $table->string('voucher_status', 30)->default('pending_payment');
            $table->string('voucher_code', 40)->nullable()->unique();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 50);
            $table->timestamp('checkout_completed_at')->nullable();
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->timestamp('voucher_issued_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['payment_status', 'voucher_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bnpl_voucher_orders');
    }
};
