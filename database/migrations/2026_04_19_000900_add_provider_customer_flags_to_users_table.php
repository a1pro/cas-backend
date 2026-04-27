<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_uber_existing_customer')->nullable()->after('longitude');
            $table->boolean('is_ubereats_existing_customer')->nullable()->after('is_uber_existing_customer');
            $table->timestamp('provider_profile_updated_at')->nullable()->after('is_ubereats_existing_customer');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_uber_existing_customer',
                'is_ubereats_existing_customer',
                'provider_profile_updated_at',
            ]);
        });
    }
};
