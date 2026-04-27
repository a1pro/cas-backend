<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('whatsapp_sessions')->nullOnDelete();
            $table->foreignId('invited_merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->string('venue_name');
            $table->string('normalized_name')->index();
            $table->string('share_code', 24)->unique();
            $table->string('inviter_name')->nullable();
            $table->string('inviter_phone', 50)->nullable();
            $table->string('inviter_email')->nullable();
            $table->string('source_channel', 50)->default('website');
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_tags');
    }
};
