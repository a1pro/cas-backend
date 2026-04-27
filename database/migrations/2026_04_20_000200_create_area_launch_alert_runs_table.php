<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_launch_alert_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger_source', 50)->default('merchant_approval');
            $table->string('postcode_prefix', 16)->nullable();
            $table->string('city', 120)->nullable();
            $table->json('audience_breakdown')->nullable();
            $table->unsignedInteger('attempted_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->enum('status', ['sent', 'partial', 'skipped', 'failed'])->default('sent');
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at']);
            $table->index(['postcode_prefix', 'created_at']);
            $table->index(['trigger_source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_launch_alert_runs');
    }
};
