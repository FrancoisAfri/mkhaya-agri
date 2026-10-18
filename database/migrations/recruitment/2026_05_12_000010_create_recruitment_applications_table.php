<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $table->foreignId('job_id')->constrained('recruitment_jobs')->restrictOnDelete();
            $table->foreignId('pipeline_stage_id')->nullable()->constrained('recruitment_pipeline_stages')->nullOnDelete();
            $table->foreignId('recruiter_id')->nullable()->constrained('users')->nullOnDelete();
            // status is set by MoveApplicationAction only — never fillable from HTTP input
            $table->enum('status', [
                'draft', 'applied', 'screening', 'assessment',
                'interview', 'shortlisted', 'offer_sent', 'hired', 'rejected', 'withdrawn',
            ])->default('applied');
            $table->enum('source', [
                'direct', 'referral', 'linkedin', 'job_board', 'agency', 'internal',
            ])->default('direct');
            $table->text('cover_letter')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamp('stage_moved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            // Pipeline board — most common query
            $table->index(['job_id', 'status', 'created_at']);
            // Recruiter workload view
            $table->index(['recruiter_id', 'status']);
            // Duplicate application detection
            $table->unique(['candidate_id', 'job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_applications');
    }
};
