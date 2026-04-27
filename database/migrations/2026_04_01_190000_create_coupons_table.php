<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('coupons')) {
            return;
        }

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->enum('journey_type', ['going_out', 'order_food']);
            $table->enum('provider', ['uber', 'ubereats', 'manual'])->default('manual');
            $table->string('code')->unique();
            $table->decimal('discount_amount', 8, 2);
            $table->decimal('minimum_order', 8, 2)->nullable();
            $table->boolean('is_new_customer_only')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['draft', 'live', 'expired', 'archived'])->default('draft');
            $table->enum('source', ['manual', 'csv_upload'])->default('manual');
            $table->string('uploaded_file_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['journey_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
