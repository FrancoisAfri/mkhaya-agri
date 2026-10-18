<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Candidate Screening — recruitment-side counterpart to Employee Reputation &
 * Risk. Stores, per candidate application (with consent), the analysis of their
 * PUBLIC professional online presence: category scores, a reputation-risk level,
 * an AI summary, supporting findings and neutral interview questions. All output
 * is for HUMAN REVIEW only — never an automated hire/reject decision. Tenant-scoped
 * (company_id) with a companion RLS migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_screenings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->decimal('reputation_score', 5, 2)->nullable();
            $table->decimal('professionalism_score', 5, 2)->nullable();
            $table->decimal('communication_score', 5, 2)->nullable();
            $table->decimal('leadership_score', 5, 2)->nullable();
            $table->decimal('community_score', 5, 2)->nullable();
            $table->string('risk_level')->nullable();
            $table->text('ai_summary')->nullable();
            // Context only, e.g. 'proceed_with_review' — NOT a hire/reject verdict.
            $table->string('recommendation')->nullable();
            $table->boolean('possible_misidentification')->default(false);
            $table->json('discovered_profiles')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('candidate_screening_findings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('screening_id')->index();
            $table->unsignedBigInteger('candidate_id')->index();
            $table->string('type');                       // professional|risk|context
            $table->string('category');
            $table->string('severity')->default('info');  // info|low|moderate|high|critical
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('source')->nullable();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->text('evidence_url')->nullable();
            $table->text('ai_explanation')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('candidate_screening_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('screening_id')->index();
            $table->text('question');
            $table->text('rationale')->nullable();
            $table->string('based_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_screening_questions');
        Schema::dropIfExists('candidate_screening_findings');
        Schema::dropIfExists('candidate_screenings');
    }
};
