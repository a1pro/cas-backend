<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            if (! Schema::hasColumn('venues', 'offer_type')) {
                $table->string('offer_type', 20)->default('ride')->after('fulfilment_type');
            }

            if (! Schema::hasColumn('venues', 'ride_trip_type')) {
                $table->string('ride_trip_type', 20)->nullable()->after('offer_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            if (Schema::hasColumn('venues', 'ride_trip_type')) {
                $table->dropColumn('ride_trip_type');
            }

            if (Schema::hasColumn('venues', 'offer_type')) {
                $table->dropColumn('offer_type');
            }
        });
    }
};
