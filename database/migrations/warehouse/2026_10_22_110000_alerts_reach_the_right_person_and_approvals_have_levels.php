<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-190 to WMS-192: notifications and the approval workflow.
 *
 * A notification is its own record, separate from the transaction it is about: the log says who was
 * told what, how often the same unresolved thing recurred, whether each channel delivered, and when
 * the underlying problem went away -- without touching the order, lot or task itself. Rules say who
 * hears about what, how quietly and when it escalates.
 *
 * Approvals: a policy per kind of request gives the levels a request passes through by value; each
 * level is a step with its own deadline, decided by a different person, possibly a delegate.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['wms_notification_rules', 'wms_notification_log', 'wms_approval_policies', 'wms_approval_steps', 'wms_delegations'];

    public function up(): void
    {
        Schema::create('wms_notification_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('event_type', 24);
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->cascadeOnDelete();
            $t->boolean('is_active')->default(true);
            $t->jsonb('recipient_permissions');
            $t->jsonb('recipient_user_ids')->nullable();
            $t->boolean('notify_responsible')->default(true);
            $t->jsonb('channels');
            $t->jsonb('thresholds')->nullable();
            $t->unsignedInteger('suppress_hours')->default(24);
            $t->unsignedInteger('escalate_after_hours')->nullable();
            $t->jsonb('escalate_to_permissions')->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });
        Schema::create('wms_notification_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('rule_id')->nullable()->constrained('wms_notification_rules')->nullOnDelete();
            $t->string('event_type', 24);
            $t->string('dedupe_key', 160);
            $t->foreignId('warehouse_id')->nullable()->constrained('fin_warehouses')->nullOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedSmallInteger('level')->default(0); // 0 notice, 1 escalation
            $t->string('reason', 12); // PERMISSION|NAMED|RESPONSIBLE|ESCALATION
            $t->string('title', 200);
            $t->text('message');
            $t->string('url', 300)->nullable();
            $t->jsonb('delivery'); // channel => SENT|FAILED|PENDING
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('last_error', 500)->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at');
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
            $t->index(['company_id', 'dedupe_key', 'recipient_id', 'level'], 'wms_notify_key_idx');
            $t->index(['company_id', 'event_type', 'resolved_at'], 'wms_notify_open_idx');
        });
        Schema::create('wms_approval_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('subject_type', 16); // ADJUSTMENT|REVALUATION
            $t->jsonb('levels'); // [{name, permission, min_value}]
            $t->unsignedInteger('due_hours')->default(24);
            $t->jsonb('escalate_to_permissions')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->unique(['company_id', 'subject_type'], 'wms_approval_policy_uq');
        });
        Schema::create('wms_approval_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->string('subject_type', 16);
            $t->unsignedBigInteger('subject_id');
            $t->string('subject_ref', 40);
            $t->unsignedSmallInteger('level');
            $t->string('name', 80);
            $t->string('permission', 60);
            $t->decimal('value', 16, 2)->default(0);
            $t->string('status', 10); // PENDING|APPROVED|REJECTED|SKIPPED
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('due_at');
            $t->timestampTz('escalated_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('on_behalf_of')->nullable()->constrained('users')->nullOnDelete(); // a delegate acting for this person
            $t->timestampTz('decided_at')->nullable();
            $t->string('note', 1000)->nullable();
            $t->timestampsTz();
            $t->unique(['subject_type', 'subject_id', 'level'], 'wms_approval_step_uq');
        });
        Schema::create('wms_delegations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $t->jsonb('subject_types')->nullable(); // NULL: every kind
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('reason', 300);
            $t->timestampTz('revoked_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            // One rule per event per warehouse, and one company-wide rule per event: NULLs never collide in a plain unique key.
            DB::statement('CREATE UNIQUE INDEX wms_notify_rule_wh_uq ON wms_notification_rules (company_id, event_type, warehouse_id) WHERE warehouse_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX wms_notify_rule_all_uq ON wms_notification_rules (company_id, event_type) WHERE warehouse_id IS NULL');
            DB::statement("ALTER TABLE wms_approval_steps ADD CONSTRAINT wms_approval_step_status_chk CHECK (status IN ('PENDING','APPROVED','REJECTED','SKIPPED'))");
            DB::statement('ALTER TABLE wms_delegations ADD CONSTRAINT wms_delegation_self_chk CHECK (from_user_id <> to_user_id AND ends_at > starts_at)');
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TENANT_TABLES as $table) {
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
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
