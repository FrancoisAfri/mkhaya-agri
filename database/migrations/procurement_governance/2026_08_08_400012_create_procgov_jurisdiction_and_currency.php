<?php

use Database\Seeders\ProcurementGovernanceModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jurisdiction and currency: what framework a tender is run under, and in what money its bids are
 * compared (§1.6, §5.3).
 *
 * Two defects sit behind this migration, and they are the same defect twice.
 *
 * The scoring engine picks the lowest bid with min('bid_amount') and measures every other bid
 * against it. `bid_amount` carries a currency column that nothing reads, so a 900,000 BWP bid is
 * treated as cheaper than a 1,000,000 ZAR bid on the arithmetic alone. In a single-country
 * installation that never surfaces. Across South Africa, Eswatini and Botswana it silently awards
 * tenders to the wrong bidder, and the audit trail faithfully records the wrong answer.
 *
 * The second is preference. Bidders carry `bbbee_level` and `preference_points`, and the points are
 * whatever someone typed — so the most disputed number in a Southern African tender is unverifiable
 * and jurisdiction-blind. Eswatini and Botswana do not use B-BBEE at all.
 *
 * The response is deliberately not to embed three countries' statutes. The PPPFA *price* formula is
 * stable and is implemented in the engine; the *points allocation* has been set by each organ of
 * state as its own specific goals since the 2022 Preferential Procurement Regulations, and Eswatini
 * and Botswana operate different schemes again. So the framework is configuration — a named
 * framework per jurisdiction with a table of bands — seeded with a starting point that an
 * accounting officer confirms, exactly as §5.3 argues for every other rule in this module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procgov_settings', function (Blueprint $t) {
            // ISO 3166-1 alpha-2. The tenant's default; a tender may be run under another.
            $t->char('jurisdiction', 2)->default('ZA')->after('currency');

            /*
             * Which day's exchange rate converts a bid for comparison.
             *
             * CLOSING is the default because it is the only basis under which every bid in a tender
             * is measured at the same instant. Converting each bid at its own submission date means
             * two identical offers rank differently for having been submitted on different days,
             * which is indefensible in an award dispute; converting at evaluation time means the
             * ranking moves every time the page is opened.
             */
            $t->string('fx_rate_basis', 20)->default('CLOSING')->after('jurisdiction');
            $t->string('fx_rate_type', 20)->default('spot')->after('fx_rate_basis');
        });

        Schema::table('procgov_tender_reviews', function (Blueprint $t) {
            // Null inherits the tenant default, so existing tenders keep behaving as they do now.
            $t->char('jurisdiction', 2)->nullable()->after('currency');
            $t->string('preference_framework_code', 40)->nullable()->after('preference_weight');
        });

        Schema::table('procgov_bidders', function (Blueprint $t) {
            // The comparable figure. Kept beside the original rather than replacing it: the bid was
            // made in its own currency and the record has to keep saying so.
            $t->decimal('bid_amount_base', 18, 2)->nullable()->after('currency');
            $t->decimal('fx_rate', 18, 8)->nullable()->after('bid_amount_base');
            $t->date('fx_rate_date')->nullable()->after('fx_rate');
            $t->string('fx_rate_source', 60)->nullable()->after('fx_rate_date');
        });

        Schema::create('procgov_preference_frameworks', function (Blueprint $t) {
            $t->id();
            $t->uuid()->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('code', 40);
            $t->string('name', 190);
            $t->char('jurisdiction', 2);

            // POINTS: bands award points out of max_points (South Africa's shape).
            // MARGIN: a percentage price advantage applied to qualifying bidders instead of points,
            // which is how citizen and local preference schemes in the region generally work.
            $t->string('basis', 20)->default('POINTS');
            $t->decimal('max_points', 6, 2)->default(20);

            // The statutory price/preference split, e.g. 80/20 or 90/10. Held here so a tender
            // inherits it rather than every officer remembering which split the value band requires.
            $t->decimal('price_weight', 6, 2)->nullable();
            $t->decimal('preference_weight', 6, 2)->nullable();

            // What still needs a compliance officer's eye. Shown on the settings screen.
            $t->text('notes')->nullable();
            $t->boolean('is_verified')->default(false);
            $t->boolean('is_active')->default(true);

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'code'], 'procgov_pref_framework_unique');
            $t->index(['company_id', 'jurisdiction', 'is_active'], 'procgov_pref_framework_lookup');
        });

        Schema::create('procgov_preference_bands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('framework_id')->constrained('procgov_preference_frameworks')->cascadeOnDelete();

            $t->string('label', 190);

            /*
             * Which field on the bidder decides the band, and the value that qualifies.
             *
             * Kept to equality on a named field rather than a general expression language: a
             * preference table is read aloud in award disputes, and "B-BBEE level 1 scores 20" has
             * to be legible to someone who does not write code. The rules engine already exists for
             * anything genuinely conditional.
             */
            $t->string('match_field', 60)->default('bbbee_level');
            $t->string('match_value', 60);

            $t->decimal('points', 6, 2)->default(0);

            // Used when basis = MARGIN: the percentage advantage a qualifying bid receives.
            $t->decimal('margin_pct', 6, 2)->nullable();

            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['company_id', 'framework_id'], 'procgov_pref_band_lookup');
        });

        $this->applyTenantRls(['procgov_preference_frameworks', 'procgov_preference_bands']);

        /*
         * Seed the frameworks here as well as in the module seeder.
         *
         * The seeder is invoked by the module-registration migration, which runs before this one on
         * a fresh install — so its framework block finds no table, skips, and a new installation
         * comes up with an empty preference catalogue and no indication anything is missing. Doing
         * it here too costs nothing on an upgrade (the seeder is idempotent) and is the difference
         * between a working install and a silently incomplete one.
         */
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            ProcurementGovernanceModuleSeeder::seedPreferenceFrameworksFor((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_preference_bands');
        Schema::dropIfExists('procgov_preference_frameworks');

        Schema::table('procgov_bidders', function (Blueprint $t) {
            $t->dropColumn(['bid_amount_base', 'fx_rate', 'fx_rate_date', 'fx_rate_source']);
        });

        Schema::table('procgov_tender_reviews', function (Blueprint $t) {
            $t->dropColumn(['jurisdiction', 'preference_framework_code']);
        });

        Schema::table('procgov_settings', function (Blueprint $t) {
            $t->dropColumn(['jurisdiction', 'fx_rate_basis', 'fx_rate_type']);
        });
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
