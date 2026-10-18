<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project Invoicing — the professional fee and disbursement account.
 *
 * Digitises a consulting engineer's Excel account workbook: a Tax Invoice, Form 1 (professional
 * fees), Form 2 (disbursements), a Summary, up to thirteen annexures and a cashflow, issued as a
 * numbered sequence of accounts against one project.
 *
 * ── Naming ─────────────────────────────────────────────────────────────────
 *
 * The specification proposed `project_invoice_*`. These are `pm_invoice_*` instead, because the
 * module's tenancy guarantee is asserted by sweeping every `pm\_%` table for forced row-level
 * security (TenantSchemaTest). A financial table outside that prefix would be invisible to the
 * check — which is exactly the table you least want unguarded.
 *
 * ── Money ──────────────────────────────────────────────────────────────────
 *
 * numeric(18,2) with an explicit currency, per PM-ADR-006; rates and percentages are numeric(9,6)
 * because an apportionment of 0.25 and a fee of 0.075 both have to survive being multiplied
 * together. Quantities are numeric(18,4).
 *
 * ── What is frozen and what is derived ─────────────────────────────────────
 *
 * The claim rule is `progress to date − previously claimed`, so an account's arithmetic depends on
 * every account before it. Previously-claimed figures are therefore **snapshotted onto the account
 * when it is issued** rather than recomputed on read: re-deriving them later would let a
 * correction to account 3 silently change what account 7 says it claimed, and account 7 has been
 * sent to a client and paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The account itself ───────────────────────────────────────────────
        Schema::create('pm_invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('client_ref_id')->nullable()->index();

            // "Professional Fee and disbursements account 7" — the type and the number are separate
            // so the sequence can be enforced and the wording can change.
            $t->string('account_type', 80)->default('professional_fee_and_disbursements');
            $t->unsignedInteger('account_number');
            $t->string('invoice_number', 40);
            $t->date('invoice_date');
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();

            // Client details as they appeared when the account was issued. A client that later
            // changes its VAT number must not retrospectively alter a tax invoice.
            $t->string('client_name', 190)->nullable();
            $t->text('client_address')->nullable();
            $t->string('client_vat_number', 40)->nullable();
            $t->string('attention', 190)->nullable();
            $t->string('client_project_number', 60)->nullable();

            $t->string('consultant_name', 190)->nullable();
            $t->text('consultant_address')->nullable();
            $t->string('consultant_reference', 60)->nullable();
            $t->string('vendor_number', 60)->nullable();
            $t->string('consultant_vat_number', 40)->nullable();
            $t->string('file_number', 60)->nullable();

            $t->text('description')->nullable();

            // Stored on the account, not read from company settings: changing the company's VAT
            // rate must never alter an invoice that has already been issued.
            $t->decimal('vat_rate', 9, 6)->default(0.15);
            // The workbook rates its disbursement fee block at 14% where everything else is 15%.
            // Reproduced as configuration rather than a literal — see DisbursementAccount.
            $t->decimal('disbursement_fee_rate', 9, 6)->default(0.14);

            $t->decimal('fees_now_claimed', 18, 2)->default(0);
            $t->decimal('disbursements_now_claimed', 18, 2)->default(0);
            $t->decimal('subtotal', 18, 2)->default(0);
            $t->decimal('vat_amount', 18, 2)->default(0);
            $t->decimal('amount_now_due', 18, 2)->default(0);
            $t->char('currency', 3)->default('ZAR');

            $t->string('status', 20)->default('draft');
            $t->timestamp('generated_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancellation_reason')->nullable();

            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'project_id', 'status']);
            $t->index(['company_id', 'invoice_date']);
        });

        // ── Form 1 header ────────────────────────────────────────────────────
        Schema::create('pm_invoice_fee_accounts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();

            // Value of the works, kept split as the form splits it.
            $t->decimal('external_works', 18, 2)->default(0);
            $t->decimal('structural_works', 18, 2)->default(0);
            $t->decimal('other_works', 18, 2)->default(0);
            $t->decimal('total_value_of_works', 18, 2)->default(0);
            $t->decimal('contingency_allowance', 18, 2)->default(0);
            $t->decimal('construction_completed_to_date', 18, 2)->default(0);

            $t->string('payment_certificate', 60)->nullable();
            $t->date('payment_certificate_dated')->nullable();

            $t->decimal('fee_percentage', 9, 6)->default(0);
            $t->decimal('approved_fee', 18, 2)->nullable();
            // Deliberately separate from the approved fee and never used to calculate a claim: an
            // adjusted fee is a proposal until somebody approves it.
            $t->decimal('adjusted_fee', 18, 2)->nullable();

            $t->decimal('previously_paid_incl_vat', 18, 2)->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
        });

        // ── Form 1 stage rows (3.1 … 3.9) ────────────────────────────────────
        Schema::create('pm_invoice_fee_stages', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();

            // The client's own numbering, gaps included — there is no 3.7.
            $t->string('code', 10);
            $t->string('label', 190);
            $t->unsignedSmallInteger('sort')->default(0);

            // Null on a fixed row such as 3.1 Time basis, whose value comes from an annexure.
            $t->decimal('apportionment', 9, 6)->nullable();
            $t->decimal('progress', 9, 6)->nullable();
            $t->decimal('fixed_value_to_date', 18, 2)->nullable();

            $t->decimal('stage_fee', 18, 2)->default(0);
            $t->decimal('value_to_date', 18, 2)->default(0);
            $t->decimal('previously_claimed', 18, 2)->default(0);
            $t->decimal('this_account', 18, 2)->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
            $t->index(['company_id', 'invoice_id', 'sort']);
        });

        // ── Form 2 rows ──────────────────────────────────────────────────────
        Schema::create('pm_invoice_disbursements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();

            // 'fee' rows are the 14%-rated block; 'category' rows are the annexure-fed schedule.
            $t->string('block', 12)->default('category');
            $t->string('description', 190);
            $t->string('reference', 60)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);

            $t->decimal('this_account', 18, 2)->default(0);
            $t->decimal('previously_claimed', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
            $t->index(['company_id', 'invoice_id', 'block', 'sort']);
        });

        // ── Annexures present on this account ────────────────────────────────
        Schema::create('pm_invoice_annexures', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();

            // 1..13, matching the client's numbering. Not sequential — the workbook has no 8, 9, 10.
            $t->unsignedSmallInteger('number');
            $t->string('title', 190);
            // Which of the five line calculations this annexure uses.
            $t->string('line_type', 30);
            $t->decimal('surcharge_rate', 9, 6)->nullable();

            $t->decimal('previously_claimed', 18, 2)->default(0);
            $t->decimal('this_invoice', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
        });

        // ── Annexure lines ───────────────────────────────────────────────────
        Schema::create('pm_invoice_annexure_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('annexure_id')->index();
            $t->unsignedSmallInteger('sort')->default(0);

            $t->date('entry_date')->nullable();
            $t->string('description', 500)->nullable();
            // Whatever the annexure needs a name for: a traveller, a sub-consultant, a supplier.
            $t->string('party', 190)->nullable();
            $t->string('post_level', 60)->nullable();
            // Annexure 1's A4…A0 and black & white / colour.
            $t->string('size', 20)->nullable();
            $t->string('variant', 30)->nullable();

            $t->decimal('quantity', 18, 4)->nullable();
            $t->decimal('tariff', 18, 4)->nullable();
            $t->decimal('kilometres', 18, 4)->nullable();
            $t->decimal('less_kilometres', 18, 4)->nullable();
            $t->decimal('hours', 18, 4)->nullable();
            $t->decimal('less_hours', 18, 4)->nullable();
            $t->decimal('appointment_amount', 18, 2)->nullable();
            // A row kept but not claimed this period — the workbook's `× 110% × 0`.
            $t->boolean('claimed_this_period')->default(true);

            $t->decimal('previously_claimed', 18, 2)->default(0);
            $t->decimal('this_invoice', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('annexure_id')->references('id')->on('pm_invoice_annexures')->cascadeOnDelete();
            $t->index(['company_id', 'annexure_id', 'sort']);
        });

        // ── What earlier accounts claimed, frozen at issue ───────────────────
        Schema::create('pm_invoice_previous_claims', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();
            $t->unsignedBigInteger('source_invoice_id')->nullable()->index();

            // 'fee_stage' | 'disbursement' | 'annexure_item', with the key it applies to.
            $t->string('subject_type', 30);
            $t->string('subject_key', 60);
            $t->decimal('amount', 18, 2)->default(0);

            $t->timestamps();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
            $t->index(['company_id', 'invoice_id', 'subject_type']);
        });

        // ── Cashflow ─────────────────────────────────────────────────────────
        Schema::create('pm_invoice_cashflows', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('invoice_id')->nullable()->index();

            $t->unsignedSmallInteger('forecast_year');
            $t->unsignedTinyInteger('forecast_month');
            // VAT-inclusive, as the client's sheet has it.
            $t->decimal('amount_incl_vat', 18, 2)->default(0);
            $t->decimal('cumulative', 18, 2)->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'project_id', 'forecast_year', 'forecast_month'], 'pm_invoice_cashflows_period_idx');
        });

        // ── Certification signatures ─────────────────────────────────────────
        Schema::create('pm_invoice_signatures', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();

            // 'consulting_engineer' | 'programme_manager' — the two the form carries.
            $t->string('role', 40);
            $t->string('signer_name', 190)->nullable();
            $t->string('registration_number', 60)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('signed_at')->nullable();
            // Binds the signature to the bytes that were on screen, as pm_document_signatures does.
            $t->char('content_sha256', 64)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
        });

        // ── Generated documents in the pack ──────────────────────────────────
        Schema::create('pm_invoice_documents', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();

            // 'tax_invoice' | 'summary' | 'form_1' | 'form_2' | 'annexure_4' | 'cashflow' | 'pack'
            $t->string('kind', 40);
            $t->string('format', 10)->default('pdf');
            $t->string('filename', 190);
            // The bytes live in pm_document_blobs, which already handles versioning and hashing.
            $t->unsignedBigInteger('blob_id')->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->unsignedBigInteger('generated_by')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
            $t->index(['company_id', 'invoice_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // One account number per project, and one invoice number per company. Partial on
            // deleted_at (PM-ADR-007): a hard UNIQUE would let a cancelled account block the number
            // its replacement needs.
            DB::statement(
                'CREATE UNIQUE INDEX pm_invoices_account_no_unique ON pm_invoices (project_id, account_number) '
                . 'WHERE deleted_at IS NULL'
            );
            DB::statement(
                'CREATE UNIQUE INDEX pm_invoices_invoice_no_unique ON pm_invoices (company_id, invoice_number) '
                . 'WHERE deleted_at IS NULL'
            );
            // An annexure appears at most once on an account.
            DB::statement(
                'CREATE UNIQUE INDEX pm_invoice_annexures_unique ON pm_invoice_annexures (invoice_id, number) '
                . 'WHERE deleted_at IS NULL'
            );
            // One stage code per account.
            DB::statement(
                'CREATE UNIQUE INDEX pm_invoice_fee_stages_unique ON pm_invoice_fee_stages (invoice_id, code) '
                . 'WHERE deleted_at IS NULL'
            );
        }

        $this->applyTenantRls([
            'pm_invoices',
            'pm_invoice_fee_accounts',
            'pm_invoice_fee_stages',
            'pm_invoice_disbursements',
            'pm_invoice_annexures',
            'pm_invoice_annexure_items',
            'pm_invoice_previous_claims',
            'pm_invoice_cashflows',
            'pm_invoice_signatures',
            'pm_invoice_documents',
        ]);
    }

    public function down(): void
    {
        foreach ([
            'pm_invoice_documents',
            'pm_invoice_signatures',
            'pm_invoice_cashflows',
            'pm_invoice_previous_claims',
            'pm_invoice_annexure_items',
            'pm_invoice_annexures',
            'pm_invoice_disbursements',
            'pm_invoice_fee_stages',
            'pm_invoice_fee_accounts',
            'pm_invoices',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Copied per-migration; this module has no shared base class. TenantSchemaTest enforces it. */
    private function applyTenantRls(array $tables): void
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
};
