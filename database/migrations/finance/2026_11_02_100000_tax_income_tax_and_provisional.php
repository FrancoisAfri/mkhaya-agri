<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax Management — Phase 3: corporate income tax and provisional tax (SRS §8).
 *
 *   tax_account_classifications  how each ledger account is treated for income tax, per business —
 *                                kept between years so a classification is made once and reviewed.
 *   tax_computations             a computation from accounting profit to taxable income and tax:
 *                                kind `cit` (the annual computation behind the ITR14) or `provisional`
 *                                (an IRP6 estimate). Each preparation is a version; approved ones stay.
 *   tax_computation_lines        the trial-balance lines, classifications, adjustments, allowances,
 *                                carried-forward items and credits that make it up — each with a reason.
 *   tax_carry_forwards           balances that move between years (assessed loss, allowances not yet
 *                                claimed), with the computation that set each closing figure.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['tax_account_classifications', 'tax_computations', 'tax_computation_lines', 'tax_carry_forwards'];

    public function up(): void
    {
        Schema::create('tax_account_classifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->unsignedBigInteger('account_id');
            // taxable | deductible | non_deductible | exempt | capital | ignore
            $t->string('treatment', 20);
            $t->text('note')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'entity_id', 'account_id'], 'tax_account_class_unique');
        });

        Schema::create('tax_computations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->foreignId('registration_id')->constrained('tax_registrations');
            $t->foreignId('period_id')->constrained('tax_periods');
            $t->string('kind', 20);                      // cit | provisional
            $t->unsignedTinyInteger('provisional_number')->nullable(); // 1, 2 (or 3, the voluntary top-up)
            $t->date('year_start');
            $t->date('year_end');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->string('status', 20)->default('prepared'); // prepared | under_review | approved | superseded
            $t->string('basis', 30)->nullable();         // trial_balance | estimate | prior_year
            $t->decimal('accounting_profit', 18, 2)->default(0);
            $t->decimal('add_backs', 18, 2)->default(0);
            $t->decimal('deductions', 18, 2)->default(0);
            $t->decimal('exempt_income', 18, 2)->default(0);
            $t->decimal('allowances', 18, 2)->default(0);
            $t->decimal('losses_set_off', 18, 2)->default(0);
            $t->decimal('taxable_income', 18, 2)->default(0);
            $t->decimal('rate', 9, 6)->nullable();
            $t->foreignId('rule_version_id')->nullable()->constrained('tax_rule_versions')->nullOnDelete();
            $t->decimal('tax', 18, 2)->default(0);
            $t->decimal('credits', 18, 2)->default(0);   // provisional payments already made, for the year
            $t->decimal('amount_payable', 18, 2)->default(0);
            $t->decimal('basic_amount', 18, 2)->nullable(); // provisional: prior assessed taxable income, for comparison
            $t->jsonb('snapshot')->nullable();           // the trial-balance cut it was built from
            $t->jsonb('validation')->nullable();
            $t->unsignedBigInteger('prepared_by')->nullable();
            $t->timestampTz('prepared_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_comment')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->text('approval_comment')->nullable();
            $t->timestampsTz();
            $t->unique(['period_id', 'kind', 'provisional_number', 'version'], 'tax_computations_version_unique');
            $t->index(['company_id', 'kind', 'status']);
        });

        Schema::create('tax_computation_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('computation_id')->constrained('tax_computations')->cascadeOnDelete();
            // profit | add_back | deduction | exempt | allowance | loss_set_off | credit
            $t->string('section', 20);
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('account_code', 40)->nullable();
            $t->string('description', 300);
            $t->decimal('amount', 18, 2);
            $t->string('source', 20);                    // ledger | classification | manual | carry_forward | payments
            $t->text('reason')->nullable();
            $t->string('reference', 120)->nullable();    // e.g. s11(e), s12C, s23(g)
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestampsTz();
            $t->index(['computation_id', 'section']);
        });

        Schema::create('tax_carry_forwards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->string('kind', 30);                      // assessed_loss | allowance_balance
            $t->date('year_end');
            $t->decimal('opening', 18, 2)->default(0);
            $t->decimal('movement', 18, 2)->default(0);
            $t->decimal('closing', 18, 2)->default(0);
            $t->unsignedBigInteger('computation_id')->nullable();
            $t->string('source', 20)->default('computation'); // computation | opening_balance | assessment
            $t->text('note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'entity_id', 'kind', 'year_end'], 'tax_carry_forwards_year_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TENANT_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
            // One live computation per period, kind and provisional number; new versions supersede.
            DB::statement("CREATE UNIQUE INDEX tax_computations_one_live ON tax_computations (period_id, kind, COALESCE(provisional_number, 0)) WHERE status <> 'superseded'");
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
