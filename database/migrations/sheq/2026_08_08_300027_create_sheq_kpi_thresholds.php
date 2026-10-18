<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thresholds for the leading and lagging indicators (§8.2, `sheq.risk.threshold_breached`).
 *
 * The event was registered and documented from the start but had nothing to fire against: there was
 * no way for a tenant to say what "too high" means for them. That is not a detail that can be
 * hard-coded. A DIFR of 3 is unremarkable in construction and a crisis in a bottling plant, and the
 * number a board reacts to is set by the board — usually against last year's figure or a group
 * target, not against anything the software could guess.
 *
 * `direction` exists because half of these indicators are inverted. Lagging indicators breach when
 * they rise: injury rate, days lost. Leading indicators breach when they *fall* — inspections
 * completed, toolbox talks held, actions closed on time. Storing only a number and assuming
 * "breach = above" would silently never fire for exactly the indicators that give early warning,
 * which are the ones worth having thresholds on at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_kpi_thresholds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            // Null means the whole company; a value narrows the threshold to one site, because a
            // shaft and a head office have no business sharing an injury-rate target.
            $table->unsignedBigInteger('site_id')->nullable()->index();

            /** The metric id as the pack defines it — 'difr', 'ltifr', 'trifr', or a leading key. */
            $table->string('metric', 60)->index();

            /** ABOVE for lagging indicators, BELOW for leading ones. */
            $table->string('direction', 10)->default('ABOVE');

            $table->decimal('threshold_value', 12, 4);

            $table->string('severity', 20)->default('WARNING');
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // One threshold per metric per scope. Two contradictory limits on the same indicator
            // would make "has this breached?" unanswerable.
            $table->unique(['company_id', 'site_id', 'metric'], 'sheq_kpi_thresholds_scope_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE sheq_kpi_thresholds ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE sheq_kpi_thresholds ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE sheq_kpi_thresholds FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sheq_kpi_thresholds');
            DB::statement("CREATE POLICY tenant_isolation ON sheq_kpi_thresholds USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_kpi_thresholds');
    }
};
