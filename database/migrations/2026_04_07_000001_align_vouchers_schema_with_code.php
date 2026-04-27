<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            if (! Schema::hasColumn('vouchers', 'journey_type')) {
                $table->string('journey_type', 30)->nullable()->after('code');
            }

            if (! Schema::hasColumn('vouchers', 'minimum_order')) {
                $table->decimal('minimum_order', 10, 2)->nullable()->after('total_charge');
            }

            if (! Schema::hasColumn('vouchers', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('redeemed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            foreach (['journey_type', 'minimum_order', 'expires_at'] as $column) {
                if (Schema::hasColumn('vouchers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
