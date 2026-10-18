<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tax certificates that were actually issued.
 *
 * Today an IRP5 is a query: press download and the totals are recomputed from whatever the payroll
 * data says at that moment. That is fine the first time and wrong every time after it. An employee
 * whose February payslip is corrected in June gets a different certificate from the one they filed
 * their return with, and nothing anywhere records that the two differ — which is the exact situation
 * SARS opens an audit about.
 *
 * So a certificate is a record, not a query. The figures are captured when it is issued and read
 * back afterwards. A correction does not edit it: it issues a new version that supersedes the old
 * one, and both stay. "What did we give this person, and what did we give them before that" has to
 * be answerable years later, because that is the window SARS works in.
 *
 * Both engines, one table, keyed the same way as the incentive ledger — an employee who moved from a
 * bureau client onto the in-house payroll has one tax year, not two halves nobody can reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // 'bureau' → bureau_employees, 'hr' → hr_people, as everywhere else in this module.
            $table->string('employee_type', 10);
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('bureau_company_id')->nullable();

            $table->smallInteger('tax_year');
            // The SARS reconciliation period: 202608 for the interim, 202602 for the full year.
            $table->string('period', 6);

            $table->string('certificate_number', 40);
            $table->string('type', 10);                       // IRP5 | IT3(a)
            $table->unsignedSmallInteger('version')->default(1);

            // What this replaced. A correction issues a new version rather than editing the old one,
            // and the chain is how "what did we give them before that" stays answerable.
            $table->foreignId('supersedes_id')->nullable()->constrained('tax_certificates')->nullOnDelete();
            $table->string('reissue_reason')->nullable();

            $table->string('status', 20)->default('draft');   // draft | final | submitted | cancelled

            // The employee as they were when it was issued. Read back rather than joined: a name or
            // a tax number corrected next year must not silently rewrite a certificate already filed.
            $table->json('employee_snapshot');
            $table->json('employer_snapshot');

            // Every SARS source code and its amount, as issued. The columns below are the same
            // figures lifted out for searching and totalling — the JSON is what the file is built
            // from, because the code set is version-specific and a new code must not need a column.
            $table->json('source_codes');

            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('paye', 14, 2)->default(0);
            $table->decimal('uif', 14, 2)->default(0);
            $table->decimal('sdl', 14, 2)->default(0);
            $table->decimal('eti', 14, 2)->default(0);
            $table->decimal('retirement', 14, 2)->default(0);
            $table->decimal('medical', 14, 2)->default(0);
            $table->decimal('allowances', 14, 2)->default(0);
            $table->decimal('fringe_benefits', 14, 2)->default(0);

            $table->unsignedSmallInteger('periods_worked')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // One live certificate per person per period. A reissue supersedes rather than adds, so
            // the version is part of the key — otherwise a regenerate would either collide or
            // quietly leave two certificates claiming to be current.
            $table->unique(['employee_type', 'employee_id', 'tax_year', 'period', 'version'], 'tax_certificates_unique');
            $table->index(['bureau_company_id', 'tax_year']);
            $table->index(['company_id', 'tax_year', 'status']);
            $table->index('certificate_number');
        });

        $this->applyTenantIsolation(['tax_certificates']);
    }

    private function applyTenantIsolation(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_certificates');
    }
};
