<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('referred_by_user_id')->nullable()->after('longitude')->constrained('users')->nullOnDelete();
            $table->string('referral_code_used', 24)->nullable()->after('referred_by_user_id');
            $table->timestamp('referral_attributed_until')->nullable()->after('referral_code_used');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn(['referral_code_used', 'referral_attributed_until']);
        });
    }
};
