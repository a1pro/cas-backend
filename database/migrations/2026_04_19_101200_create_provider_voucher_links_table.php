<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_voucher_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 30)->default('uber');
            $table->text('link_url');
            $table->string('offer_type', 20)->default('ride');
            $table->string('ride_trip_type', 20)->nullable();
            $table->decimal('voucher_amount', 10, 2)->nullable();
            $table->decimal('minimum_order', 10, 2)->nullable();
            $table->string('location_postcode', 16)->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source', 40)->default('manual');
            $table->string('import_batch_code', 40)->nullable();
            $table->string('source_label', 120)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'venue_id', 'provider', 'is_active'], 'pvl_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_voucher_links');
    }
};
