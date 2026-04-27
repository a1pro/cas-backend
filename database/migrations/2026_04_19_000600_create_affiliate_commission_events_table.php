<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliate_commission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('affiliate_referral_id')->nullable()->constrained('affiliate_referrals')->nullOnDelete();
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->string('referral_code', 24)->nullable();
            $table->string('event_type', 48)->default('redeemed_voucher_commission');
            $table->string('status', 24)->default('earned');
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->timestamp('earned_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->json('notification_channels')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('voucher_id');
            $table->index(['affiliate_user_id', 'earned_at']);
            $table->index(['affiliate_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commission_events');
    }
};
