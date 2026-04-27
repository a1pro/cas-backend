<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'reserved_at')) {
                $table->timestamp('reserved_at')->nullable()->after('expires_at');
            }
            if (! Schema::hasColumn('coupons', 'used_at')) {
                $table->timestamp('used_at')->nullable()->after('reserved_at');
            }
            if (! Schema::hasColumn('coupons', 'reserved_by_voucher_id')) {
                $table->foreignId('reserved_by_voucher_id')->nullable()->after('used_at')->constrained('vouchers')->nullOnDelete();
            }
            if (! Schema::hasColumn('coupons', 'reserved_by_session_id')) {
                $table->foreignId('reserved_by_session_id')->nullable()->after('reserved_by_voucher_id')->constrained('whatsapp_sessions')->nullOnDelete();
            }
        });

        Schema::table('vouchers', function (Blueprint $table) {
            if (! Schema::hasColumn('vouchers', 'coupon_id')) {
                $table->foreignId('coupon_id')->nullable()->after('venue_id')->constrained('coupons')->nullOnDelete();
            }
            if (! Schema::hasColumn('vouchers', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('redeemed_at');
            }
            if (! Schema::hasColumn('vouchers', 'verification_notes')) {
                $table->text('verification_notes')->nullable()->after('verified_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            foreach (['coupon_id', 'verified_at', 'verification_notes'] as $column) {
                if (Schema::hasColumn('vouchers', $column)) {
                    if ($column === 'coupon_id') {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('coupons', function (Blueprint $table) {
            foreach (['reserved_by_session_id', 'reserved_by_voucher_id'] as $column) {
                if (Schema::hasColumn('coupons', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            foreach (['reserved_at', 'used_at'] as $column) {
                if (Schema::hasColumn('coupons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
