<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The number the third party knows this employee by.
 *
 * `payroll_beneficiaries.reference` is the employer's reference with the fund — one value for the
 * whole relationship. What every third-party deduction file actually keys on is the reference
 * issued per person: a Q Link loan number, a medical-aid membership number, a policy number, a
 * garnishee court-order number. Without it a deduction file cannot be produced at all, because the
 * receiving system has no way to apply the money to an account.
 *
 * It has been missing since remittances were built. The CSV schedule got away with it — a human
 * reading a spreadsheet can match on a name and an ID number — but a machine-read file cannot, and
 * this is why the Q Link and PIPEDEL exports could not be written even though the layout engine and
 * the per-employee schedule were both already there.
 *
 * ── Per (employee, beneficiary), and dated ──────────────────────────────────
 *
 * One employee can hold several references with the same fund over time: a loan settled and a new
 * one taken, a policy replaced. The dates matter because a file for March must carry the reference
 * that was current in March — a settled loan number sent this month is a payment applied to a
 * closed account, which is worse than sending nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deduction_references', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // Which engine's employee. The bureau and the direct payroll keep employees in separate
            // tables, and a reference belongs to a person on one of them — mirrors eti_claims,
            // which had to solve exactly this.
            $table->string('employee_type', 10)->default('hr');   // hr | bureau
            $table->unsignedBigInteger('employee_id');

            $table->foreignId('beneficiary_id')->constrained('payroll_beneficiaries')->cascadeOnDelete();

            // What the third party calls this deduction for this person.
            $table->string('reference', 60);
            // Some files carry a second identifier alongside the reference — an instalment number,
            // a scheme code, a court case number. Kept as a free field rather than a column per
            // vendor, and addressable from a layout the same way.
            $table->string('secondary_reference', 60)->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            // What the deduction is for, when the beneficiary receives more than one kind.
            $table->string('deduction_type', 40)->nullable();
            // The full amount owed, where the third party has told us one. Used to stop deducting
            // past settlement, which is the other half of not paying into a closed account.
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->decimal('instalment', 14, 2)->nullable();

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One live reference per person per beneficiary per purpose. A second one is a data
            // entry mistake that would put the same deduction on the file twice under two
            // different account numbers — the receiving system applies one and queries the other.
            $table->unique(
                ['company_id', 'employee_type', 'employee_id', 'beneficiary_id', 'reference'],
                'deduction_reference_unique',
            );
            $table->index(['beneficiary_id', 'active']);
            $table->index(['employee_type', 'employee_id']);
        });

        Schema::table('payroll_beneficiaries', function (Blueprint $table) {
            // Which outbound layout this fund is sent, by file_specifications.code — 'qlink_deduction',
            // 'pipedel', or whatever the tenant has transcribed from the vendor's document. Null
            // means the CSV schedule, which is what every beneficiary got until now and remains the
            // right answer for a fund that reads a spreadsheet.
            $table->string('file_spec_code', 60)->nullable()->after('line_types');
            // The employer's own identifier on the file, where the vendor issues one that is not the
            // same as the reference already held: Q Link calls it an employer code.
            $table->string('employer_code', 40)->nullable()->after('file_spec_code');
        });

        $this->applyTenantIsolation('payroll_deduction_references');
    }

    private function applyTenantIsolation(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        Schema::table('payroll_beneficiaries', function (Blueprint $table) {
            $table->dropColumn(['file_spec_code', 'employer_code']);
        });

        Schema::dropIfExists('payroll_deduction_references');
    }
};
