<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_name', 50);
            $table->string('event_type', 80);
            $table->string('verification_result', 40)->default('logged');
            $table->string('provider_reference')->nullable();
            $table->boolean('destination_match')->nullable();
            $table->boolean('charge_applied')->default(false);
            $table->decimal('amount_charged', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['voucher_id', 'event_type']);
            $table->index(['verification_result', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_provider_events');
    }
};
