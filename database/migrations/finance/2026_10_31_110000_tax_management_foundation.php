<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax Management & Compliance — Phase 1 foundation.
 *
 * See docs/tax-management/01-gap-assessment-and-architecture.md. What is here is only what Finance
 * and Payroll do not already hold: registrations per legal entity, versioned rules, tax periods,
 * the VAT register, reconciliation runs, the compliance calendar and an append-only audit trail.
 * Documents, the ledger, tax codes and payroll calculations stay where they are.
 *
 * Money is NUMERIC(18,2), rates NUMERIC(9,6). Every tenant table carries company_id under the same
 * row-level-security policy as the rest of Finance, and entity_id where it belongs to a business.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'tax_registrations', 'tax_registration_versions', 'tax_rule_versions', 'tax_periods',
        'tax_period_events', 'tax_transactions', 'tax_reconciliations', 'tax_reconciliation_items',
        'tax_compliance_deadlines', 'tax_audit_events',
    ];

    public function up(): void
    {
        // Global catalogue: which kinds of tax exist and how each behaves. Not tenant data.
        Schema::create('tax_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();            // VAT, PAYE, UIF, SDL, CIT, PROV, WHT
            $t->string('name', 120);
            $t->string('authority', 40)->default('SARS');
            $t->string('category', 20);                  // indirect | payroll | income | other
            $t->boolean('has_return')->default(true);
            $t->boolean('has_payment')->default(true);
            $t->json('frequencies');                     // the filing frequencies this tax allows
            $t->string('default_frequency', 20)->nullable();
            // Filing and payment due-date rules, as data so a changed deadline is not a deployment.
            // e.g. {"rule":"day_of_following_month","day":25,"last_business_day_if_efiling":true}
            $t->json('filing_due_rule')->nullable();
            $t->json('payment_due_rule')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestampsTz();
        });

        Schema::create('tax_registrations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->foreignId('tax_type_id')->constrained('tax_types');
            $t->string('reference', 40)->nullable();     // VAT number, PAYE reference…
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('filing_frequency', 20);          // monthly | bi_monthly_even | bi_monthly_odd | six_monthly | annual
            $t->unsignedTinyInteger('year_end_month')->nullable(); // income tax / annual anchors
            $t->string('authority', 40)->default('SARS');
            $t->string('status', 20)->default('pending'); // pending | active | suspended | deregistered
            $t->unsignedBigInteger('preparer_id')->nullable();
            $t->unsignedBigInteger('reviewer_id')->nullable();
            $t->unsignedBigInteger('escalation_id')->nullable();
            $t->boolean('applicability_confirmed')->default(false); // TAX-REG-003
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->timestampTz('confirmed_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampsTz();
            $t->index(['company_id', 'entity_id', 'tax_type_id', 'status']);
        });

        // TAX-REG-005: every change, with who, when, why and from when it applies.
        Schema::create('tax_registration_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('registration_id')->constrained('tax_registrations')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->jsonb('snapshot');
            $t->jsonb('changes')->nullable();
            $t->text('reason');
            $t->date('effective_date');
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['registration_id', 'version']);
        });

        Schema::create('tax_rule_versions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index(); // null = every entity of the tenant
            $t->foreignId('tax_type_id')->constrained('tax_types');
            $t->string('tax_code', 30);                  // Finance's fin_tax_codes.code
            $t->string('name', 160);
            $t->unsignedInteger('version');
            $t->string('treatment', 20);                 // standard | zero_rated | exempt | out_of_scope | reduced | other
            $t->string('direction', 10);                 // output | input | both | none
            $t->string('method', 20)->default('percentage'); // percentage | fixed | none
            $t->decimal('rate', 9, 6)->default(0);       // percent, e.g. 15.000000
            $t->string('rounding', 20)->default('half_up');
            $t->unsignedTinyInteger('precision')->default(2);
            $t->unsignedBigInteger('control_account_id')->nullable(); // tax_gl_mapping (§18)
            $t->string('return_field', 20)->nullable();  // e.g. VAT201 field 1 / 2 / 14
            $t->string('reporting_class', 40)->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 20)->default('draft');  // draft | pending_approval | published | retired | rejected
            $t->text('legal_reference')->nullable();
            $t->text('change_reason')->nullable();
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->text('approval_comment')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->timestampTz('retired_at')->nullable();
            $t->jsonb('simulation')->nullable();         // TAX-RULE-005: the last test run and its result
            $t->timestampsTz();
            $t->unique(['company_id', 'tax_code', 'entity_id', 'version'], 'tax_rule_versions_code_version_unique');
            $t->index(['company_id', 'tax_code', 'status', 'effective_from']);
        });

        Schema::create('tax_periods', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->foreignId('registration_id')->constrained('tax_registrations');
            $t->foreignId('tax_type_id')->constrained('tax_types');
            $t->string('label', 40);
            $t->date('start_date');
            $t->date('end_date');
            $t->date('filing_due_date')->nullable();
            $t->date('payment_due_date')->nullable();
            $t->string('status', 20)->default('open');
            $t->timestampTz('locked_at')->nullable();
            $t->unsignedBigInteger('locked_by')->nullable();
            $t->unsignedInteger('reopen_count')->default(0);
            $t->timestampsTz();
            $t->unique(['registration_id', 'start_date']);
            $t->index(['company_id', 'entity_id', 'tax_type_id', 'status']);
        });

        Schema::create('tax_period_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('period_id')->constrained('tax_periods')->cascadeOnDelete();
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        // The VAT register (and, later, other transaction-level taxes). One row per source line per
        // version: a changed source line is a new version, the old one is kept and marked superseded.
        Schema::create('tax_transactions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->foreignId('tax_type_id')->constrained('tax_types');
            $t->foreignId('period_id')->nullable()->constrained('tax_periods')->nullOnDelete();
            $t->string('source_module', 30);             // finance_ar | finance_ap | finance_expense | manual | import
            $t->string('source_table', 60);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('source_line_id')->nullable();
            $t->string('source_key', 120);               // idempotency: module:table:id:line
            $t->string('source_hash', 64);               // detects a changed source
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('superseded_by_id')->nullable();
            $t->boolean('is_current')->default(true);
            $t->string('document_number', 60)->nullable();
            $t->string('document_type', 20);             // invoice | credit_note | expense | adjustment
            $t->date('document_date');
            $t->string('direction', 10);                 // output | input
            $t->unsignedBigInteger('party_id')->nullable();
            $t->string('party_name', 200)->nullable();
            $t->string('party_vat_number', 40)->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('journal_id')->nullable()->index(); // the posting, for reconciliation to the GL
            $t->string('tax_code', 30)->nullable();
            $t->string('tax_code_source', 20)->nullable(); // document | inferred | classified
            $t->foreignId('rule_version_id')->nullable()->constrained('tax_rule_versions')->nullOnDelete();
            $t->string('treatment', 20)->nullable();
            $t->string('return_field', 20)->nullable();
            $t->char('currency', 3)->default('ZAR');
            $t->decimal('fx_rate', 18, 8)->default(1);
            $t->string('fx_source', 60)->nullable();
            $t->decimal('taxable_amount', 18, 2);        // base currency, signed (credit notes negative)
            $t->decimal('tax_amount', 18, 2);            // as recorded on the source document
            $t->decimal('calculated_tax', 18, 2)->nullable(); // what the rule in force says
            $t->decimal('difference', 18, 2)->default(0);
            $t->jsonb('calculation')->nullable();        // the full §5.3 result, for reproduction
            $t->jsonb('exceptions')->nullable();         // missing_code | unknown_code | rate_mismatch | no_rule | …
            $t->boolean('has_exception')->default(false);
            $t->string('status', 20)->default('recorded'); // recorded | adjusted | excluded
            $t->timestampsTz();
            $t->unique(['company_id', 'source_key', 'version'], 'tax_transactions_source_version_unique');
            $t->index(['company_id', 'entity_id', 'tax_type_id', 'document_date']);
            $t->index(['period_id', 'is_current']);
            $t->index(['company_id', 'has_exception']);
        });

        Schema::create('tax_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->string('type', 40);                      // vat_register_to_gl, …
            $t->foreignId('period_id')->constrained('tax_periods');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->string('status', 20)->default('completed'); // completed | signed_off | reopened | failed
            $t->timestampTz('cutoff_at');                // TAX-REC-001
            $t->decimal('tolerance', 18, 2)->default(1);
            $t->decimal('source_total', 18, 2)->default(0);
            $t->decimal('target_total', 18, 2)->default(0);
            $t->decimal('difference', 18, 2)->default(0);
            $t->unsignedInteger('matched_count')->default(0);
            $t->unsignedInteger('unmatched_count')->default(0);
            $t->unsignedInteger('exception_count')->default(0);
            $t->jsonb('summary')->nullable();
            $t->jsonb('diagnostics')->nullable();
            $t->unsignedBigInteger('prepared_by')->nullable();
            $t->unsignedBigInteger('signed_off_by')->nullable();
            $t->timestampTz('signed_off_at')->nullable();
            $t->text('sign_off_comment')->nullable();
            $t->timestampsTz();
            $t->unique(['period_id', 'type', 'version']);
        });

        Schema::create('tax_reconciliation_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('reconciliation_id')->constrained('tax_reconciliations')->cascadeOnDelete();
            $t->string('side', 10);                      // source | target | both
            $t->string('match_key', 120)->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('description', 300)->nullable();
            $t->decimal('source_amount', 18, 2)->default(0);
            $t->decimal('target_amount', 18, 2)->default(0);
            $t->decimal('difference', 18, 2)->default(0);
            $t->string('match_state', 20);               // matched | partial | unmatched | exception
            $t->string('difference_class', 20)->nullable(); // timing | rounding | missing | genuine
            $t->text('explanation')->nullable();
            $t->unsignedBigInteger('explained_by')->nullable();
            $t->timestampTz('explained_at')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->jsonb('links')->nullable();              // source records behind the item
            $t->timestampsTz();
            $t->index(['reconciliation_id', 'match_state']);
        });

        Schema::create('tax_compliance_deadlines', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable()->index();
            $t->foreignId('tax_type_id')->nullable()->constrained('tax_types');
            $t->foreignId('registration_id')->nullable()->constrained('tax_registrations')->nullOnDelete();
            $t->foreignId('period_id')->nullable()->constrained('tax_periods')->nullOnDelete();
            $t->string('kind', 20);                      // filing | payment | manual
            $t->string('title', 200);
            $t->date('due_date');
            $t->string('source', 20)->default('rule');   // rule | manual | verified
            $t->string('status', 20)->default('open');   // open | in_progress | done | overdue | waived
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('reviewer_id')->nullable();
            $t->unsignedBigInteger('escalation_id')->nullable();
            $t->json('reminder_days')->nullable();       // e.g. [14, 7, 3, 1]
            $t->jsonb('reminders_sent')->nullable();     // {"7": "2026-10-18T07:00:00Z"}
            $t->timestampTz('escalated_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->text('resolution_note')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['period_id', 'kind']);
            $t->index(['company_id', 'due_date', 'status']);
        });

        Schema::create('tax_audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->string('actor_type', 10)->default('user'); // user | service
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 200)->nullable();
            $t->string('action', 60);
            $t->string('subject_type', 60);
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('tax_type', 20)->nullable();
            $t->unsignedBigInteger('period_id')->nullable();
            $t->unsignedBigInteger('rule_version_id')->nullable();
            $t->string('outcome', 20)->default('success'); // success | denied | failed
            $t->text('reason')->nullable();
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->jsonb('context')->nullable();
            $t->uuid('correlation_id')->nullable();
            $t->string('previous_hash', 64)->nullable();
            $t->string('hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['company_id', 'subject_type', 'subject_id']);
            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'action']);
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

            // §17: audit records cannot be altered. Corrections are new events.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION tax_audit_events_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'tax_audit_events is append-only';
                END;
                $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS tax_audit_events_no_change ON tax_audit_events;
                CREATE TRIGGER tax_audit_events_no_change BEFORE UPDATE OR DELETE ON tax_audit_events
                    FOR EACH ROW EXECUTE FUNCTION tax_audit_events_immutable();
            SQL);

            DB::statement("ALTER TABLE tax_rule_versions ADD CONSTRAINT tax_rule_versions_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)");
            DB::statement("ALTER TABLE tax_registrations ADD CONSTRAINT tax_registrations_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)");
            DB::statement("ALTER TABLE tax_periods ADD CONSTRAINT tax_periods_dates CHECK (end_date >= start_date)");
        }

        $this->seedTaxTypes();
    }

    /**
     * The tax types the module knows how to track. Due-date rules are the generally published SARS
     * ones and are data, not code: §14 requires an approved update path when a deadline changes, and
     * NSEZ's tax professionals must confirm each before reliance (§28).
     */
    private function seedTaxTypes(): void
    {
        $now = now();
        $rows = [
            ['VAT', 'Value-Added Tax', 'indirect', ['monthly', 'bi_monthly_even', 'bi_monthly_odd', 'four_monthly', 'six_monthly', 'annual'], 'bi_monthly_even',
                ['rule' => 'day_of_following_month', 'day' => 25, 'efiling' => 'last_business_day'], ['rule' => 'same_as_filing']],
            ['PAYE', 'Employees\' Tax (PAYE) — EMP201', 'payroll', ['monthly'], 'monthly',
                ['rule' => 'day_of_following_month', 'day' => 7, 'shift' => 'previous_business_day'], ['rule' => 'same_as_filing']],
            ['UIF', 'Unemployment Insurance Fund contributions', 'payroll', ['monthly'], 'monthly',
                ['rule' => 'day_of_following_month', 'day' => 7, 'shift' => 'previous_business_day'], ['rule' => 'same_as_filing']],
            ['SDL', 'Skills Development Levy', 'payroll', ['monthly'], 'monthly',
                ['rule' => 'day_of_following_month', 'day' => 7, 'shift' => 'previous_business_day'], ['rule' => 'same_as_filing']],
            ['CIT', 'Corporate Income Tax (ITR14)', 'income', ['annual'], 'annual',
                ['rule' => 'months_after_period_end', 'months' => 12], ['rule' => 'months_after_period_end', 'months' => 7]],
            ['PROV', 'Provisional Tax (IRP6)', 'income', ['six_monthly'], 'six_monthly',
                ['rule' => 'last_day_of_period'], ['rule' => 'same_as_filing']],
            ['WHT', 'Withholding taxes', 'other', ['monthly'], 'monthly',
                ['rule' => 'day_of_following_month', 'day' => 25], ['rule' => 'same_as_filing']],
        ];

        foreach ($rows as [$code, $name, $category, $frequencies, $default, $filing, $payment]) {
            DB::table('tax_types')->updateOrInsert(['code' => $code], [
                'name' => $name, 'authority' => 'SARS', 'category' => $category,
                'has_return' => true, 'has_payment' => true,
                'frequencies' => json_encode($frequencies), 'default_frequency' => $default,
                'filing_due_rule' => json_encode($filing), 'payment_due_rule' => json_encode($payment),
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS tax_audit_events_no_change ON tax_audit_events; DROP FUNCTION IF EXISTS tax_audit_events_immutable();');
        }
        foreach (array_reverse(array_merge(['tax_types'], self::TENANT_TABLES)) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
