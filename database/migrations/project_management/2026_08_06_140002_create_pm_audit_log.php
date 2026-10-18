<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PM audit log (PM-1024): actor, timestamp, entity, before/after diff, IP and request ID for every
 * create, update and delete — append-only, enforced by the database.
 *
 * Deliberately separate from the platform's shared `activity_log`. That table is written by every
 * module and is *rewritten* by the POPIA anonymisation and retention-purge commands; making it
 * immutable would break those flows. A PM-owned table can be immutable without that collateral.
 *
 * Erasure is still possible here, but as an append-a-correction operation rather than an UPDATE —
 * the same discipline the Evidence Ledger uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_audit_log', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            $t->string('entity_type', 60);              // pm_project, pm_task, pm_project_member, …
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->uuid('entity_uuid')->nullable();        // the public id, so a log row survives a purge
            $t->string('action', 20);                   // created | updated | deleted | restored | <domain verb>

            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_type', 20)->default('user');   // user | system | integration | device
            $t->string('actor_label', 190)->nullable();      // who it was at the time, not who they are now

            // Only the fields that changed, so a wide update does not store a wall of unchanged
            // values that nobody will ever read.
            $t->json('before')->nullable();
            $t->json('after')->nullable();

            $t->string('ip', 45)->nullable();           // 45 = IPv6
            $t->string('request_id', 64)->nullable();   // correlates the row with the request log
            $t->string('user_agent', 255)->nullable();
            $t->string('route', 190)->nullable();

            // No updated_at: a row is written once and never touched again.
            $t->timestamp('created_at')->nullable();

            $t->index(['company_id', 'entity_type', 'entity_id']);
            $t->index(['company_id', 'actor_id', 'created_at']);
            $t->index(['company_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
        DB::statement("ALTER TABLE pm_audit_log ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement('ALTER TABLE pm_audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE pm_audit_log FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON pm_audit_log');
        DB::statement("CREATE POLICY tenant_isolation ON pm_audit_log USING ({$policy}) WITH CHECK ({$policy})");

        // Append-only, enforced where it cannot be argued with. An audit trail that the application
        // can quietly edit is not evidence of anything.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pm_audit_log_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'pm_audit_log is append-only: % is not permitted (id=%)', TG_OP, OLD.id
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER pm_audit_log_append_only
            BEFORE UPDATE OR DELETE ON pm_audit_log
            FOR EACH ROW EXECUTE FUNCTION pm_audit_log_append_only()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS pm_audit_log_append_only ON pm_audit_log');
        DB::statement('DROP FUNCTION IF EXISTS pm_audit_log_append_only()');
        Schema::dropIfExists('pm_audit_log');
    }
};
