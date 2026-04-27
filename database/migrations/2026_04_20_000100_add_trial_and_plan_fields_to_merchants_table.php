<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('onboarding_plan')->default('free_trial')->after('status');
            $table->string('free_trial_status')->default('eligible')->after('onboarding_plan');
            $table->string('free_trial_ineligible_reason')->nullable()->after('free_trial_status');
            $table->text('free_trial_message')->nullable()->after('free_trial_ineligible_reason');
            $table->json('trial_blocked_keywords')->nullable()->after('free_trial_message');
            $table->string('normalized_trial_address')->nullable()->after('trial_blocked_keywords');
            $table->index('normalized_trial_address', 'merchants_normalized_trial_address_index');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropIndex('merchants_normalized_trial_address_index');
            $table->dropColumn([
                'onboarding_plan',
                'free_trial_status',
                'free_trial_ineligible_reason',
                'free_trial_message',
                'trial_blocked_keywords',
                'normalized_trial_address',
            ]);
        });
    }
};
