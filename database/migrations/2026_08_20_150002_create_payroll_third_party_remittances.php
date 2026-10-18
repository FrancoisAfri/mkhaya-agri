<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where money deducted from employees is supposed to go.
 *
 * Medical aid, provident fund, union dues, garnishees and maintenance orders all come off the
 * payslip as deduction lines, and nothing scheduled paying any of them over. The employee's net
 * pay is right and the fund never hears from us: no beneficiary records, no remittance file, no
 * reconciliation, and no way to answer "did the September provident payment go?" except by asking
 * the bank.
 *
 * That is not a reporting gap. Money is held on behalf of somebody else, and holding it without a
 * record of paying it across is the kind of thing that ends in a Section 37 complaint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Who the money is owed to.
        Schema::create('payroll_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->index();
            $table->string('name');
            // medical_aid | provident | pension | union | garnishee | maintenance | other
            $table->string('type', 30);
            $table->string('reference')->nullable();       // our membership or employer number
            $table->string('bank_name')->nullable();
            $table->string('branch_code', 20)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('account_type', 20)->nullable();
            $table->string('contact_email')->nullable();
            // Which payslip deduction lines are paid to this beneficiary.
            $table->json('line_types')->nullable();
            $table->unsignedTinyInteger('pay_by_day')->nullable();  // day of the following month
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // What was owed to them for one pay run, and whether it went.
        Schema::create('payroll_remittances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->index();
            $table->unsignedBigInteger('beneficiary_id')->index();
            $table->unsignedBigInteger('payroll_run_id')->nullable()->index();
            // Bureau runs deduct too, and their money is owed to the same funds.
            $table->unsignedBigInteger('bureau_pay_run_id')->nullable()->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('employee_portion', 14, 2)->default(0);
            $table->decimal('employer_portion', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            // due | paid | failed | cancelled
            $table->string('status', 20)->default('due');
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->unsignedBigInteger('marked_by')->nullable();
            // The per-employee breakdown a fund asks for when it queries a payment.
            $table->json('schedule')->nullable();
            $table->timestamps();

            // One remittance per beneficiary per run — recalculating must update, never duplicate.
            $table->unique(['beneficiary_id', 'payroll_run_id'], 'remittance_run_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            $q = chr(39);
            $session = "NULLIF(current_setting({$q}app.company_id{$q}, true), {$q}{$q})";
            $policy = $session . ' IS NULL OR company_id = ' . $session . '::bigint';
            foreach (['payroll_beneficiaries', 'payroll_remittances'] as $t) {
                DB::statement("ALTER TABLE \"{$t}\" ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE \"{$t}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE \"{$t}\" FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$t}\"");
                DB::statement("CREATE POLICY tenant_isolation ON \"{$t}\" USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_remittances');
        Schema::dropIfExists('payroll_beneficiaries');
    }
};
