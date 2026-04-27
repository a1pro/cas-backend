<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_profile_id')->constrained('affiliate_profiles')->cascadeOnDelete();
            $table->foreignId('affiliate_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('referral_code', 24);
            $table->string('status', 24)->default('signed_up');
            $table->timestamp('signed_up_at');
            $table->timestamp('attributed_until')->nullable();
            $table->timestamp('first_voucher_issued_at')->nullable();
            $table->timestamp('first_voucher_redeemed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('referred_user_id');
            $table->index(['affiliate_user_id', 'signed_up_at']);
            $table->index(['referral_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_referrals');
    }
};
