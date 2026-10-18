<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_fit_scores', function (Blueprint $table) {
            $table->id();
            // One score per application
            $table->foreignId('application_id')->unique()->constrained('recruitment_applications')->cascadeOnDelete();
            // Denormalized for shortlist ranking index — avoids join on hot path
            $table->foreignId('job_id')->constrained('recruitment_jobs')->cascadeOnDelete();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->decimal('skills_score', 5, 2)->nullable();
            $table->decimal('experience_score', 5, 2)->nullable();
            $table->decimal('qualification_score', 5, 2)->nullable();
            $table->decimal('cultural_score', 5, 2)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->string('ai_model_used', 100)->nullable();
            $table->timestamps();

            // Shortlist ranking — partial index skips unscored rows
            $table->index('job_id');
        });

        // Partial index for shortlist ranking (only scored applications)
        DB::statement("
            CREATE INDEX idx_fit_scores_job_score
            ON candidate_fit_scores (job_id, overall_score DESC)
            WHERE overall_score IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_fit_scores_job_score');
        Schema::dropIfExists('candidate_fit_scores');
    }
};
