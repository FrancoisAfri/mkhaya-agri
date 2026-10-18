<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('request_number', 50)->unique()
                ->comment('Human-readable reference e.g. LR-2026-000001');

            // Core relationships
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->foreignId('leave_cycle_id')->nullable()->constrained('leave_cycles')->nullOnDelete();

            // Request mode
            $table->enum('request_mode', ['daily', 'hourly'])->default('daily');
            $table->boolean('is_overtime')->default(false);
            $table->boolean('is_bulk')->default(false);

            // Dates & times
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time')->nullable()->comment('For hourly leave');
            $table->time('end_time')->nullable()->comment('For hourly leave');

            // Duration
            $table->decimal('working_days', 8, 2)->default(0);
            $table->decimal('working_hours', 8, 2)->default(0);
            $table->decimal('hours_reserved', 8, 2)->default(0)
                ->comment('Hours debited from balance when request is submitted');

            // Workflow status
            $table->enum('status', [
                'draft', 'pending', 'pending_manager', 'pending_department',
                'pending_hr', 'pending_payroll', 'approved', 'rejected',
                'cancelled', 'escalated',
            ])->default('pending');

            $table->unsignedTinyInteger('current_approval_level')->default(1);
            $table->foreignId('current_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete()
                ->comment('Admin submitting on behalf of employee');

            // Outcome timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Outcome metadata
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();

            // Escalation
            $table->unsignedTinyInteger('escalation_count')->default(0);
            $table->timestamp('escalated_at')->nullable();

            // BCEA validation
            $table->timestamp('bcea_validated_at')->nullable();
            $table->json('bcea_validation_result')->nullable();

            // AI
            $table->text('ai_explanation')->nullable();
            $table->timestamp('ai_processed_at')->nullable();

            // Integration sync
            $table->timestamp('payroll_synced_at')->nullable();
            $table->timestamp('ta_synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('employee_id');
            $table->index('leave_type_id');
            $table->index('leave_cycle_id');
            $table->index('status');
            $table->index('start_date');
            $table->index(['employee_id', 'status']);
            $table->index(['employee_id', 'start_date', 'end_date']);
            $table->index('current_approver_id');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
