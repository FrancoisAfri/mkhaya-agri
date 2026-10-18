<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. NQF level on certificates ─────────────────────────────────────────
        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->unsignedTinyInteger('nqf_level')->nullable()->after('certificate_number');
            $table->string('saqa_qual_id', 20)->nullable()->after('nqf_level');
        });

        // 2. hr_person_id on PoE portfolios ────────────────────────────────────
        Schema::table('lms_poe_portfolios', function (Blueprint $table) {
            $table->unsignedBigInteger('hr_person_id')->nullable()->after('user_id');
            $table->foreign('hr_person_id')->references('id')->on('hr_people')->nullOnDelete();
        });

        // 3. Unit standard outcomes ────────────────────────────────────────────
        Schema::create('lms_unit_standard_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('unit_standard_id');
            $table->string('outcome_code', 20);
            $table->string('outcome_title', 300);
            $table->text('performance_criteria')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->foreign('unit_standard_id')
                ->references('id')->on('lms_unit_standards')->cascadeOnDelete();
            $table->unique(['unit_standard_id', 'outcome_code']);
        });

        // 4. Link PoE sections to specific outcomes ────────────────────────────
        Schema::table('lms_poe_sections', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_standard_outcome_id')->nullable()->after('unit_standard_id');
            $table->foreign('unit_standard_outcome_id')
                ->references('id')->on('lms_unit_standard_outcomes')->nullOnDelete();
        });

        // 5. Payroll snapshots (auditable B-BBEE baseline) ─────────────────────
        Schema::create('lms_payroll_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('plan_year');
            $table->decimal('annual_leviable_payroll', 15, 2);
            $table->decimal('levy_percentage', 5, 4)->default(0.0100);
            $table->decimal('levy_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->timestamps();
            $table->unique('plan_year');
            $table->foreign('captured_by')->references('id')->on('users')->nullOnDelete();
        });

        // 6. Evidence versioning ───────────────────────────────────────────────
        Schema::table('lms_poe_evidence', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('uploaded_by');
            $table->unsignedBigInteger('previous_version_id')->nullable()->after('version');
            $table->foreign('previous_version_id')
                ->references('id')->on('lms_poe_evidence')->nullOnDelete();
        });

        // 7. Moderation acknowledgment ─────────────────────────────────────────
        Schema::table('lms_moderation_reports', function (Blueprint $table) {
            $table->timestamp('seta_acknowledged_at')->nullable()->after('seta_submitted_at');
            $table->string('seta_acknowledgement_reference', 80)->nullable()->after('seta_acknowledged_at');
            $table->unsignedBigInteger('acknowledged_by')->nullable()->after('seta_acknowledgement_reference');
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
        });

        // 8. PoE section revision history ──────────────────────────────────────
        Schema::create('lms_poe_section_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('poe_section_id');
            $table->unsignedSmallInteger('revision_number');
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('revised_by')->nullable();
            $table->timestamp('revised_at');
            $table->timestamps();
            $table->foreign('poe_section_id')
                ->references('id')->on('lms_poe_sections')->cascadeOnDelete();
            $table->foreign('revised_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_poe_section_revisions');

        Schema::table('lms_moderation_reports', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by']);
            $table->dropColumn(['seta_acknowledged_at', 'seta_acknowledgement_reference', 'acknowledged_by']);
        });

        Schema::table('lms_poe_evidence', function (Blueprint $table) {
            $table->dropForeign(['previous_version_id']);
            $table->dropColumn(['version', 'previous_version_id']);
        });

        Schema::dropIfExists('lms_payroll_snapshots');

        Schema::table('lms_poe_sections', function (Blueprint $table) {
            $table->dropForeign(['unit_standard_outcome_id']);
            $table->dropColumn('unit_standard_outcome_id');
        });

        Schema::dropIfExists('lms_unit_standard_outcomes');

        Schema::table('lms_poe_portfolios', function (Blueprint $table) {
            $table->dropForeign(['hr_person_id']);
            $table->dropColumn('hr_person_id');
        });

        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->dropColumn(['nqf_level', 'saqa_qual_id']);
        });
    }
};
