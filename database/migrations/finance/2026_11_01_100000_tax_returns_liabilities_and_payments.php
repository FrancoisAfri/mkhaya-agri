<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax Management — Phase 2: returns, liabilities and payments (SRS §10, §11, §13, §15).
 *
 *   tax_return_forms        a return form and its field mapping, versioned (TAX-VAT "configurable
 *                           and versioned mapping"); also the evidence checklist the form needs.
 *   tax_returns             a prepared return: one version per preparation, each kept (TAX-RET-007).
 *   tax_return_lines        the return's fields: calculated, adjusted (with reason) and final.
 *   tax_return_sources      which register lines each field was built from (TAX-RET, §18.3).
 *   tax_liabilities         what is owed or refundable, from an approved return or an assessment.
 *   tax_payment_allocations Finance payments allocated to liabilities (never a payment of our own).
 *
 * Submissions are recorded in the existing sars_submissions register (and its events), linked
 * from the return, so there is one submission history for every SARS return in the system.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['tax_returns', 'tax_return_lines', 'tax_return_sources', 'tax_liabilities', 'tax_payment_allocations'];

    public function up(): void
    {
        Schema::create('tax_return_forms', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20);                      // VAT201, EMP201
            $t->unsignedInteger('version');
            $t->string('tax_type', 20);
            $t->string('name', 120);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->jsonb('fields');                         // [{field, label, kind, source, sign}]
            $t->jsonb('evidence')->nullable();           // [{key, label, required_for}]
            $t->text('source_reference')->nullable();    // the SARS guide the mapping follows
            $t->timestampsTz();
            $t->unique(['code', 'version']);
        });

        Schema::create('tax_returns', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->foreignId('registration_id')->constrained('tax_registrations');
            $t->foreignId('period_id')->constrained('tax_periods');
            $t->foreignId('tax_type_id')->constrained('tax_types');
            $t->foreignId('form_id')->constrained('tax_return_forms');
            $t->string('reference', 40);                 // RET-VAT-2026-03-v1
            $t->string('purpose', 20)->default('original'); // original | amendment
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('amends_id')->nullable();
            $t->unsignedBigInteger('superseded_by_id')->nullable();
            // draft → prepared → under_review → approved → submitted → accepted | rejected; superseded
            $t->string('status', 20)->default('draft');
            $t->decimal('amount_payable', 18, 2)->default(0);   // negative = refundable
            $t->jsonb('totals')->nullable();
            $t->jsonb('snapshot')->nullable();           // source cut: counts, hashes, register versions
            $t->jsonb('validation')->nullable();
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('prepared_by')->nullable();
            $t->timestampTz('prepared_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_comment')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->text('approval_comment')->nullable();
            // Submission (§11). The status here mirrors the SARS register row it links to.
            $t->unsignedBigInteger('sars_submission_id')->nullable();
            $t->string('submission_status', 20)->default('not_submitted'); // not_submitted | pending | submitted | accepted | rejected | unknown | amended
            $t->string('submission_method', 20)->nullable();  // manual_efiling | integration
            $t->string('submission_reference', 120)->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->text('submission_response')->nullable();
            $t->string('payment_status', 20)->default('none'); // none | unpaid | part_paid | paid | refund_due
            $t->timestampsTz();
            $t->unique(['period_id', 'version']);
            $t->index(['company_id', 'status']);
        });

        Schema::create('tax_return_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('return_id')->constrained('tax_returns')->cascadeOnDelete();
            $t->string('field', 20);
            $t->string('label', 200);
            $t->string('kind', 20);                      // value | tax | total
            $t->decimal('calculated', 18, 2)->default(0);
            $t->decimal('adjustment', 18, 2)->default(0);
            $t->decimal('final', 18, 2)->default(0);
            $t->text('adjustment_reason')->nullable();
            $t->unsignedBigInteger('adjusted_by')->nullable();
            $t->timestampTz('adjusted_at')->nullable();
            $t->text('explanation')->nullable();
            $t->unsignedInteger('source_count')->default(0);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestampsTz();
            $t->unique(['return_id', 'field']);
        });

        Schema::create('tax_return_sources', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('return_id')->constrained('tax_returns')->cascadeOnDelete();
            $t->string('field', 20);
            $t->foreignId('transaction_id')->constrained('tax_transactions');
            $t->unsignedInteger('transaction_version');
            $t->decimal('amount', 18, 2);
            $t->index(['return_id', 'field']);
        });

        Schema::create('tax_liabilities', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->foreignId('tax_type_id')->constrained('tax_types');
            $t->foreignId('registration_id')->nullable()->constrained('tax_registrations');
            $t->foreignId('period_id')->nullable()->constrained('tax_periods');
            $t->foreignId('return_id')->nullable()->constrained('tax_returns');
            $t->string('source', 20);                    // return | assessment | interest | penalty | manual
            $t->string('description', 200);
            $t->decimal('original_amount', 18, 2);       // negative = refundable by SARS
            $t->decimal('adjustments', 18, 2)->default(0);
            $t->decimal('paid_amount', 18, 2)->default(0);
            $t->date('due_date')->nullable();
            $t->string('status', 20)->default('open');   // open | part_paid | paid | overpaid | refund_due | cancelled
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['company_id', 'status', 'due_date']);
        });

        Schema::create('tax_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('liability_id')->constrained('tax_liabilities');
            $t->unsignedBigInteger('fin_payment_id');    // a payment recorded in Finance — never one made here
            $t->decimal('amount', 18, 2);
            $t->text('note')->nullable();
            $t->unsignedBigInteger('allocated_by')->nullable();
            $t->timestampTz('allocated_at');
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->timestampTz('reversed_at')->nullable();
            $t->text('reversal_reason')->nullable();
            $t->timestampsTz();
            $t->index(['fin_payment_id']);
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
            // TAX-RET-009: one live return per period; amendments supersede rather than coexist.
            DB::statement("CREATE UNIQUE INDEX tax_returns_one_live_per_period ON tax_returns (period_id) WHERE status NOT IN ('superseded', 'rejected')");
            DB::statement('ALTER TABLE tax_payment_allocations ADD CONSTRAINT tax_payment_allocations_positive CHECK (amount > 0)');
        }

        // ETI reduces what an employer pays on the EMP201; it is tracked as its own tax type so the
        // payroll register can carry it, but it has no return or payment of its own.
        DB::table('tax_types')->updateOrInsert(['code' => 'ETI'], [
            'name' => 'Employment Tax Incentive (offset against PAYE)', 'authority' => 'SARS', 'category' => 'payroll',
            'has_return' => false, 'has_payment' => false, 'frequencies' => json_encode(['monthly']), 'default_frequency' => 'monthly',
            'filing_due_rule' => null, 'payment_due_rule' => null, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seedForms();
    }

    /**
     * Version 1 of the VAT201 and EMP201 mappings. Each field says where its value comes from, so a
     * SARS form change is a new version of this row, not a code change. NSEZ's tax representatives
     * confirm the mapping before reliance (SRS §28).
     */
    private function seedForms(): void
    {
        $now = now();
        $vat = [
            ['field' => '1', 'label' => 'Standard-rated supplies (excl. VAT)', 'kind' => 'value', 'source' => ['direction' => 'output', 'treatment' => 'standard', 'measure' => 'taxable']],
            ['field' => '2', 'label' => 'Zero-rated supplies (excluding exports)', 'kind' => 'value', 'source' => ['direction' => 'output', 'treatment' => 'zero_rated', 'measure' => 'taxable']],
            ['field' => '3', 'label' => 'Exempt and non-supplies', 'kind' => 'value', 'source' => ['direction' => 'output', 'treatment' => ['exempt', 'out_of_scope'], 'measure' => 'taxable']],
            ['field' => '4', 'label' => 'Output tax on standard-rated supplies', 'kind' => 'tax', 'source' => ['direction' => 'output', 'treatment' => 'standard', 'measure' => 'tax']],
            ['field' => '13', 'label' => 'Total output tax', 'kind' => 'total', 'source' => ['sum' => ['4']]],
            ['field' => '14', 'label' => 'Capital goods acquired (input tax)', 'kind' => 'tax', 'source' => ['direction' => 'input', 'return_field' => '14', 'measure' => 'tax']],
            ['field' => '15', 'label' => 'Other goods and services acquired (input tax)', 'kind' => 'tax', 'source' => ['direction' => 'input', 'not_return_field' => '14', 'measure' => 'tax']],
            ['field' => '19', 'label' => 'Total input tax', 'kind' => 'total', 'source' => ['sum' => ['14', '15']]],
            ['field' => '20', 'label' => 'VAT payable / (refundable)', 'kind' => 'total', 'source' => ['sum' => ['13'], 'less' => ['19']]],
        ];
        $emp = [
            ['field' => 'PAYE', 'label' => 'PAYE', 'kind' => 'tax', 'source' => ['tax_type' => 'PAYE', 'measure' => 'tax']],
            ['field' => 'SDL', 'label' => 'SDL', 'kind' => 'tax', 'source' => ['tax_type' => 'SDL', 'measure' => 'tax']],
            ['field' => 'UIF', 'label' => 'UIF (employee and employer)', 'kind' => 'tax', 'source' => ['tax_type' => 'UIF', 'measure' => 'tax']],
            ['field' => 'ETI', 'label' => 'ETI utilised', 'kind' => 'tax', 'source' => ['tax_type' => 'ETI', 'measure' => 'tax']],
            ['field' => 'TOTAL', 'label' => 'Total payable', 'kind' => 'total', 'source' => ['sum' => ['PAYE', 'SDL', 'UIF'], 'less' => ['ETI']]],
        ];
        $evidence = [
            ['key' => 'schedule', 'label' => 'Signed supporting schedule', 'required_for' => 'approve'],
            ['key' => 'submission_receipt', 'label' => 'SARS submission receipt / acknowledgement', 'required_for' => 'submitted'],
            ['key' => 'statement_of_account', 'label' => 'SARS statement of account (after payment)', 'required_for' => null],
        ];

        foreach ([['VAT201', 'VAT', 'VAT201 — Value-Added Tax return', $vat, 'SARS VAT201 guide (field numbers as on the eFiling form)'],
            ['EMP201', 'PAYE', 'EMP201 — Monthly employer declaration', $emp, 'SARS EMP201 guide']] as [$code, $type, $name, $fields, $ref]) {
            DB::table('tax_return_forms')->updateOrInsert(['code' => $code, 'version' => 1], [
                'tax_type' => $type, 'name' => $name, 'effective_from' => '2018-04-01', 'fields' => json_encode($fields),
                'evidence' => json_encode($evidence), 'source_reference' => $ref, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['tax_payment_allocations', 'tax_liabilities', 'tax_return_sources', 'tax_return_lines', 'tax_returns', 'tax_return_forms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
