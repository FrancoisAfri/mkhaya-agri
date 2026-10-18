<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The site safety risk score (§16).
 *
 * One table and one settings column, following the contractor score exactly — same weight
 * storage, same stored breakdown, same reasoning about why the breakdown is stored rather than
 * recomputed. A figure that explains itself with today's data is a figure that rewrites its own
 * history, and the whole value of a trend is that last month's number still says what it said
 * last month.
 *
 * One thing is deliberately NOT the same, and it is the thing most likely to cause a mistake:
 * **this score runs the other way round.** The contractor score is 0-100 where ninety is good.
 * This one is 0-100 where eighty-two is dangerous, because that is what §16 asks for — "Site
 * Safety Risk: 82/100 — HIGH". Two scores in one module pointing in opposite directions is a trap
 * whichever way they are named, so the band names here all carry the word RISK and the column is
 * `risk_score` rather than `score`. A number labelled 82 should never be ambiguous about whether
 * somebody is being praised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_site_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sheq_sites')->cascadeOnDelete();

            // YYYY-MM. The same period key the contractor score uses, so the two can be read
            // side by side without a date conversion in the middle.
            $table->string('period', 7);

            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->string('risk_band', 20)->nullable();

            // The counts behind the number, one column each, so a site can be queried on a single
            // signal without unpacking JSON — "show me every site with an overdue CAPA" is a
            // question somebody asks.
            $table->unsignedInteger('incidents')->default(0);
            $table->unsignedInteger('high_potential_incidents')->default(0);
            $table->unsignedInteger('near_misses')->default(0);
            $table->unsignedInteger('hazards')->default(0);
            $table->unsignedInteger('inspection_failures')->default(0);
            $table->unsignedInteger('unsafe_observations')->default(0);
            $table->unsignedInteger('contractor_compliance_gaps')->default(0);
            $table->unsignedInteger('overdue_actions')->default(0);
            $table->unsignedInteger('permit_violations')->default(0);
            $table->unsignedInteger('competency_failures')->default(0);
            $table->unsignedInteger('ncrs')->default(0);
            $table->unsignedInteger('repeated_control_failures')->default(0);

            // Exposure, so the score is a rate rather than a headcount. A site with forty
            // incidents and nine thousand people on it is safer than one with four and twelve.
            $table->unsignedInteger('people_on_site')->default(0);
            $table->unsignedBigInteger('hours_worked')->nullable();

            /*
             * Stored, not recomputed. §16 asks for the score to be explainable, and an
             * explanation that is regenerated on read explains the present rather than the month
             * it is labelled with — so the figure and its reasons drift apart silently.
             */
            $table->jsonb('breakdown')->nullable();
            $table->jsonb('weights')->nullable();

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'site_id', 'period'], 'sheq_site_risk_period_uniq');
            $table->index(['company_id', 'period', 'risk_score'], 'sheq_site_risk_board_idx');
        });

        Schema::table('sheq_settings', function (Blueprint $table) {
            // Alongside `contractor_score_weights`, same shape, same reasons.
            $table->jsonb('site_risk_weights')->nullable()->after('contractor_score_weights');
        });

        $this->applyTenantRls(['sheq_site_risk_scores']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_site_risk_scores');

        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->dropColumn('site_risk_weights');
        });
    }

    /** The same row-level security every other SHEQ table carries. */
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
