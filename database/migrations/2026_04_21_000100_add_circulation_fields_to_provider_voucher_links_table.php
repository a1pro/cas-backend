<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_voucher_links', function (Blueprint $table) {
            if (! Schema::hasColumn('provider_voucher_links', 'circulation_mode')) {
                $table->string('circulation_mode', 30)->default('shared_sequence')->after('is_active');
            }

            if (! Schema::hasColumn('provider_voucher_links', 'max_issue_count')) {
                $table->unsignedInteger('max_issue_count')->nullable()->after('circulation_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('provider_voucher_links', function (Blueprint $table) {
            if (Schema::hasColumn('provider_voucher_links', 'max_issue_count')) {
                $table->dropColumn('max_issue_count');
            }

            if (Schema::hasColumn('provider_voucher_links', 'circulation_mode')) {
                $table->dropColumn('circulation_mode');
            }
        });
    }
};
