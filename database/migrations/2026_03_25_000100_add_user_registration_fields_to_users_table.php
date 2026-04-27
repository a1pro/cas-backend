<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('postcode', 20)->nullable()->after('phone');
            $table->decimal('latitude', 10, 7)->nullable()->after('postcode');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        DB::table('users')
            ->whereNull('email')
            ->update([
                'email' => DB::raw("CONCAT('restored-', id, '@talktocas.local')"),
            ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_verified_at', 'postcode', 'latitude', 'longitude']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
