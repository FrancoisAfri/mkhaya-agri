<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_calibration_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->string('title');
            $table->unsignedBigInteger('facilitator_id');
            $table->date('scheduled_date');
            // draft / in_progress / completed / cancelled
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['cycle_id', 'status']);
        });

        Schema::create('pa_calibration_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('calibrator_id')->nullable();
            $table->decimal('original_score', 5, 2)->nullable();
            $table->decimal('calibrated_score', 5, 2)->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->boolean('finalized')->default(false);
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('pa_calibration_sessions')->cascadeOnDelete();
            $table->unique(['session_id', 'employee_id']);
        });

        Schema::create('pa_approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('resource_type')->nullable(); // PaEmployeeKpi / PaDevelopmentPlan / etc.
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pa_approval_workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedSmallInteger('step_order');
            $table->string('step_label');
            $table->unsignedBigInteger('approver_id')->nullable(); // specific user; null = any manager
            $table->string('approver_role')->nullable(); // e.g. 'manager', 'hr', 'director'
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->foreign('workflow_id')->references('id')->on('pa_approval_workflows')->cascadeOnDelete();
            $table->unique(['workflow_id', 'step_order']);
        });

        Schema::create('pa_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->string('resource_type'); // what is being approved
            $table->unsignedBigInteger('resource_id');
            $table->string('title');
            $table->unsignedBigInteger('submitted_by');
            $table->unsignedSmallInteger('current_step')->default(1);
            $table->unsignedSmallInteger('total_steps')->default(1);
            // pending / in_progress / approved / rejected / recalled
            $table->string('status')->default('pending');
            $table->text('final_notes')->nullable();
            $table->timestamps();

            $table->index(['submitted_by', 'status']);
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('pa_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedSmallInteger('step_number');
            $table->unsignedBigInteger('action_by');
            // approved / rejected / recalled / delegated
            $table->string('action');
            $table->text('notes')->nullable();
            $table->timestamp('actioned_at');
            $table->timestamps();

            $table->foreign('request_id')->references('id')->on('pa_approval_requests')->cascadeOnDelete();
            $table->index(['request_id', 'step_number']);
            $table->index(['action_by', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_approval_actions');
        Schema::dropIfExists('pa_approval_requests');
        Schema::dropIfExists('pa_approval_workflow_steps');
        Schema::dropIfExists('pa_approval_workflows');
        Schema::dropIfExists('pa_calibration_participants');
        Schema::dropIfExists('pa_calibration_sessions');
    }
};
