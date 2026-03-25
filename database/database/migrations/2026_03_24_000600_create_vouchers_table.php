<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('destination_postcode');
            $table->string('promo_message', 80)->nullable();
            $table->decimal('voucher_value', 10, 2)->default(5.00);
            $table->decimal('service_fee', 10, 2)->default(2.50);
            $table->decimal('total_charge', 10, 2)->default(7.50);
            $table->enum('status', ['issued', 'redeemed', 'expired', 'cancelled'])->default('issued');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};