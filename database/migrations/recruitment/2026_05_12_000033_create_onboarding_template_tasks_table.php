<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_template_id')->constrained('onboarding_templates')->cascadeOnDelete();
            $table->string('type', 50); // same OnboardingTaskType enum values
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('day_offset')->default(0); // due on start_date + day_offset
            $table->string('owner_type', 30)->nullable(); // hr|it|manager|buddy
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['onboarding_template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_template_tasks');
    }
};
