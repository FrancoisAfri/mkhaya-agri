<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Universal Audit Trail (§7) — one ledger every capability in this module writes to.
 *
 * Why a module-owned table rather than the platform's shared `activity_log`: that table is
 * *rewritten* by the POPIA anonymisation and retention-purge commands, so it cannot be made
 * immutable without breaking those flows. Procurement evidence has to survive an audit years later
 * with a demonstrable chain, so it gets its own append-only table — the same reasoning, and the
 * same shape, as the Project Management audit log.
 *
 * Two protections, because they defend against different things:
 *
 *  - A BEFORE UPDATE OR DELETE trigger stops anything reachable through the application from
 *    editing history, including a mistaken migration or a console command.
 *  - A per-tenant hash chain (`sequence`, `previous_hash`, `hash`) makes tampering *detectable*
 *    even by someone with direct database access who could drop the trigger: altering or removing
 *    any row breaks every hash after it, and `procgov:verify-ledger` reports where.
 *
 * `sequence` is per company and gap-free, so a missing row is visible as a hole rather than
 * inferred from timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procgov_audit_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // Position in this tenant's chain. Claimed under the same row lock as the reference
            // sequences, so two concurrent writers cannot take the same number.
            $t->unsignedBigInteger('sequence');

            $t->timestamp('occurred_at');

            // Who — captured as it was at the time. A person's role changes; what they held when
            // they approved something does not.
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_type', 20)->default('user');   // user | system | integration
            $t->string('actor_label', 190)->nullable();
            $t->string('actor_role', 120)->nullable();

            // What
            $t->string('action', 60);                        // created | submitted | approved | scored | …
            $t->string('module', 60)->default('procurement_governance');
            $t->string('capability', 40)->nullable();        // planning | tender_evaluation | deviations | …

            // Which record
            $t->string('entity_type', 80);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->uuid('entity_uuid')->nullable();
            $t->string('entity_reference', 80)->nullable();
            $t->unsignedBigInteger('record_id')->nullable();  // the 360 spine, when there is one

            $t->string('summary', 500)->nullable();          // "approved Deviation DEV-2026-0042"

            // Before/after — only the fields that changed, so a wide update does not store a wall
            // of unchanged values nobody will read.
            $t->json('before')->nullable();
            $t->json('after')->nullable();

            // Decision context (§7): approvals carry an outcome and a reason, and the reason is
            // frequently the only thing an auditor actually wants.
            $t->string('decision', 30)->nullable();          // approved | rejected | recused | escalated | …
            $t->text('comment')->nullable();

            // Where from
            $t->string('ip', 45)->nullable();                // 45 = IPv6
            $t->string('user_agent', 255)->nullable();
            $t->string('session_id', 64)->nullable();
            $t->string('request_id', 64)->nullable();
            $t->string('route', 190)->nullable();

            // Chain
            $t->char('previous_hash', 64)->nullable();       // null only for a tenant's first event
            $t->char('hash', 64);

            // No updated_at: a row is written once and never touched again.
            $t->timestamp('created_at')->nullable();

            $t->unique(['company_id', 'sequence'], 'procgov_audit_seq_unique');
            $t->index(['company_id', 'entity_type', 'entity_id'], 'procgov_audit_entity_idx');
            $t->index(['company_id', 'record_id', 'occurred_at'], 'procgov_audit_record_idx');
            $t->index(['company_id', 'actor_id', 'occurred_at'], 'procgov_audit_actor_idx');
            $t->index(['company_id', 'occurred_at'], 'procgov_audit_time_idx');
            $t->index(['company_id', 'action'], 'procgov_audit_action_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
        DB::statement("ALTER TABLE procgov_audit_events ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement('ALTER TABLE procgov_audit_events ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE procgov_audit_events FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON procgov_audit_events');
        DB::statement("CREATE POLICY tenant_isolation ON procgov_audit_events USING ({$policy}) WITH CHECK ({$policy})");

        // Append-only, enforced where it cannot be argued with. An audit trail the application can
        // quietly edit is not evidence of anything.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION procgov_audit_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'procgov_audit_events is append-only: % is not permitted (id=%)', TG_OP, OLD.id
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER procgov_audit_append_only
            BEFORE UPDATE OR DELETE ON procgov_audit_events
            FOR EACH ROW EXECUTE FUNCTION procgov_audit_append_only()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS procgov_audit_append_only ON procgov_audit_events');
        DB::statement('DROP FUNCTION IF EXISTS procgov_audit_append_only()');
        Schema::dropIfExists('procgov_audit_events');
    }
};
