<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            if (! Schema::hasColumn('vouchers', 'provider_voucher_link_id')) {
                $table->foreignId('provider_voucher_link_id')->nullable()->after('venue_id')->constrained('provider_voucher_links')->nullOnDelete();
            }

            if (! Schema::hasColumn('vouchers', 'provider_name')) {
                $table->string('provider_name', 30)->nullable()->after('journey_type');
            }

            if (! Schema::hasColumn('vouchers', 'offer_type')) {
                $table->string('offer_type', 20)->nullable()->after('provider_name');
            }

            if (! Schema::hasColumn('vouchers', 'ride_trip_type')) {
                $table->string('ride_trip_type', 20)->nullable()->after('offer_type');
            }

            if (! Schema::hasColumn('vouchers', 'voucher_link_url')) {
                $table->text('voucher_link_url')->nullable()->after('external_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            foreach (['provider_voucher_link_id', 'provider_name', 'offer_type', 'ride_trip_type', 'voucher_link_url'] as $column) {
                if (Schema::hasColumn('vouchers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
