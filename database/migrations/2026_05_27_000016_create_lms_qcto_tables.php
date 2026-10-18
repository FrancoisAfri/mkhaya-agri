<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add QCTO fields to lms_courses ─────────────────────────────────
        Schema::table('lms_courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('nqf_level')->nullable()->comment('NQF level 1-10');
            $table->decimal('credits', 6, 1)->nullable()->comment('NQF credits (1 credit = 10 notional hours)');
            $table->string('saqa_id', 20)->nullable()->comment('SAQA qualification or unit standard ID');
            $table->string('unit_standard_code', 30)->nullable();
            $table->string('ofo_code', 10)->nullable()->comment('Organising Framework for Occupations code');
            $table->unsignedBigInteger('seta_id')->nullable()->comment('Accrediting SETA');
            $table->string('seta_accreditation_number', 60)->nullable();
            $table->date('seta_accreditation_expires_at')->nullable();
            $table->string('qualification_component', 2)->nullable()->comment('K=Knowledge, P=Practical, WE=Work Experience, SP=Skills Programme');
            $table->boolean('is_qcto_registered')->default(false);
            $table->boolean('is_accredited')->default(false)->comment('Accredited by SETA/QCTO');
        });

        // ── 2. Add OFO code + NQF level to hr_positions ───────────────────────
        Schema::table('hr_positions', function (Blueprint $table) {
            $table->string('ofo_code', 10)->nullable();
            $table->unsignedTinyInteger('nqf_level')->nullable();
        });

        // ── 3. SETAs registry ─────────────────────────────────────────────────
        Schema::create('lms_setas', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('acronym', 20)->unique();
            $table->text('description')->nullable();
            $table->string('website', 200)->nullable();
            $table->string('levy_code', 20)->nullable();
            $table->string('contact_email', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── 4. Unit Standards registry (SAQA) ─────────────────────────────────
        Schema::create('lms_unit_standards', function (Blueprint $table) {
            $table->id();
            $table->string('saqa_id', 20)->unique()->comment('SAQA-issued ID');
            $table->string('title', 300);
            $table->decimal('credits', 6, 1)->default(0);
            $table->unsignedTinyInteger('nqf_level');
            $table->string('field_of_study', 120)->nullable();
            $table->string('sub_field', 120)->nullable();
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->string('type', 30)->default('unit_standard')
                ->comment('unit_standard|qualification|skills_programme|learnership');
            $table->string('status', 20)->default('registered')
                ->comment('registered|reregistered|archived');
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->text('purpose')->nullable();
            $table->timestamps();

            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
        });

        // ── 5. Registered assessors & moderators ──────────────────────────────
        Schema::create('lms_registered_persons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('id_number', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('person_type', 30)
                ->comment('assessor|moderator|assessor_moderator');
            $table->string('registration_number', 60)->nullable();
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->json('nqf_levels')->nullable()->comment('Array of NQF levels this person is registered for');
            $table->json('fields_of_study')->nullable()->comment('Array of SAQA fields registered for');
            $table->string('status', 20)->default('active')
                ->comment('active|lapsed|suspended|cancelled');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
        });

        // ── 6. Skills Development Facilitators ────────────────────────────────
        Schema::create('lms_sdfs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->string('registration_number', 60)->nullable();
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_primary')->default(false)
                ->comment('Primary SDF for levy submissions');
            $table->string('department', 120)->nullable();
            $table->string('status', 20)->default('active')
                ->comment('active|lapsed|suspended');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
        });

        // ── 7. Learnerships & apprenticeships ─────────────────────────────────
        Schema::create('lms_learnerships', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title', 200);
            $table->string('saqa_id', 20)->nullable();
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->unsignedTinyInteger('nqf_level')->nullable();
            $table->decimal('total_credits', 6, 1)->default(0);
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->string('learnership_type', 30)->default('learnership')
                ->comment('learnership|apprenticeship|skills_programme|internship');
            $table->string('ofo_code', 10)->nullable();
            $table->string('seta_programme_code', 60)->nullable();
            // Component course links
            $table->unsignedBigInteger('k_course_id')->nullable()->comment('Knowledge component course');
            $table->unsignedBigInteger('p_course_id')->nullable()->comment('Practical component course');
            // WE is tracked via logbook
            $table->decimal('we_hours_required', 8, 1)->default(0)
                ->comment('Workplace experience hours required');
            $table->decimal('stipend_per_month', 10, 2)->nullable();
            $table->string('stipend_currency', 3)->default('ZAR');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
            $table->foreign('k_course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->foreign('p_course_id')->references('id')->on('lms_courses')->nullOnDelete();
        });

        // ── 8. Learnership enrolments ──────────────────────────────────────────
        Schema::create('lms_learnership_enrolments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('learnership_id');
            $table->unsignedBigInteger('hr_person_id');
            $table->string('seta_learner_id', 60)->nullable()
                ->comment('SETA-issued learner registration number');
            $table->string('seta_registration_number', 60)->nullable()
                ->comment('Enrolment registration reference from SETA');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('registered')
                ->comment('registered|active|completed|withdrawn|transferred|lapsed');
            // Component tracking
            $table->unsignedBigInteger('k_enrollment_id')->nullable()
                ->comment('LMS enrollment for knowledge component');
            $table->unsignedBigInteger('p_enrollment_id')->nullable()
                ->comment('LMS enrollment for practical component');
            $table->decimal('we_hours_logged', 8, 1)->default(0);
            $table->boolean('we_logbook_complete')->default(false);
            $table->timestamp('k_completed_at')->nullable();
            $table->timestamp('p_completed_at')->nullable();
            $table->timestamp('we_completed_at')->nullable();
            // Final assessment
            $table->date('final_assessment_date')->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->string('certificate_number', 60)->nullable();
            $table->date('certificate_issued_at')->nullable();
            $table->decimal('stipend_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('learnership_id')->references('id')->on('lms_learnerships')->onDelete('cascade');
            $table->foreign('k_enrollment_id')->references('id')->on('lms_enrollments')->nullOnDelete();
            $table->foreign('p_enrollment_id')->references('id')->on('lms_enrollments')->nullOnDelete();
        });

        // ── 9. Learnership WBL logbook entries ────────────────────────────────
        Schema::create('lms_learnership_logbook', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('learnership_enrolment_id');
            $table->date('log_date');
            $table->text('activity_description');
            $table->decimal('hours_logged', 5, 1);
            $table->string('workplace_coach', 120)->nullable();
            $table->boolean('coach_signed_off')->default(false);
            $table->timestamp('signed_off_at')->nullable();
            $table->string('evidence_file_path', 500)->nullable();
            $table->text('learner_reflection')->nullable();
            $table->timestamps();

            $table->foreign('learnership_enrolment_id')
                ->references('id')->on('lms_learnership_enrolments')->onDelete('cascade');
        });

        // ── 10. Portfolio of Evidence (PoE) portfolios ────────────────────────
        Schema::create('lms_poe_portfolios', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('learnership_enrolment_id')->nullable();
            $table->string('title', 200);
            $table->string('status', 30)->default('draft')
                ->comment('draft|submitted|under_review|returned|approved|rejected|certified');
            $table->unsignedBigInteger('assessor_id')->nullable()
                ->comment('FK to lms_registered_persons');
            $table->unsignedBigInteger('moderator_id')->nullable()
                ->comment('FK to lms_registered_persons');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->string('assessment_decision', 30)->nullable()
                ->comment('competent|not_yet_competent|insufficient_evidence');
            $table->string('moderation_decision', 20)->nullable()
                ->comment('agreed|different');
            $table->text('assessor_feedback')->nullable();
            $table->text('moderator_feedback')->nullable();
            $table->boolean('learner_declared')->default(false);
            $table->timestamp('declared_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('lms_enrollments')->nullOnDelete();
            $table->foreign('assessor_id')->references('id')->on('lms_registered_persons')->nullOnDelete();
            $table->foreign('moderator_id')->references('id')->on('lms_registered_persons')->nullOnDelete();
        });

        // ── 11. PoE sections (per unit standard outcome) ──────────────────────
        Schema::create('lms_poe_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('poe_portfolio_id');
            $table->unsignedBigInteger('unit_standard_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('outcome_number', 20)->nullable();
            $table->string('status', 20)->default('pending')
                ->comment('pending|submitted|assessed|moderated');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->text('assessor_notes')->nullable();
            $table->string('assessment_decision', 30)->nullable()
                ->comment('competent|not_yet_competent|insufficient_evidence');
            $table->boolean('learner_declaration')->default(false);
            $table->timestamps();

            $table->foreign('poe_portfolio_id')->references('id')
                ->on('lms_poe_portfolios')->onDelete('cascade');
            $table->foreign('unit_standard_id')->references('id')
                ->on('lms_unit_standards')->nullOnDelete();
        });

        // ── 12. PoE evidence items ─────────────────────────────────────────────
        Schema::create('lms_poe_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('poe_section_id');
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('evidence_type', 30)->default('document')
                ->comment('document|image|video|link|observation|testimonial|product|report');
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('url', 500)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->foreign('poe_section_id')->references('id')
                ->on('lms_poe_sections')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('cascade');
        });

        // ── 13. Moderation reports ────────────────────────────────────────────
        Schema::create('lms_moderation_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('moderation_type', 20)->default('internal')
                ->comment('internal|external');
            $table->unsignedBigInteger('moderator_id')
                ->comment('FK to lms_registered_persons');
            $table->unsignedBigInteger('assessor_id')->nullable()
                ->comment('FK to lms_registered_persons');
            $table->date('moderation_date');
            $table->unsignedSmallInteger('total_assessed')->default(0);
            $table->unsignedSmallInteger('sampled_count')->default(0);
            $table->decimal('sample_pct', 5, 2)->default(0);
            $table->unsignedSmallInteger('agreed_count')->default(0);
            $table->unsignedSmallInteger('disagreed_count')->default(0);
            $table->decimal('agreement_rate', 5, 2)->default(0);
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->boolean('action_required')->default(false);
            $table->text('corrective_actions')->nullable();
            $table->string('status', 20)->default('draft')
                ->comment('draft|submitted|approved|rejected');
            $table->string('seta_submission_reference', 80)->nullable();
            $table->date('seta_submitted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->foreign('moderator_id')->references('id')
                ->on('lms_registered_persons')->onDelete('cascade');
            $table->foreign('assessor_id')->references('id')
                ->on('lms_registered_persons')->nullOnDelete();
        });

        // ── 14. NLRD submissions ───────────────────────────────────────────────
        Schema::create('lms_nlrd_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('submission_type', 30)
                ->comment('learner_enrolment|learner_achievement|wspa|atr');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->unsignedBigInteger('sdf_id')->nullable();
            $table->date('submission_date')->nullable();
            $table->string('reference_number', 80)->nullable();
            $table->string('status', 30)->default('draft')
                ->comment('draft|validated|submitted|accepted|rejected|correction_required');
            $table->unsignedInteger('records_count')->default(0);
            $table->string('file_path', 500)->nullable();
            $table->text('error_log')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
            $table->foreign('sdf_id')->references('id')->on('lms_sdfs')->nullOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
        });

        // ── 15. Workplace Skills Plans (WSP) ──────────────────────────────────
        Schema::create('lms_wsp_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedSmallInteger('plan_year');
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->unsignedBigInteger('sdf_id')->nullable();
            $table->date('due_date')->nullable();
            $table->date('submission_date')->nullable();
            $table->string('status', 20)->default('draft')
                ->comment('draft|submitted|approved|rejected|resubmit');
            $table->decimal('total_levy_paid', 14, 2)->default(0);
            $table->decimal('total_planned_spend', 14, 2)->default(0);
            $table->decimal('planned_spend_pct', 5, 2)->default(0)
                ->comment('% of payroll planned for training');
            $table->boolean('mandatory_grant_claimed')->default(false);
            $table->boolean('discretionary_grant_applied')->default(false);
            $table->string('submission_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['plan_year', 'seta_id']);
            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
            $table->foreign('sdf_id')->references('id')->on('lms_sdfs')->nullOnDelete();
        });

        // ── 16. WSP line items ────────────────────────────────────────────────
        Schema::create('lms_wsp_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wsp_plan_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('learnership_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('training_type', 40)
                ->comment('skills_programme|learnership|apprenticeship|bursary|conference|short_course|elearning|other');
            $table->string('training_description', 300)->nullable();
            $table->string('target_group', 40)->nullable()
                ->comment('african_male|african_female|coloured_male|coloured_female|indian_male|indian_female|white_male|white_female|foreign|disabled');
            $table->unsignedTinyInteger('nqf_level')->nullable();
            $table->decimal('credits', 6, 1)->nullable();
            $table->string('ofo_code', 10)->nullable();
            $table->string('saqa_id', 20)->nullable();
            $table->unsignedSmallInteger('planned_beneficiaries')->default(0);
            $table->decimal('planned_cost_per_person', 10, 2)->default(0);
            $table->decimal('total_planned_cost', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('wsp_plan_id')->references('id')->on('lms_wsp_plans')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->foreign('learnership_id')->references('id')->on('lms_learnerships')->nullOnDelete();
        });

        // ── 17. Annual Training Reports (ATR) ─────────────────────────────────
        Schema::create('lms_atr_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('wsp_plan_id')->nullable()
                ->comment('Linked WSP for this report year');
            $table->unsignedBigInteger('seta_id')->nullable();
            $table->unsignedBigInteger('sdf_id')->nullable();
            $table->unsignedSmallInteger('report_year');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('submission_date')->nullable();
            $table->string('status', 20)->default('draft')
                ->comment('draft|submitted|approved|rejected');
            $table->decimal('total_actual_spend', 14, 2)->default(0);
            $table->decimal('actual_spend_pct', 5, 2)->default(0);
            $table->string('submission_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('wsp_plan_id')->references('id')->on('lms_wsp_plans')->nullOnDelete();
            $table->foreign('seta_id')->references('id')->on('lms_setas')->nullOnDelete();
            $table->foreign('sdf_id')->references('id')->on('lms_sdfs')->nullOnDelete();
        });

        // ── 18. ATR line items (one row per employee per training) ────────────
        Schema::create('lms_atr_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('atr_report_id');
            $table->unsignedBigInteger('hr_person_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('learnership_id')->nullable();
            $table->string('training_type', 40);
            $table->string('training_provider', 200)->nullable()
                ->comment('Internal or external provider name');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedTinyInteger('nqf_level')->nullable();
            $table->decimal('credits', 6, 1)->nullable();
            $table->decimal('cost_per_person', 10, 2)->default(0);
            $table->string('result', 20)->default('completed')
                ->comment('completed|in_progress|failed|withdrawn|not_started');
            $table->string('certificate_number', 80)->nullable();
            $table->string('ofo_code', 10)->nullable();
            $table->string('saqa_id', 20)->nullable();
            // B-BBEE demographic data (snapshot at time of report)
            $table->string('race', 20)->nullable()
                ->comment('african|coloured|indian|white|other');
            $table->string('gender', 10)->nullable();
            $table->boolean('has_disability')->default(false);
            $table->timestamps();

            $table->foreign('atr_report_id')->references('id')->on('lms_atr_reports')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
        });

        // ── 19. B-BBEE training spend tracking ────────────────────────────────
        Schema::create('lms_bbee_training_spend', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('spend_year');
            $table->unsignedBigInteger('hr_person_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('learnership_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            // Demographic snapshot (from hr_people at time of training)
            $table->string('race', 20)->nullable()
                ->comment('african|coloured|indian|white|other|not_specified');
            $table->string('gender', 10)->nullable();
            $table->boolean('has_disability')->default(false);
            $table->boolean('is_sa_citizen')->default(true);
            // Training attributes
            $table->string('training_type', 40);
            $table->decimal('cost', 10, 2)->default(0);
            $table->string('currency', 3)->default('ZAR');
            $table->unsignedTinyInteger('nqf_level')->nullable();
            $table->decimal('credits', 6, 1)->nullable();
            $table->boolean('is_accredited')->default(false);
            $table->boolean('is_learnership')->default(false);
            $table->boolean('is_bursary')->default(false);
            $table->boolean('is_absorbed')->default(false)
                ->comment('Learner absorbed after learnership');
            $table->date('training_date');
            $table->string('result', 20)->nullable();
            $table->timestamps();

            $table->foreign('hr_person_id')->references('id')->on('hr_people')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('lms_courses')->nullOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('lms_enrollments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_bbee_training_spend');
        Schema::dropIfExists('lms_atr_items');
        Schema::dropIfExists('lms_atr_reports');
        Schema::dropIfExists('lms_wsp_items');
        Schema::dropIfExists('lms_wsp_plans');
        Schema::dropIfExists('lms_nlrd_submissions');
        Schema::dropIfExists('lms_moderation_reports');
        Schema::dropIfExists('lms_poe_evidence');
        Schema::dropIfExists('lms_poe_sections');
        Schema::dropIfExists('lms_poe_portfolios');
        Schema::dropIfExists('lms_learnership_logbook');
        Schema::dropIfExists('lms_learnership_enrolments');
        Schema::dropIfExists('lms_learnerships');
        Schema::dropIfExists('lms_sdfs');
        Schema::dropIfExists('lms_registered_persons');
        Schema::dropIfExists('lms_unit_standards');
        Schema::dropIfExists('lms_setas');

        Schema::table('hr_positions', function (Blueprint $table) {
            $table->dropColumn(['ofo_code', 'nqf_level']);
        });

        Schema::table('lms_courses', function (Blueprint $table) {
            $table->dropColumn([
                'nqf_level', 'credits', 'saqa_id', 'unit_standard_code',
                'ofo_code', 'seta_id', 'seta_accreditation_number',
                'seta_accreditation_expires_at', 'qualification_component',
                'is_qcto_registered', 'is_accredited',
            ]);
        });
    }
};
