<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 10, 2)->default(0);
            $table->string('currency', 10)->default('GBP');
            $table->decimal('low_balance_threshold', 10, 2)->default(50);
            $table->boolean('auto_top_up_enabled')->default(false);
            $table->decimal('auto_top_up_amount', 10, 2)->default(100);
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_wallets');
    }
};