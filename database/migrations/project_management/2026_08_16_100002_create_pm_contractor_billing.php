<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subcontractor contracts and billing.
 *
 * Two halves that are deliberately independent, because the practice they are built for does not
 * always do both: a contractor may be engaged on a signed contract, or may simply be a team member
 * who invoices. Invoicing works with no contract at all; a contract, where there is one, gives the
 * invoices something to count against.
 *
 * ── The contract half reuses what exists ────────────────────────────────────
 *
 * The business already has a Contract Management module — a clause library, tenant-authored
 * templates, approval steps, and E-Sign wiring that has been in use. Rebuilding that inside Project
 * Management would have produced a second contract register for the same company to maintain, so
 * this migration adds two columns instead: which project a contract belongs to, and which member of
 * that project's team is the other party.
 *
 * Both are nullable. Every contract written before today belongs to a supplier and no project, and
 * must keep working exactly as it did.
 *
 * ── The billing half is new ─────────────────────────────────────────────────
 *
 * A contractor submits their own invoice from the portal; the project manager approves or rejects
 * it; approval is what makes it billable.
 *
 * ── Markup is internal, and the schema says so ──────────────────────────────
 *
 * `markup_rate` and the two figures derived from it exist so a practice can on-charge a
 * subcontractor's work. The client must never see them, and neither must the contractor whose
 * invoice they are applied to — a contractor who learns their work is being resold at 15% has
 * learned it from us. They live on the invoice rather than in a separate table because they are a
 * property of this claim, but nothing that builds an external payload may read them: see
 * ContractorInvoice::externalPayload().
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The contract half ────────────────────────────────────────────────
        Schema::table('contracts', function (Blueprint $t) {
            $t->unsignedBigInteger('project_id')->nullable()->after('supplier_id')->index();
            $t->unsignedBigInteger('project_member_id')->nullable()->after('project_id')->index();

            $t->foreign('project_id')->references('id')->on('pm_projects')->nullOnDelete();
            $t->foreign('project_member_id')->references('id')->on('pm_project_members')->nullOnDelete();
        });

        // ── The billing half ─────────────────────────────────────────────────
        Schema::create('pm_contractor_invoices', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('project_member_id')->index();
            // Optional: invoicing works with no contract, which is the common case.
            $t->unsignedBigInteger('contract_id')->nullable()->index();

            // Their number, not ours. Two contractors will eventually both call one "INV-001", so
            // this is not unique — it is a reference we quote back to them.
            $t->string('reference', 60)->nullable();
            $t->date('invoice_date')->nullable();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->text('description')->nullable();

            $t->decimal('subtotal', 18, 2)->default(0);
            $t->decimal('vat_rate', 9, 6)->default(0);
            $t->decimal('vat_amount', 18, 2)->default(0);
            $t->decimal('total', 18, 2)->default(0);
            $t->string('currency', 3)->default('ZAR');

            /**
             * Internal only. Null means no markup was applied, which is the default and the normal
             * case — the project manager chooses per invoice, and most of the time chooses not to.
             */
            $t->decimal('markup_rate', 9, 6)->nullable();
            $t->decimal('markup_amount', 18, 2)->default(0);
            /** total + markup — what this work is worth to us, and never shown outside. */
            $t->decimal('billable_total', 18, 2)->default(0);

            $t->string('status', 20)->default('draft');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('decision_reason')->nullable();

            // Their own PDF, held the way every other upload in this codebase is held.
            $t->longText('file_data')->nullable();
            $t->string('file_name', 190)->nullable();
            $t->string('file_mime', 120)->nullable();
            $t->unsignedBigInteger('file_size')->nullable();

            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('project_member_id')->references('id')->on('pm_project_members')->cascadeOnDelete();
            $t->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
        });

        Schema::create('pm_contractor_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('contractor_invoice_id')->index();

            $t->string('description', 255);
            $t->decimal('quantity', 18, 4)->default(1);
            $t->decimal('rate', 18, 2)->default(0);
            $t->decimal('amount', 18, 2)->default(0);
            $t->unsignedInteger('sort')->default(0);

            $t->timestamps();
            $t->softDeletes();

            $t->foreign('contractor_invoice_id')->references('id')
                ->on('pm_contractor_invoices')->cascadeOnDelete();
        });

        $this->applyTenantRls(['pm_contractor_invoices', 'pm_contractor_invoice_lines']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_contractor_invoice_lines');
        Schema::dropIfExists('pm_contractor_invoices');

        Schema::table('contracts', function (Blueprint $t) {
            $t->dropForeign(['project_id']);
            $t->dropForeign(['project_member_id']);
            $t->dropColumn(['project_id', 'project_member_id']);
        });
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
