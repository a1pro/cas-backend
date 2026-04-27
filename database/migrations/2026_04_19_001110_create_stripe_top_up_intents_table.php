<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stripe_top_up_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_wallet_id')->constrained('merchant_wallets')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('GBP');
            $table->string('mode', 30)->default('manual');
            $table->string('provider', 30)->default('stripe');
            $table->string('checkout_code', 20)->unique();
            $table->string('status', 30)->default('pending');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('simulated_payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_top_up_intents');
    }
};
