<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exposure hours and the KPI read projection (§5.11, SHEQ-ARC-4).
 *
 * **Exposure hours** (SHEQ-DASH-4) are the denominator of every frequency rate. Without them LTIFR
 * is undefined, not zero — which is why they are a first-class table with a source flag rather than
 * a setting. A period whose hours were typed in by hand is marked ESTIMATED, because a board pack
 * that cannot distinguish a payroll figure from a guess invites the wrong decision.
 *
 * **The projection** exists because SHEQ-ARC-4 forbids computing KPIs by live aggregation over the
 * transactional tables. A dashboard that scans every incident, action and inspection on each load
 * is fine with one tenant and fatal with a thousand. Rates are computed once per period and read
 * from here; `computed_at` makes projection lag visible rather than assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_exposure_hours', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            // Null site = the whole company for that period.
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            $table->decimal('employee_hours', 12, 2)->default(0);
            // Contractor hours are kept apart: SHEQ-CON-4 needs contractor incidents to roll up
            // both separately and in aggregate, and that is impossible from a single total.
            $table->decimal('contractor_hours', 12, 2)->default(0);

            $table->unsignedInteger('employee_headcount')->nullable();
            $table->unsignedInteger('contractor_headcount')->nullable();

            // PAYROLL|ATTENDANCE|MANUAL — MANUAL surfaces as "estimated" on every rate it feeds.
            $table->string('source', 20)->default('MANUAL');
            $table->text('notes')->nullable();

            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'site_id', 'period_year', 'period_month'], 'sheq_exposure_period_unique');
            $table->index(['company_id', 'period_year', 'period_month']);
        });

        Schema::create('sheq_kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');

            // Every rate and count for the period, keyed by metric id, plus the inputs they were
            // computed from — a KPI whose numerator cannot be shown is not auditable.
            $table->jsonb('metrics');
            $table->jsonb('inputs');

            $table->string('regulatory_pack_id', 60)->nullable();
            $table->string('regulatory_pack_version', 40)->nullable();

            // True when any exposure figure feeding this period was hand-entered.
            $table->boolean('exposure_estimated')->default(false);

            $table->timestampTz('computed_at');
            $table->timestamps();

            $table->unique(['company_id', 'site_id', 'period_year', 'period_month'], 'sheq_kpi_period_unique');
            $table->index(['company_id', 'period_year', 'period_month']);
        });

        $this->applyTenantRls(['sheq_exposure_hours', 'sheq_kpi_snapshots']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_kpi_snapshots');
        Schema::dropIfExists('sheq_exposure_hours');
    }

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
