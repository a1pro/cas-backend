<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_voucher_links', function (Blueprint $table) {
            if (! Schema::hasColumn('provider_voucher_links', 'venue_code_reference')) {
                $table->string('venue_code_reference', 6)->nullable()->after('venue_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('provider_voucher_links', function (Blueprint $table) {
            if (Schema::hasColumn('provider_voucher_links', 'venue_code_reference')) {
                $table->dropIndex(['venue_code_reference']);
                $table->dropColumn('venue_code_reference');
            }
        });
    }
};
