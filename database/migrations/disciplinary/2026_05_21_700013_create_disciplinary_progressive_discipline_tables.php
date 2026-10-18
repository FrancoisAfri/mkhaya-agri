<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verbal, written, and final written warnings
        Schema::create('disciplinary_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->foreignId('hearing_id')->nullable()->constrained('disciplinary_hearings')->nullOnDelete();
            $table->enum('warning_type', ['verbal', 'written', 'final_written']);
            $table->foreignId('issued_by')->constrained('users');
            $table->date('issued_date');
            $table->date('expiry_date')->nullable();
            $table->text('description');
            $table->string('incident_reference')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->boolean('employee_acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            // File storage (letter)
            $table->string('file_uuid')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->longText('file_data')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'warning_type']);
            $table->index(['employee_id', 'is_expired']);
        });

        // Suspensions (paid / unpaid / precautionary pending investigation)
        Schema::create('disciplinary_suspensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->enum('suspension_type', ['paid', 'unpaid', 'precautionary']);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('expected_return_date')->nullable();
            $table->date('actual_return_date')->nullable();
            $table->text('reason');
            $table->foreignId('issued_by')->constrained('users');
            $table->enum('status', ['active', 'ended', 'cancelled'])->default('active');
            $table->integer('suspension_days')->nullable();
            $table->text('notes')->nullable();
            $table->string('file_uuid')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->longText('file_data')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
        });

        // AI/system escalation recommendations and decisions
        Schema::create('disciplinary_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('case_id')->nullable()->constrained('disciplinary_cases')->nullOnDelete();
            $table->string('from_level')->nullable(); // none|verbal|written|final_written|suspension
            $table->string('to_level');               // verbal|written|final_written|suspension|dismissal
            $table->text('reason');
            $table->foreignId('triggered_by')->constrained('users');
            $table->boolean('ai_recommended')->default(false);
            $table->text('ai_reasoning')->nullable();
            $table->integer('prior_offences_count')->default(0);
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'to_level']);
        });

        // Warning expiry tracking (separate for efficient batch checks)
        Schema::create('disciplinary_warning_expiry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warning_id')->constrained('disciplinary_warnings')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users');
            $table->enum('warning_type', ['verbal', 'written', 'final_written']);
            $table->date('issued_date');
            $table->date('expiry_date');
            $table->boolean('is_expired')->default(false);
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('notified_at')->nullable(); // when HR was notified of expiry
            $table->timestamps();
            $table->index(['expiry_date', 'is_expired']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_warning_expiry');
        Schema::dropIfExists('disciplinary_escalations');
        Schema::dropIfExists('disciplinary_suspensions');
        Schema::dropIfExists('disciplinary_warnings');
    }
};
