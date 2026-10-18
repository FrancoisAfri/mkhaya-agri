<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 1 full requisitions schema.
 * NOTE: The existing 2026_05_12_000004 migration creates a minimal requisitions table
 * tied to recruitment_jobs. This migration drops and recreates the table with the full
 * Sprint 1 schema that is standalone (not job-scoped).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the old minimal table if it still exists before creating the full schema
        Schema::dropIfExists('recruitment_requisitions');

        Schema::create('recruitment_requisitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('requisition_number', 30)->unique();

            $table->string('title', 200);
            $table->string('department', 100)->nullable();

            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();

            // Reporting line
            $table->unsignedBigInteger('reporting_to')->nullable();

            $table->string('job_type', 30); // VacancyType enum value
            $table->unsignedSmallInteger('headcount')->default(1);
            $table->text('reason');
            $table->text('job_description')->nullable();
            $table->text('required_qualifications')->nullable();
            $table->json('required_skills')->nullable();

            $table->decimal('salary_band_min', 12, 2)->nullable();
            $table->decimal('salary_band_max', 12, 2)->nullable();
            $table->date('target_start_date')->nullable();

            $table->string('status', 30)->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('approval_notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('requested_by');
            $table->index('approved_by');
            $table->index('position_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_requisitions');
    }
};
