<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-203 and WMS-220: the warehouse audit ledger and company settings for retention.
 *
 * Each audit event carries the hash of the one before it in its company, so removing or editing a
 * row breaks the chain where it happened. Rows cannot be updated at all, and deleted only by the
 * retention command -- which sets a session flag the trigger checks -- and only once they are older
 * than the company's retention period; the oldest remaining row then anchors the chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->unsignedBigInteger('sequence');
            $t->timestampTz('occurred_at');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('actor_label', 160)->nullable();
            $t->string('actor_type', 10); // USER|TOKEN|SYSTEM
            $t->string('action', 60);
            $t->string('entity_type', 32);
            $t->string('entity_ref', 80)->nullable();
            $t->string('summary', 500);
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 300)->nullable();
            $t->char('previous_hash', 64)->nullable();
            $t->char('hash', 64);
            $t->timestampTz('created_at')->nullable();
            $t->unique(['company_id', 'sequence'], 'wms_audit_seq_uq');
            $t->index(['company_id', 'entity_type', 'entity_ref'], 'wms_audit_entity_idx');
            $t->index(['company_id', 'action', 'occurred_at'], 'wms_audit_action_idx');
        });
        Schema::create('wms_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->unique()->constrained('companies')->cascadeOnDelete();
            $t->jsonb('settings');
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION wms_audit_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE keep_days integer;
                BEGIN
                    IF TG_OP = 'DELETE' AND current_setting('wms.audit_purge', true) = 'on' THEN
                        SELECT COALESCE((settings->>'audit_retention_days')::integer, 2555) INTO keep_days FROM wms_settings WHERE company_id = OLD.company_id;
                        IF OLD.occurred_at < now() - make_interval(days => COALESCE(keep_days, 2555)) THEN
                            RETURN OLD;
                        END IF;
                    END IF;
                    RAISE EXCEPTION 'wms_audit_events is append-only: % is not permitted (id=%)', TG_OP, OLD.id USING ERRCODE = 'restrict_violation';
                END $$
            SQL);
            DB::statement('CREATE TRIGGER wms_audit_append_only BEFORE UPDATE OR DELETE ON wms_audit_events FOR EACH ROW EXECUTE FUNCTION wms_audit_append_only()');
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (['wms_audit_events', 'wms_settings'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS wms_audit_append_only ON wms_audit_events');
            DB::statement('DROP FUNCTION IF EXISTS wms_audit_append_only()');
        }
        Schema::dropIfExists('wms_settings');
        Schema::dropIfExists('wms_audit_events');
    }
};
