<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cas_message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->string('channel')->nullable();
            $table->string('journey_type')->nullable();
            $table->string('weather_condition')->nullable();
            $table->string('emoji', 12)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['key', 'channel', 'journey_type', 'weather_condition'], 'cas_templates_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cas_message_templates');
    }
};
