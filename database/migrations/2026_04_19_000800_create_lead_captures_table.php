<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('matched_merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('postcode', 16);
            $table->string('city', 120)->nullable();
            $table->enum('journey_type', ['nightlife', 'food']);
            $table->string('desired_venue_name')->nullable();
            $table->string('desired_category', 80)->nullable();
            $table->enum('source', ['discovery_no_results', 'waiting_list', 'manual', 'tag_missing_venue'])->default('manual');
            $table->enum('status', ['new', 'contacted', 'converted', 'archived'])->default('new');
            $table->text('notes')->nullable();
            $table->boolean('contact_consent')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['journey_type', 'status']);
            $table->index(['postcode', 'city']);
            $table->index(['matched_merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_captures');
    }
};
