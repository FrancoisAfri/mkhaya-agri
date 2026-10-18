<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Implementing Agent's payment certificate — the workbook's `AI` sheet.
 *
 * A fourth document on an invoice, and only on invoices where the practice was appointed
 * implementing agent: engaged to run a programme on a municipality's or department's behalf. It
 * certifies the whole project's position for the period — the practice's management fee plus the
 * construction company's payment — on one page the funder's managers sign.
 *
 * Three tables, because the sheet has three lists on it that grow independently: the KPI questions,
 * the schedule of payments made to date, and the certificate itself.
 *
 * ── Why the payment schedule is a table and not a derived query ─────────────
 *
 * It is tempting to build the schedule from the invoices already issued, since row seven is this
 * invoice's own total. But rows one to six are payments *actually made* — dates a treasury paid,
 * not dates we invoiced — and they include payments to the contractor and cession payments that
 * never appeared on any of our invoices. It is a record of the funder's account, so it is entered
 * and kept.
 *
 * ── Money ──────────────────────────────────────────────────────────────────
 *
 * numeric(18,2) per PM-ADR-006, rates numeric(9,6). The management fee ceiling is stored as its
 * pre-VAT base (`H40 = base × 115%`) so that switching a practice's VAT registration moves the
 * ceiling with it rather than leaving a figure that quietly means the wrong thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_invoice_agent_certificates', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('invoice_id')->index();

            // The heading block. Copied onto the certificate when it is created, for the same
            // reason the invoice copies its client details: a signed certificate must keep saying
            // what it said when it was signed.
            $t->string('implementing_agent', 190)->nullable();
            $t->string('project_funder', 190)->nullable();
            $t->string('tender_number', 80)->nullable();
            $t->string('project_name', 190)->nullable();
            $t->string('certificate_number', 40)->nullable();
            // "Is this a cert. of completion?" — free text because the workbook answers "N/A".
            $t->string('completion_certificate', 20)->nullable();

            $t->decimal('total_approved_value', 18, 2)->nullable();
            $t->decimal('total_construction_cost', 18, 2)->nullable();

            // ── Management fee ───────────────────────────────────────────────
            $t->decimal('max_fee_base', 18, 2)->default(0);
            $t->decimal('vat_rate', 9, 6)->default(0.15);
            // Null derives it from the schedule: every payment but the one being certified.
            $t->decimal('claimed_previously', 18, 2)->nullable();

            // ── Construction ─────────────────────────────────────────────────
            $t->decimal('work_done_to_date', 18, 2)->default(0);
            $t->decimal('nominated_subcontractors', 18, 2)->default(0);
            $t->decimal('escalation', 18, 2)->default(0);
            $t->decimal('penalties_recovered', 18, 2)->default(0);
            // Already taken to 80% on the sheet, so it is stored as the claimable figure.
            $t->decimal('materials_on_site', 18, 2)->default(0);
            $t->decimal('previously_certified', 18, 2)->default(0);
            $t->decimal('retention_rate', 9, 6)->default(0.10);

            // ── Retention account ────────────────────────────────────────────
            $t->decimal('previous_retention_held', 18, 2)->default(0);
            $t->decimal('retention_surety_rate', 9, 6)->default(0);
            $t->decimal('cession_payments', 18, 2)->default(0);

            // ── The six people who sign ──────────────────────────────────────
            // Fixed slots rather than a table: the form has exactly these, in this order, and a
            // certificate with a seventh signatory is a different form.
            $t->string('agent_name', 190)->nullable();
            $t->string('project_manager_name', 190)->nullable();
            $t->string('pmu_manager_name', 190)->nullable();
            $t->string('technical_manager_name', 190)->nullable();
            $t->string('cfo_name', 190)->nullable();
            $t->string('municipal_manager_name', 190)->nullable();

            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('invoice_id')->references('id')->on('pm_invoices')->cascadeOnDelete();
        });

        // One certificate per invoice, and a deleted one does not block a replacement (PM-ADR-007).
        DB::statement(
            'CREATE UNIQUE INDEX pm_agent_cert_one_per_invoice ON pm_invoice_agent_certificates '
            . '(invoice_id) WHERE deleted_at IS NULL'
        );

        Schema::create('pm_invoice_agent_kpis', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('certificate_id')->index();

            $t->string('question', 255);
            // "Yes or No" on the form, but nullable so an unanswered question reads as unanswered
            // rather than as No.
            $t->string('answer', 10)->nullable();
            $t->unsignedInteger('position')->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('certificate_id')->references('id')
                ->on('pm_invoice_agent_certificates')->cascadeOnDelete();
        });

        Schema::create('pm_invoice_agent_payments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('certificate_id')->index();

            $t->unsignedInteger('position')->default(0);
            $t->date('paid_on')->nullable();
            $t->string('service_provider', 190)->nullable();

            $t->decimal('to_consultant', 18, 2)->default(0);
            $t->decimal('to_contractor', 18, 2)->default(0);
            $t->decimal('cession', 18, 2)->default(0);

            /**
             * The last row is this invoice's own claim, and is kept in step with it rather than
             * typed. Flagged so the schedule can order and total correctly no matter how the rows
             * were entered — "claimed previously" is every row that is not this one.
             */
            $t->boolean('is_current_claim')->default(false);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('certificate_id')->references('id')
                ->on('pm_invoice_agent_certificates')->cascadeOnDelete();
        });

        Schema::table('pm_invoicing_settings', function (Blueprint $t) {
            // Off by default: most practices are not implementing agents, and a certificate nobody
            // needs is a tab nobody should have to ignore.
            $t->boolean('implementing_agent')->default(false)->after('vat_registered');
            $t->string('default_project_funder', 190)->nullable()->after('implementing_agent');
        });

        $this->applyTenantRls([
            'pm_invoice_agent_certificates',
            'pm_invoice_agent_kpis',
            'pm_invoice_agent_payments',
        ]);
    }

    public function down(): void
    {
        Schema::table('pm_invoicing_settings', function (Blueprint $t) {
            $t->dropColumn(['implementing_agent', 'default_project_funder']);
        });

        foreach ([
            'pm_invoice_agent_payments',
            'pm_invoice_agent_kpis',
            'pm_invoice_agent_certificates',
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
