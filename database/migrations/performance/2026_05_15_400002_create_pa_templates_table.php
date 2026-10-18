<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('pa_template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('pa_templates')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(0)->comment('Percentage weight of this section');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pa_template_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('pa_template_sections')->cascadeOnDelete();
            $table->text('question');
            $table->text('description')->nullable();
            $table->enum('question_type', ['rating', 'text', 'yes_no', 'multiple_choice'])->default('rating');
            $table->json('options')->nullable()->comment('For multiple_choice questions');
            $table->boolean('is_required')->default(true);
            $table->decimal('weight', 5, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_template_questions');
        Schema::dropIfExists('pa_template_sections');
        Schema::dropIfExists('pa_templates');
    }
};
