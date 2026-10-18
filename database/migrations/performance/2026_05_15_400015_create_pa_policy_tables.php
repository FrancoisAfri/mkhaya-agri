<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique(); // e.g. NO_RELATIVE_APPRAISAL
            $table->text('description')->nullable();
            // appraisal / probation / bonus / kpi / feedback / general
            $table->string('policy_type')->default('general');
            // threshold_check / days_limit / evidence_required / sign_off_lock / relative_check / calibration_required
            $table->string('condition_type');
            $table->json('condition_config')->nullable(); // {"threshold":40} | {"max_days":90} | {"min_evidence":1}
            // warning / soft_block / hard_block
            $table->string('enforcement_level')->default('warning');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['policy_type', 'is_active']);
        });

        Schema::create('pa_policy_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action_type'); // probation_overdue / low_performance_no_pip / kpi_no_evidence …
            $table->string('resource_type')->nullable(); // PaProbationReview / PaEmployeeKpi …
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->json('violation_data')->nullable();
            // detected / escalated / exempted / resolved
            $table->string('status')->default('detected');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('pa_policies')->cascadeOnDelete();
            $table->index(['employee_id', 'status']);
            $table->index(['policy_id', 'status']);
        });

        Schema::create('pa_policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('reason');
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('pa_policies')->cascadeOnDelete();
            $table->index(['employee_id', 'policy_id']);
        });

        Schema::create('pa_escalations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('violation_id');
            $table->unsignedBigInteger('escalated_to');
            $table->unsignedTinyInteger('escalation_level')->default(1); // 1 / 2 / 3
            $table->text('message')->nullable();
            // pending / acknowledged / resolved
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('violation_id')->references('id')->on('pa_policy_violations')->cascadeOnDelete();
            $table->index(['escalated_to', 'status']);
        });

        Schema::create('pa_compliance_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->decimal('compliance_score', 5, 2)->default(0);
            $table->unsignedSmallInteger('total_checks')->default(0);
            $table->unsignedSmallInteger('passed_checks')->default(0);
            $table->unsignedSmallInteger('violation_count')->default(0);
            $table->unsignedSmallInteger('exception_count')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'cycle_id']);
            $table->index('compliance_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_compliance_scores');
        Schema::dropIfExists('pa_escalations');
        Schema::dropIfExists('pa_policy_exceptions');
        Schema::dropIfExists('pa_policy_violations');
        Schema::dropIfExists('pa_policies');
    }
};
