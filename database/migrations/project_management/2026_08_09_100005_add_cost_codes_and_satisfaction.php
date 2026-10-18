<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost codes (§8) and client satisfaction (§1).
 *
 * ── Cost codes are a string, not a table ────────────────────────────────────
 *
 * Every organisation already has a cost-code scheme, and it lives in their accounting system. A PM
 * table of codes would be a second list that drifts from the real one within a quarter, and the
 * first symptom would be costs coded to something Finance does not recognise. So PM records the
 * code as text alongside the cost, and the tenant's own list — wherever it lives — stays the source
 * of truth. `pm_cost_codes` exists only so a tenant *without* an accounting system has somewhere to
 * keep theirs, and the field accepts anything either way.
 *
 * ── Client satisfaction is measured, not inferred ───────────────────────────
 *
 * §1 asks for a satisfaction score on the dashboard. The only honest way to have one is to ask the
 * client, so `pm_client_ratings` records an actual rating a client gave on the portal, with the
 * period it refers to. Deriving "satisfaction" from delivery metrics would be inventing a client's
 * opinion from data they never saw.
 */
return new class extends Migration
{
    private const TABLES = ['pm_cost_codes', 'pm_client_ratings'];

    public function up(): void
    {
        Schema::create('pm_cost_codes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('code', 40);
            $t->string('name', 190);
            $t->string('category', 40)->nullable();      // labour, materials, plant …
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pm_client_ratings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            // 1–5. Deliberately coarse: a ten-point scale produces a mean nobody can interpret,
            // and clients answer a five-point one honestly.
            $t->unsignedTinyInteger('score');
            $t->text('comment')->nullable();
            // What the rating is *about*, so a score given in March is not read as an opinion of
            // the job in September.
            $t->date('period_month');
            $t->timestamps();

            // One rating per client per month per project. Asking more often trains people to
            // ignore the ask.
            $t->unique(['project_id', 'user_id', 'period_month'], 'pm_client_ratings_unique');
        });

        // The code goes on the cost, where it is needed to post the entry.
        Schema::table('pm_cost_entries', fn (Blueprint $t) => $t->string('cost_code', 40)->nullable()->index());
        // …and on the bill item, because a priced bill is coded before any cost exists.
        Schema::table('pm_boq_items', fn (Blueprint $t) => $t->string('cost_code', 40)->nullable());

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        Schema::table('pm_boq_items', fn (Blueprint $t) => $t->dropColumn('cost_code'));
        Schema::table('pm_cost_entries', fn (Blueprint $t) => $t->dropColumn('cost_code'));

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
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
