<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bureau_pay_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bureau_company_id')->constrained('bureau_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('frequency');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date')->nullable();
            $table->unsignedSmallInteger('tax_year');
            $table->string('status')->default('draft'); // draft|processing|review|finalised
            $table->decimal('total_gross', 16, 2)->default(0);
            $table->decimal('total_paye', 16, 2)->default(0);
            $table->decimal('total_uif_employee', 16, 2)->default(0);
            $table->decimal('total_uif_employer', 16, 2)->default(0);
            $table->decimal('total_sdl', 16, 2)->default(0);
            $table->decimal('total_eti', 16, 2)->default(0);
            $table->decimal('total_deductions', 16, 2)->default(0);
            $table->decimal('total_net', 16, 2)->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->timestamp('finalised_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bureau_company_id', 'status']);
        });

        Schema::create('bureau_payslips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bureau_pay_run_id')->constrained('bureau_pay_runs')->cascadeOnDelete();
            $table->foreignId('bureau_employee_id')->constrained('bureau_employees')->cascadeOnDelete();
            $table->json('employee_snapshot')->nullable();
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('taxable_income', 14, 2)->default(0);
            $table->decimal('paye', 14, 2)->default(0);
            $table->decimal('uif_employee', 14, 2)->default(0);
            $table->decimal('uif_employer', 14, 2)->default(0);
            $table->decimal('sdl', 14, 2)->default(0);
            $table->decimal('eti', 14, 2)->default(0);
            $table->decimal('retirement_deduction', 14, 2)->default(0);
            $table->decimal('medical_credit', 14, 2)->default(0);
            $table->decimal('other_deductions', 14, 2)->default(0);
            $table->decimal('net_pay', 14, 2)->default(0);
            $table->longText('pdf_data')->nullable();   // encrypted payslip PDF (base64)
            $table->boolean('is_locked')->default(false);
            $table->string('status')->default('draft'); // draft|finalised
            $table->timestamps();

            $table->unique(['bureau_pay_run_id', 'bureau_employee_id']);
        });

        Schema::create('bureau_payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bureau_payslip_id')->constrained('bureau_payslips')->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('description');
            $table->string('type'); // earning|deduction|company_contribution
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->boolean('taxable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bureau_payslip_lines');
        Schema::dropIfExists('bureau_payslips');
        Schema::dropIfExists('bureau_pay_runs');
    }
};
