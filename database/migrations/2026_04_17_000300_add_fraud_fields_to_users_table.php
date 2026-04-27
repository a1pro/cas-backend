<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('fraud_score')->default(0)->after('referral_attributed_until');
            $table->string('fraud_status', 20)->default('clear')->after('fraud_score');
            $table->timestamp('fraud_blocked_until')->nullable()->after('fraud_status');
            $table->string('last_device_fingerprint', 128)->nullable()->after('fraud_blocked_until');
            $table->timestamp('last_fraud_review_at')->nullable()->after('last_device_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'fraud_score',
                'fraud_status',
                'fraud_blocked_until',
                'last_device_fingerprint',
                'last_fraud_review_at',
            ]);
        });
    }
};
