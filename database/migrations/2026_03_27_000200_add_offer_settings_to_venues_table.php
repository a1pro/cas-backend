<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            if (! Schema::hasColumn('venues', 'offer_enabled')) {
                $table->boolean('offer_enabled')->default(true)->after('is_active');
            }

            if (! Schema::hasColumn('venues', 'offer_value')) {
                $table->decimal('offer_value', 8, 2)->default(5.00)->after('offer_enabled');
            }

            if (! Schema::hasColumn('venues', 'offer_days')) {
                $table->json('offer_days')->nullable()->after('offer_value');
            }

            if (! Schema::hasColumn('venues', 'offer_start_time')) {
                $table->time('offer_start_time')->nullable()->after('offer_days');
            }

            if (! Schema::hasColumn('venues', 'offer_end_time')) {
                $table->time('offer_end_time')->nullable()->after('offer_start_time');
            }

            if (! Schema::hasColumn('venues', 'minimum_order')) {
                $table->decimal('minimum_order', 8, 2)->nullable()->after('offer_end_time');
            }

            if (! Schema::hasColumn('venues', 'fulfilment_type')) {
                $table->string('fulfilment_type', 20)->default('venue')->after('minimum_order');
            }

            if (! Schema::hasColumn('venues', 'offer_review_status')) {
                $table->string('offer_review_status', 20)->default('live')->after('fulfilment_type');
            }
        });

        $venues = DB::table('venues')->select('id', 'category')->get();
        foreach ($venues as $venue) {
            DB::table('venues')->where('id', $venue->id)->update([
                'offer_enabled' => 1,
                'offer_value' => 5.00,
                'offer_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
                'minimum_order' => $venue->category === 'restaurant' ? 20.00 : null,
                'fulfilment_type' => $venue->category === 'restaurant' ? 'both' : 'venue',
                'offer_review_status' => 'live',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            foreach (['offer_review_status', 'fulfilment_type', 'minimum_order', 'offer_end_time', 'offer_start_time', 'offer_days', 'offer_value', 'offer_enabled'] as $column) {
                if (Schema::hasColumn('venues', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
