<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cas_message_templates', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('emoji');
            $table->string('language', 20)->nullable()->after('category');
            $table->string('provider_template_id')->nullable()->after('language');
            $table->string('provider_template_name')->nullable()->after('provider_template_id');
            $table->string('approval_status', 30)->default('draft')->after('provider_template_name');
            $table->text('approval_notes')->nullable()->after('approval_status');
            $table->timestamp('last_submitted_at')->nullable()->after('approval_notes');
            $table->timestamp('last_synced_at')->nullable()->after('last_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('cas_message_templates', function (Blueprint $table) {
            $table->dropColumn([
                'category',
                'language',
                'provider_template_id',
                'provider_template_name',
                'approval_status',
                'approval_notes',
                'last_submitted_at',
                'last_synced_at',
            ]);
        });
    }
};
