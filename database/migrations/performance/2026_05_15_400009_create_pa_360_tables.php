<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_360_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question', 500);
            $table->enum('category', ['leadership', 'teamwork', 'communication', 'technical', 'culture', 'general'])
                  ->default('general');
            $table->enum('question_type', ['rating', 'text', 'both'])->default('both');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pa_360_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete()
                  ->comment('The subject being reviewed');
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->string('title', 200);
            $table->text('instructions')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('require_anonymity')->default(true);
            $table->unsignedTinyInteger('min_raters')->default(3);
            $table->unsignedTinyInteger('max_raters')->default(10);
            $table->enum('status', ['draft', 'collecting', 'closed', 'reported'])->default('draft');
            $table->json('question_ids')->nullable()->comment('Ordered list of pa_360_questions ids to use');
            $table->text('ai_summary')->nullable()->comment('AI-generated report summary');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('pa_360_raters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('pa_360_reviews')->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('users')->cascadeOnDelete();
            $table->enum('relationship', ['self', 'manager', 'peer', 'direct_report', 'external'])
                  ->default('peer');
            $table->enum('status', ['pending', 'submitted', 'declined'])->default('pending');
            $table->boolean('nominated_by_employee')->default(false);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'rater_id']);
        });

        Schema::create('pa_360_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('pa_360_reviews')->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('pa_360_raters')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('pa_360_questions')->nullOnDelete();
            $table->string('question_snapshot', 500)->comment('Snapshot of question text at time of submission');
            $table->string('category', 50)->nullable();
            $table->decimal('rating_value', 3, 1)->nullable();
            $table->text('text_value')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['rater_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_360_responses');
        Schema::dropIfExists('pa_360_raters');
        Schema::dropIfExists('pa_360_reviews');
        Schema::dropIfExists('pa_360_questions');
    }
};
