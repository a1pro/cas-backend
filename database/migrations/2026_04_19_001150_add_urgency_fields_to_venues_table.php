<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            if (! Schema::hasColumn('venues', 'urgency_enabled')) {
                $table->boolean('urgency_enabled')->default(true)->after('offer_review_status');
            }

            if (! Schema::hasColumn('venues', 'daily_voucher_cap')) {
                $table->unsignedInteger('daily_voucher_cap')->nullable()->after('urgency_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            if (Schema::hasColumn('venues', 'daily_voucher_cap')) {
                $table->dropColumn('daily_voucher_cap');
            }

            if (Schema::hasColumn('venues', 'urgency_enabled')) {
                $table->dropColumn('urgency_enabled');
            }
        });
    }
};
