<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The SHEQ audit log — principle P4, "everything is evidence. Assume a court will read it."
 *
 * SHEQ-DATA-1 requires append-only enforced by the database, not by convention: a trigger that
 * raises on UPDATE or DELETE, so an ORM bug, a console session or a compromised application role
 * cannot rewrite history. SHEQ-DATA-2 requires each row to carry
 * `hash = SHA256(prev_hash || canonical_json(row))`, forming a per-tenant chain that a verification
 * endpoint can walk to detect a break.
 *
 * The chain is per (company_id) rather than global: one tenant's writes must not interleave into
 * another's chain, or verifying one company would require reading every company's rows — and a
 * gap caused by another tenant's deletion would be indistinguishable from tampering.
 *
 * Hashing happens in the database (pgcrypto) rather than the application, so a row inserted by a
 * migration, a seeder or psql is chained on the same terms as one written by the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // USER | SYSTEM | AI_AGENT | INTEGRATION — P5 requires AI writes to be distinguishable.
            $table->string('actor_type', 20)->default('USER');

            $table->string('action', 80);
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');

            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('device_id', 120)->nullable();
            // Mandatory for overrides and deletions; the application enforces which actions require it.
            $table->text('reason')->nullable();

            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();

            $table->index(['company_id', 'id']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'occurred_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // ── Hash chain ────────────────────────────────────────────────────────────────────────
        // Canonical form is a fixed field order, not row_to_json: column order or a later ALTER
        // must never change the hash of rows already written.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sheq_audit_chain() RETURNS TRIGGER AS $$
            DECLARE
                previous CHAR(64);
                canonical TEXT;
            BEGIN
                SELECT hash INTO previous
                FROM sheq_audit_log
                WHERE company_id = NEW.company_id
                ORDER BY id DESC
                LIMIT 1;

                NEW.prev_hash := previous;

                canonical := concat_ws('|',
                    COALESCE(previous, ''),
                    NEW.company_id::text,
                    to_char(NEW.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
                    COALESCE(NEW.actor_id::text, ''),
                    NEW.actor_type,
                    NEW.action,
                    NEW.subject_type,
                    NEW.subject_id::text,
                    COALESCE(NEW.before::text, ''),
                    COALESCE(NEW.after::text, ''),
                    COALESCE(NEW.reason, '')
                );

                NEW.hash := encode(digest(canonical, 'sha256'), 'hex');
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER sheq_audit_chain_trg BEFORE INSERT ON sheq_audit_log
                       FOR EACH ROW EXECUTE FUNCTION sheq_audit_chain()');

        // Serialise appends per tenant. Without this, two concurrent inserts can read the same
        // `previous` and fork the chain — verification would then fail on honest data.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sheq_audit_serialise() RETURNS TRIGGER AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('sheq_audit_log'), NEW.company_id::int);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER sheq_audit_serialise_trg BEFORE INSERT ON sheq_audit_log
                       FOR EACH ROW EXECUTE FUNCTION sheq_audit_serialise()');

        // ── Append-only (SHEQ-DATA-1) ─────────────────────────────────────────────────────────
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sheq_audit_append_only() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'sheq_audit_log is append-only (SHEQ-DATA-1): % rejected', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER sheq_audit_no_update BEFORE UPDATE ON sheq_audit_log
                       FOR EACH ROW EXECUTE FUNCTION sheq_audit_append_only()');
        DB::statement('CREATE TRIGGER sheq_audit_no_delete BEFORE DELETE ON sheq_audit_log
                       FOR EACH ROW EXECUTE FUNCTION sheq_audit_append_only()');
        // TRUNCATE bypasses row triggers entirely, so it needs its own statement-level guard.
        DB::statement('CREATE TRIGGER sheq_audit_no_truncate BEFORE TRUNCATE ON sheq_audit_log
                       FOR EACH STATEMENT EXECUTE FUNCTION sheq_audit_append_only()');

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
        DB::statement("ALTER TABLE sheq_audit_log ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement('ALTER TABLE sheq_audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sheq_audit_log FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sheq_audit_log');
        DB::statement("CREATE POLICY tenant_isolation ON sheq_audit_log USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['sheq_audit_no_truncate', 'sheq_audit_no_delete', 'sheq_audit_no_update',
                'sheq_audit_serialise_trg', 'sheq_audit_chain_trg'] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON sheq_audit_log");
            }
            DB::statement('DROP FUNCTION IF EXISTS sheq_audit_append_only()');
            DB::statement('DROP FUNCTION IF EXISTS sheq_audit_serialise()');
            DB::statement('DROP FUNCTION IF EXISTS sheq_audit_chain()');
        }

        Schema::dropIfExists('sheq_audit_log');
    }
};
