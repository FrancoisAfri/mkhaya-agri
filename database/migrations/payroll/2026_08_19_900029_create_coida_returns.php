<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COIDA — the Return of Earnings, which did not exist.
 *
 * Every employer in South Africa registers under the Compensation for Occupational Injuries and
 * Diseases Act and submits a Return of Earnings each year: actual earnings for the year just ended
 * and an estimate for the year ahead, each employee capped at an annual ceiling that moves yearly.
 * The Compensation Fund then assesses a levy against it.
 *
 * The module had nothing. The only COIDA references anywhere in the codebase are in SHEQ and
 * supplier vetting, collecting a *contractor's* letter of good standing — nothing that computed an
 * employer's own liability. For a bureau, that is a statutory return currently being done on a
 * spreadsheet outside the system.
 *
 * The ceiling itself lives on `sa_labour_rates` rather than here, because it is a statutory figure
 * like the minimum wage and belongs with the others rather than in a second place.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Registration details per employer. Nullable client id: the bureau's own in-house payroll
        // is an employer too, and it has no bureau client row.
        Schema::create('coida_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('bureau_company_id')->nullable();

            $table->string('registration_number', 40)->nullable();
            $table->string('industry_class', 20)->nullable();     // the Fund's class, e.g. "SIC 41"
            $table->string('industry_description')->nullable();

            // Assessed per R100 of leviable earnings, which is how the Fund quotes it.
            $table->decimal('assessment_rate_per_100', 8, 4)->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'bureau_company_id'], 'coida_registration_unique');
        });

        Schema::create('coida_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('bureau_company_id')->nullable();
            $table->foreignId('registration_id')->nullable()->constrained('coida_registrations')->nullOnDelete();

            // The assessment year runs March to February, like the tax year.
            $table->smallInteger('assessment_year');

            $table->decimal('ceiling_applied', 14, 2)->nullable();
            $table->decimal('total_actual_earnings', 16, 2)->default(0);
            $table->decimal('total_leviable_earnings', 16, 2)->default(0);
            $table->decimal('estimated_earnings', 16, 2)->default(0);
            $table->decimal('assessment', 16, 2)->default(0);
            $table->unsignedInteger('employee_count')->default(0);

            // Per employee, as submitted: name, number, actual earnings and the capped figure. Kept
            // rather than recomputed, for the same reason a tax certificate is — the return that was
            // filed has to stay readable after the payroll behind it has moved on.
            $table->json('employees')->nullable();

            $table->string('status', 20)->default('draft');   // draft | final | submitted
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'bureau_company_id', 'assessment_year'], 'coida_return_unique');
        });

        $this->applyTenantIsolation(['coida_registrations', 'coida_returns']);
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
        Schema::dropIfExists('coida_returns');
        Schema::dropIfExists('coida_registrations');
    }
};
