<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_commission_events', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_commission_events', 'payout_run_id')) {
                $table->foreignId('payout_run_id')->nullable()->after('voucher_id')->constrained('stripe_payout_runs')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_commission_events', function (Blueprint $table) {
            if (Schema::hasColumn('affiliate_commission_events', 'payout_run_id')) {
                $table->dropConstrainedForeignId('payout_run_id');
            }
        });
    }
};
