<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 of Supplier Due Diligence: the four tables everything else is built on.
 *
 * The shape here is the whole point of the phase. Today a supplier's compliance state lives in
 * columns on `suppliers` and in a single `supplier_screenings` row that each screening overwrites,
 * so the system can say "this supplier is tax compliant" but not "who said so, when, and what did
 * they actually say". That makes continuous monitoring impossible: you cannot detect a change
 * against a record that keeps only the present.
 *
 * So every check ever run is an immutable observation in `supplier_verifications`, and the
 * artefact behind it is chained into `dd_evidence_entries`. Nothing overwrites; the current state
 * is the newest observation, and history is the rest.
 *
 *  verification_providers          global catalogue — what kinds of check exist and who can answer
 *  company_verification_providers  per-tenant credentials & cadence (each customer licenses their own)
 *  supplier_verifications          the observation log — dated, sourced, field-level
 *  dd_evidence_entries             append-only hash-chained evidence, same protections as the
 *                                  Procurement Governance audit ledger
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The catalogue ────────────────────────────────────────────────────
        // Global, like `plans` and `sa_tax_years`: one list of providers for the installation, with
        // no company_id and no row-level security. What a tenant *does* with a provider is
        // per-tenant; which providers exist is not.
        Schema::create('verification_providers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 60)->unique();               // manual | cipc | sars_tcs | avs_bank | …
            $t->string('name', 150);
            $t->string('driver', 60);                       // manual | http_json — which connector class runs it
            $t->string('category', 40);                     // government | commercial | intelligence
            $t->char('country', 2)->nullable();             // ZA; null = country-agnostic
            $t->json('capabilities');                       // the check types this provider can answer
            $t->text('access_note')->nullable();            // what authorisation the customer needs
            $t->boolean('requires_credentials')->default(true);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        // ── Per-tenant configuration ─────────────────────────────────────────
        // Credentials are the tenant's own — the same reasoning as `payment_gateways`, and they are
        // encrypted at rest for the same reason. A provider row that exists but is not enabled is
        // not a silent pass: the engine reports the check as unavailable.
        Schema::create('company_verification_providers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('provider_code', 60);
            $t->boolean('is_enabled')->default(false);
            $t->string('mode', 10)->default('live');        // live | test
            $t->text('credentials')->nullable();            // encrypted:array
            $t->json('enabled_checks')->nullable();         // null = every capability the provider has
            $t->json('settings')->nullable();               // endpoints, field maps, cadence overrides

            // Operational truth for the Data Source Registry screen: is this thing actually working?
            $t->timestamp('last_ok_at')->nullable();
            $t->timestamp('last_error_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->unsignedInteger('monthly_quota')->nullable();   // null = uncapped
            $t->unsignedInteger('calls_this_month')->default(0);
            $t->string('quota_month', 7)->nullable();           // YYYY-MM — the month the counter belongs to
            $t->unsignedBigInteger('configured_by')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'provider_code'], 'company_verification_provider_unique');
        });

        // ── The observation log ──────────────────────────────────────────────
        Schema::create('supplier_verifications', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();

            // The subject of the check. A company check is about the supplier; a director screening
            // is about a person, and the same person recurs across suppliers.
            $t->string('subject_type', 30)->default('supplier');   // supplier | person
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('subject_label', 190)->nullable();          // who/what this was, as at the check

            $t->string('check_type', 60);                          // company.registry | tax.compliance_status | …
            $t->string('provider_code', 60);
            $t->string('status', 20);                              // verified | mismatch | not_found | unavailable | manual_review | error
            $t->unsignedTinyInteger('confidence')->nullable();      // 0-100, where the provider expresses one

            // What we were told, what the source said, and the field-by-field comparison. Keeping
            // all three is what lets a screen show "supplier says ABC Holdings, registry says ABC
            // Trading" a year later, rather than just "mismatch".
            $t->json('claimed')->nullable();
            $t->json('observed')->nullable();
            $t->json('field_matches')->nullable();

            $t->string('reference', 120)->nullable();              // TCS PIN, CSD number, provider ref
            $t->string('summary', 500)->nullable();
            $t->text('note')->nullable();                          // a person's own words, for manual checks
            $t->decimal('cost', 10, 2)->nullable();                // what the pull cost, where metered

            $t->unsignedBigInteger('checked_by')->nullable();      // null = system/scheduled
            $t->timestamp('checked_at');
            $t->timestamp('valid_until')->nullable();              // when this answer goes stale

            $t->timestamps();

            $t->index(['company_id', 'supplier_id', 'check_type', 'checked_at'], 'supplier_verifications_current_idx');
            $t->index(['company_id', 'status'], 'supplier_verifications_status_idx');
            $t->index(['company_id', 'subject_type', 'subject_id'], 'supplier_verifications_subject_idx');
        });

        // ── Evidence ledger ──────────────────────────────────────────────────
        // Same two protections as procgov_audit_events, for the same reason: a due-diligence
        // decision has to be explainable years later, and evidence the application can quietly
        // edit is not evidence. The trigger stops anything reachable through the app; the hash
        // chain makes tampering detectable by someone who could drop the trigger.
        Schema::create('dd_evidence_entries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('sequence');            // per tenant, gap-free
            $t->timestamp('occurred_at');

            $t->unsignedBigInteger('supplier_id')->nullable()->index();
            $t->string('subject_type', 30)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();

            $t->string('action', 60);                      // verification.recorded | provider.configured | …
            $t->string('check_type', 60)->nullable();
            $t->string('provider_code', 60)->nullable();
            $t->string('status', 20)->nullable();

            // Who looked — captured as they were at the time.
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_type', 20)->default('user'); // user | system | integration
            $t->string('actor_label', 190)->nullable();
            $t->string('actor_role', 120)->nullable();

            $t->string('summary', 500)->nullable();
            $t->json('claimed')->nullable();
            $t->json('observed')->nullable();
            $t->json('payload')->nullable();               // the raw provider answer, as received

            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('route', 190)->nullable();

            $t->char('previous_hash', 64)->nullable();     // null only for a tenant's first entry
            $t->char('hash', 64);

            $t->timestamp('created_at')->nullable();       // no updated_at: written once, never touched

            $t->unique(['company_id', 'sequence'], 'dd_evidence_seq_unique');
            $t->index(['company_id', 'supplier_id', 'occurred_at'], 'dd_evidence_supplier_idx');
        });

        // The ledger's per-tenant counter. A separate row to lock is the simplest correct way to
        // hand out gap-free sequence numbers: two concurrent writers cannot both read the same
        // predecessor, because the second one waits on this row.
        Schema::create('dd_evidence_sequences', function (Blueprint $t) {
            $t->unsignedBigInteger('company_id')->primary();
            $t->unsignedBigInteger('next_sequence')->default(1);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['company_verification_providers', 'supplier_verifications', 'dd_evidence_entries'] as $table) {
            $this->enforceTenantIsolation($table);
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION dd_evidence_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'dd_evidence_entries is append-only: % is not permitted (id=%)', TG_OP, OLD.id
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER dd_evidence_append_only
            BEFORE UPDATE OR DELETE ON dd_evidence_entries
            FOR EACH ROW EXECUTE FUNCTION dd_evidence_append_only()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS dd_evidence_append_only ON dd_evidence_entries');
        DB::statement('DROP FUNCTION IF EXISTS dd_evidence_append_only()');
        Schema::dropIfExists('dd_evidence_sequences');
        Schema::dropIfExists('dd_evidence_entries');
        Schema::dropIfExists('supplier_verifications');
        Schema::dropIfExists('company_verification_providers');
        Schema::dropIfExists('verification_providers');
    }

    /**
     * Row-level security on the tenant column, plus a default so a row written by a query that
     * forgot company_id still lands in the right tenant instead of nowhere. Mirrors what
     * tenant_scope_all_modules did for the existing estate.
     */
    private function enforceTenantIsolation(string $table): void
    {
        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
    }
};
