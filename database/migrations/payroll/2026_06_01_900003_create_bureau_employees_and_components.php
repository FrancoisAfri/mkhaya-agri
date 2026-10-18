<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standalone employee of a bureau client company (no platform login).
        Schema::create('bureau_employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bureau_company_id')->constrained('bureau_companies')->cascadeOnDelete();
            $table->string('employee_number')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('id_number')->nullable();      // SA ID
            $table->string('passport_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('tax_number')->nullable();      // SARS income tax number
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            // employment
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');   // active|terminated|suspended
            $table->string('occupation')->nullable();
            $table->string('pay_frequency')->nullable();   // null = inherit company
            $table->string('payment_type')->default('salary'); // salary|wage
            $table->decimal('basic_salary', 14, 2)->default(0);  // per-period gross basic
            $table->decimal('pay_rate', 12, 4)->nullable();      // hourly rate for wages
            // banking
            $table->string('bank_name')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_type')->nullable();
            $table->string('account_holder')->nullable();
            // statutory inputs
            $table->unsignedTinyInteger('medical_members')->default(0);     // main member counts as 1
            $table->unsignedTinyInteger('medical_dependants')->default(0);  // additional dependants
            $table->decimal('medical_contribution', 12, 2)->default(0);     // monthly
            $table->decimal('retirement_contribution', 12, 2)->default(0);  // monthly RA/pension/provident
            $table->decimal('tax_directive_percentage', 5, 2)->nullable();  // fixed % directive overrides PAYE calc
            $table->boolean('eti_opt_in')->default(true);                   // claim ETI if eligible
            // payslip lock key (encrypted at rest) — the PDF open-password
            $table->text('payslip_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bureau_company_id', 'status']);
        });

        // Per-company reusable earning/deduction catalogue (the "item master").
        Schema::create('bureau_components', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bureau_company_id')->constrained('bureau_companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type');            // earning|deduction|company_contribution|fringe_benefit
            $table->string('calc_method')->default('fixed'); // fixed|percentage_of_basic|per_hour
            $table->decimal('default_amount', 14, 4)->default(0);
            $table->boolean('taxable')->default(true);
            $table->boolean('affects_uif')->default(true);
            $table->boolean('is_recurring')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['bureau_company_id', 'code']);
        });

        // Recurring/once-off inputs assigned to an employee from the catalogue.
        Schema::create('bureau_employee_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bureau_employee_id')->constrained('bureau_employees')->cascadeOnDelete();
            $table->foreignId('bureau_component_id')->constrained('bureau_components')->cascadeOnDelete();
            $table->decimal('amount', 14, 2)->nullable();  // overrides component default
            $table->decimal('quantity', 10, 2)->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bureau_employee_components');
        Schema::dropIfExists('bureau_components');
        Schema::dropIfExists('bureau_employees');
    }
};
