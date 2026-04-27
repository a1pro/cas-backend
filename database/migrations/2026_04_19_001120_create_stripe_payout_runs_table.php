<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_payout_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_payout_profile_id')->nullable()->constrained('affiliate_payout_profiles')->nullOnDelete();
            $table->string('provider')->default('stripe_connect');
            $table->string('payout_code')->unique();
            $table->string('status')->default('processing');
            $table->string('currency', 3)->default('GBP');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->unsignedInteger('commission_event_count')->default(0);
            $table->string('stripe_payout_id')->nullable()->index();
            $table->string('paid_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payout_runs');
    }
};
