<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly Project & Financial Reporting.
 *
 * Digitises a management report the client currently maintains by hand: one row per project, with
 * stage, progress, status, challenges, three fee figures and a six-month cash-flow projection,
 * issued afresh every month and discussed at a meeting.
 *
 * ── The months are rows, never columns ──────────────────────────────────────
 *
 * The document shows March to August 2026. Next month's shows April to September. Modelling that
 * as `march_2026`, `april_2026` columns would mean a migration every month forever, so a
 * projection is a row in `pm_monthly_cashflows` carrying its own year and month, and the six
 * columns a reader sees are a window derived from the period's reporting month.
 *
 * ── Each month is its own record, and earlier ones do not move ──────────────
 *
 * The whole value of the document is that August says something different about a project from
 * July. Reports are therefore per-period rows rather than fields updated in place, and a period
 * that has been issued is frozen — "Copy Previous Month" starts the next one from the last, and
 * leaves the last alone.
 *
 * ── The project record stays the master ─────────────────────────────────────
 *
 * A report row points at a `pm_projects` row and never duplicates it. Name, code and manager are
 * read from the project; what is stored here is only what the client's document says that the
 * project itself does not — their stage vocabulary, their status wording, their challenges text
 * and their fee figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_reporting_periods', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // "August 2026 Monthly Project & Financial Report"
            $t->string('report_name', 190);
            $t->unsignedSmallInteger('reporting_year');
            $t->unsignedTinyInteger('reporting_month');
            // The date the report is discussed — the document's own "Meeting Date: 12 May 2026".
            $t->date('meeting_date')->nullable();

            // How many months the cash-flow projection shows, ending at the reporting month. Six
            // in the client's document; configurable so a different practice is not a code change.
            $t->unsignedTinyInteger('cashflow_months')->default(6);

            $t->string('status', 20)->default('draft');
            $t->timestamp('issued_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'reporting_year', 'reporting_month']);
        });

        Schema::create('pm_monthly_reports', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('reporting_period_id')->index();
            $t->unsignedBigInteger('project_id')->index();

            // The "No." column: the row's position in the client's table, not a database id.
            $t->unsignedSmallInteger('row_number')->default(0);

            // The document sometimes names two people — "Neo/Refiloe". A list of user ids rather
            // than a single manager_id, and rendered back in the client's own style.
            $t->json('project_manager_ids')->nullable();
            $t->string('project_manager_label', 190)->nullable();

            // Their stage vocabulary, not the module's project stages: "Close out/partial",
            // "Construction Drawings & Tender Documentation", "Assessment/scoping". Free text
            // against a configurable list so the client can keep the words they use.
            $t->string('stage', 120)->nullable();
            $t->decimal('progress_percentage', 5, 2)->nullable();
            // Kept as its own field. Deliberately not collapsed into a generic "health" —
            // the client enters the status they use, such as "On Hold".
            $t->string('project_status', 120)->nullable();
            // Multiple bullet-style updates; line breaks are preserved on the way out.
            $t->text('challenges')->nullable();

            // Finances (VAT Excl.), in the client's wording.
            $t->decimal('estimated_professional_fee', 18, 2)->nullable();
            $t->decimal('professional_fee_invoiced_to_date', 18, 2)->nullable();
            // "Total prof fee outstanding (Due to Aseda incl. Disb.)"
            $t->decimal('professional_fee_outstanding', 18, 2)->nullable();
            $t->char('currency', 3)->default('ZAR');

            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('reporting_period_id')->references('id')->on('pm_reporting_periods')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'reporting_period_id', 'row_number']);
        });

        Schema::create('pm_monthly_cashflows', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('monthly_report_id')->index();

            // The month this projection is *for*, which is what makes the columns dynamic.
            $t->unsignedSmallInteger('forecast_year');
            $t->unsignedTinyInteger('forecast_month');
            // Estimated invoice cash-flow projections (VAT Excl.), per the client's heading.
            $t->decimal('amount', 18, 2)->default(0);

            $t->timestamps();

            $t->foreign('monthly_report_id')->references('id')->on('pm_monthly_reports')->cascadeOnDelete();
            $t->index(['company_id', 'monthly_report_id'], 'pm_monthly_cashflows_report_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            // One report per month per company. Partial on deleted_at so a discarded period does
            // not block the month being reported again (PM-ADR-007).
            DB::statement(
                'CREATE UNIQUE INDEX pm_reporting_periods_month_unique ON pm_reporting_periods '
                . '(company_id, reporting_year, reporting_month) WHERE deleted_at IS NULL'
            );
            // A project appears once in a given month's report.
            DB::statement(
                'CREATE UNIQUE INDEX pm_monthly_reports_unique ON pm_monthly_reports '
                . '(reporting_period_id, project_id) WHERE deleted_at IS NULL'
            );
            // And one projection per month per row.
            DB::statement(
                'CREATE UNIQUE INDEX pm_monthly_cashflows_unique ON pm_monthly_cashflows '
                . '(monthly_report_id, forecast_year, forecast_month)'
            );
        }

        $this->applyTenantRls(['pm_reporting_periods', 'pm_monthly_reports', 'pm_monthly_cashflows']);
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_monthly_cashflows');
        Schema::dropIfExists('pm_monthly_reports');
        Schema::dropIfExists('pm_reporting_periods');
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
