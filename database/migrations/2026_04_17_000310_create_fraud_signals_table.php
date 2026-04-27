<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fraud_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signal_type', 60);
            $table->string('severity', 20)->default('low');
            $table->integer('score_delta')->default(0);
            $table->string('status', 20)->default('open');
            $table->string('reason', 500);
            $table->json('context')->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['signal_type', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_signals');
    }
};
