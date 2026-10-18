<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference_number')->unique();
            $table->unsignedBigInteger('employee_id')->index();
            $table->unsignedBigInteger('loan_type_id')->nullable()->index();
            $table->decimal('amount_requested', 12, 2);
            $table->decimal('amount_approved', 12, 2)->nullable();
            $table->decimal('outstanding_balance', 12, 2)->default(0);
            $table->decimal('monthly_installment', 10, 2)->nullable();
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('term_months');
            $table->text('purpose');
            // Snapshot of employee status at time of application
            $table->string('employment_status')->nullable();
            $table->boolean('is_permanent')->default(false);
            $table->boolean('probation_completed')->default(false);
            $table->boolean('is_suspended')->default(false);
            $table->decimal('monthly_net_pay', 12, 2)->nullable();
            $table->enum('status', ['draft', 'pending', 'under_review', 'approved', 'rejected', 'active', 'closed', 'defaulted'])->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('next_payment_date')->nullable();
            $table->json('ai_flags')->nullable();
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->string('risk_level')->nullable();
            $table->decimal('affordability_score', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('employee_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('loan_type_id')->references('id')->on('staff_loan_types')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_loans');
    }
};
