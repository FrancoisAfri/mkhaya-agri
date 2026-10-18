<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('payslip_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->enum('correction_type', [
                'duplicate_payment',
                'allowance_error',
                'leave_error',
                'tax_error',
                'other',
            ]);
            $table->text('description');
            $table->text('suggested_action');
            $table->string('field_affected', 100)->nullable();
            $table->decimal('original_value', 14, 2)->nullable();
            $table->decimal('corrected_value', 14, 2)->nullable();
            $table->enum('status', ['suggested', 'approved', 'rejected', 'applied'])->default('suggested');
            $table->string('suggested_by', 100)->default('system');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('run_id');
            $table->index('employee_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_corrections');
    }
};
