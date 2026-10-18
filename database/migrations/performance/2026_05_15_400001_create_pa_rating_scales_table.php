<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_rating_scales', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('min_score')->default(1);
            $table->unsignedTinyInteger('max_score')->default(5);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pa_rating_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_scale_id')->constrained('pa_rating_scales')->cascadeOnDelete();
            $table->string('label', 100);
            $table->decimal('value', 4, 1);
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_rating_values');
        Schema::dropIfExists('pa_rating_scales');
    }
};
