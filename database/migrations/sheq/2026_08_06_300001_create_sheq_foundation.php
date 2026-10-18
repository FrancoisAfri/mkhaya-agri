<?php

use App\Shared\Database\OptionalStatement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHEQ foundation: the site hierarchy every operational record hangs off, and the module's own
 * settings.
 *
 * SHEQ-TEN-2 requires a hierarchy of arbitrary depth (Organisation → Region → Site → Area →
 * Sub-area) with every operational record attributable to a node. That is `ltree`: subtree queries
 * ("everything at or below this site") are an index lookup rather than a recursive CTE, which
 * matters because SHEQ-TEN-3 evaluates access as (role × site subtree × sensitivity) on every read.
 *
 * Geometry uses PostGIS where the spec asks for it (SHEQ-TEN-2 geofence, and the GPS columns on
 * incidents and evidence added by later migrations). Both extensions are created here so the rest
 * of the module can assume them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->ensureExtension('ltree');
            $this->ensureExtension('postgis');
        }

        Schema::create('sheq_sites', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->string('code', 40);
            $table->string('name');
            // MINE | PLANT | OFFICE | PROJECT | DEPOT | REGION | ORGANISATION | AREA
            $table->string('site_type', 30)->default('SITE');

            // Which company unit this mirrors, when the tenant models sites as org units already.
            $table->foreignId('company_unit_id')->nullable()->constrained('company_units')->nullOnDelete();

            $table->string('timezone', 64)->default('Africa/Johannesburg');
            $table->boolean('is_active')->default(true);

            // Hours worked drives every frequency rate (SHEQ-DASH-4); recorded per site per period
            // in sheq_exposure_hours, not here.
            $table->string('external_reference')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'parent_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Materialised path. Maintained by the application (SiteHierarchy service) because a
            // trigger would fire per row on a subtree move and cost more than one bulk update.
            DB::statement('ALTER TABLE sheq_sites ADD COLUMN path LTREE');
            DB::statement('CREATE INDEX sheq_sites_path_gist ON sheq_sites USING GIST (path)');
            DB::statement('CREATE INDEX sheq_sites_path_btree ON sheq_sites USING BTREE (path)');

            // SHEQ-TEN-2 geofence, and SHEQ-INS-7 location validation reads it.
            DB::statement('ALTER TABLE sheq_sites ADD COLUMN geofence GEOGRAPHY(POLYGON,4326)');
            DB::statement('ALTER TABLE sheq_sites ADD COLUMN centre_point GEOGRAPHY(POINT,4326)');
            DB::statement('ALTER TABLE sheq_sites ADD COLUMN geofence_radius_m INTEGER');
            DB::statement('CREATE INDEX sheq_sites_geofence_gist ON sheq_sites USING GIST (geofence)');
        }

        // Per-company module configuration. One row per company; created on first access.
        Schema::create('sheq_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Reference prefixes — tenants recognise their own numbering (INC-2026-001234).
            $table->string('incident_prefix', 12)->default('INC');
            $table->string('hazard_prefix', 12)->default('HAZ');
            $table->string('action_prefix', 12)->default('ACT');
            $table->string('permit_prefix', 12)->default('PTW');
            $table->string('assessment_prefix', 12)->default('RA');
            $table->string('inspection_prefix', 12)->default('INSP');

            // SHEQ-HAZ-2: triage SLA, and SHEQ-HAZ-4 tracks time-to-acknowledge against it.
            $table->unsignedSmallInteger('hazard_triage_sla_hours')->default(24);

            // SHEQ-INC-3: anonymity is a tenant choice, disableable per site.
            $table->boolean('allow_anonymous_reporting')->default(true);

            // SHEQ-CAP-3 default effectiveness review interval after closure.
            $table->unsignedSmallInteger('capa_effectiveness_review_days')->default(90);

            // SHEQ-CAP-2: verification by someone other than the assignee, from this priority up.
            $table->string('capa_independent_verification_from', 20)->default('HIGH');

            $table->timestamps();
            $table->unique('company_id');
        });

        $this->applyTenantRls(['sheq_sites', 'sheq_settings']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_settings');
        Schema::dropIfExists('sheq_sites');
        // Extensions are left installed: other modules may have come to rely on them, and dropping
        // an extension cascades to every dependent object in the database.
    }

    /**
     * SHEQ-ADR-001: tenant isolation lives in the database. company_id defaults from the
     * app.company_id session variable; FORCE RLS binds even the table owner. A NULL session
     * variable (console, platform admin) disables the filter, which is how seeders and jobs work.
     */
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

    /**
     * Make sure a required Postgres extension is present, and say what to do when it is not.
     *
     * `CREATE EXTENSION` needs superuser rights, and the application's database user rarely has
     * them. On a fresh database that produced `SQLSTATE[42501]: permission denied to create
     * extension "postgis"` roughly nine hundred migrations in — a stack trace with no instruction,
     * after twelve minutes of work, and every migration behind this one silently never ran.
     *
     * The extension is genuinely required: `sheq_sites.geofence` is GEOGRAPHY(POLYGON,4326), and
     * skipping it would leave the schema quietly different from every other environment. So this
     * cannot be worked around — but it can be said plainly, with the exact command to run. The
     * migration is resumable, so a DBA runs one line and `migrate` picks up where it stopped.
     */
    private function ensureExtension(string $name): void
    {
        $installed = fn () => DB::table('pg_extension')->where('extname', $name)->exists();

        if ($installed()) {
            return;
        }

        /*
         * Guarded, so the connection is still usable if it fails.
         *
         * A failed statement aborts the Postgres transaction, and every query after it fails too —
         * including the `$installed()` re-check below. So an unguarded attempt replaced the careful
         * message this method exists to produce with a bare "current transaction is aborted", which
         * tells a DBA nothing about which extension to install.
         */
        $created = OptionalStatement::attempt(
            "create the {$name} extension",
            fn () => DB::statement("CREATE EXTENSION IF NOT EXISTS {$name}"),
            'SHEQ cannot be installed without it',
        );

        if (! $created) {
            // Another connection may have created it in the meantime.
            if ($installed()) {
                return;
            }

            $database = DB::getDatabaseName();

            /*
             * No exception to carry: `OptionalStatement::attempt` catches it, logs it and returns
             * false, which is what makes the connection usable enough to reach this message at
             * all. Referencing a `$e` here threw "Undefined variable $e" instead — so the one
             * method written to replace an unhelpful stack trace with an instruction produced an
             * unhelpful stack trace of its own, and a fresh installation stopped dead on the
             * first SHEQ migration with no idea why. Found by migrating an empty database.
             */
            throw new \RuntimeException(
                "SHEQ requires the Postgres \"{$name}\" extension, and this database user may not "
                ."create it.\n\n"
                ."Ask somebody with superuser rights to run:\n\n"
                ."    psql -d {$database} -c 'CREATE EXTENSION IF NOT EXISTS {$name};'\n\n"
                ."then run php artisan migrate again — it resumes from here.\n\n"
                .'The database\'s own reason is in the application log, against '
                ."\"optional database step skipped: create the {$name} extension\"."
            );
        }
    }
};
