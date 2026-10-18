<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow Management & Automation Engine — foundation.
 *
 * See docs/workflow-engine/01-gap-assessment-and-architecture.md. Three things are kept apart (PRS §27):
 *   definition  — workflow_definitions → immutable workflow_versions (the graph), workflow_forms → versions;
 *   execution   — workflow_instances pinned to a version, every node execution in workflow_node_runs,
 *                 human work in workflow_tasks, deferred work in workflow_timers / workflow_action_runs;
 *   the record  — referenced by subject_type/subject_id, never copied; its module changes it.
 *
 * Production has no queue worker: state changes commit in the request that causes them and anything
 * deferred is a row that `workflow:tick` picks up, so a restart loses nothing (§19).
 * Every tenant table carries company_id under the platform's row-level-security policy.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'workflow_settings', 'workflow_calendars', 'workflow_forms', 'workflow_form_versions',
        'workflow_definitions', 'workflow_versions', 'workflow_instances', 'workflow_node_runs',
        'workflow_tasks', 'workflow_task_assignments', 'workflow_timers', 'workflow_action_runs',
        'workflow_comments', 'workflow_delegations', 'workflow_drafts', 'workflow_events', 'workflow_audit_events',
    ];

    public function up(): void
    {
        Schema::create('workflow_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->unique();
            $t->jsonb('values')->default('{}');
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampsTz();
        });

        // §10: business hours and holidays. Public holidays come from public_holidays; extra days here.
        Schema::create('workflow_calendars', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('timezone', 60)->default('Africa/Johannesburg');
            $t->jsonb('work_days')->default('[1,2,3,4,5]');   // ISO weekday numbers
            $t->string('day_start', 5)->default('08:00');
            $t->string('day_end', 5)->default('17:00');
            $t->boolean('use_public_holidays')->default(true);
            $t->jsonb('extra_holidays')->default('[]');       // ["2026-12-24", …]
            $t->boolean('is_default')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });

        // §6: reusable forms, versioned so a running instance keeps the fields it started with.
        Schema::create('workflow_forms', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
        });

        Schema::create('workflow_form_versions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('form_id')->constrained('workflow_forms')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->jsonb('fields');                               // [{key,label,type,required,options,visible_if,…}]
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['form_id', 'version']);
        });

        Schema::create('workflow_definitions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('key', 80);                             // stable identifier, unique per company
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->string('category', 60)->nullable();
            $t->jsonb('tags')->default('[]');
            $t->string('module', 60)->default('generic');      // owning provider: generic | finance.claims | hr.leave | procurement.requisition
            $t->string('subject_type', 80)->nullable();        // the provider subject it runs on, when it has one
            $t->unsignedBigInteger('process_owner_id')->nullable();
            $t->jsonb('departments')->default('[]');
            $t->string('status', 20)->default('draft');        // draft | in_review | published | suspended | retired
            $t->string('trigger_type', 30)->default('manual'); // manual | record_event | scheduled
            $t->jsonb('trigger_config')->default('{}');
            $t->jsonb('start_roles')->default('[]');           // empty = anyone with workflow.start
            $t->string('default_priority', 10)->default('normal');
            $t->unsignedBigInteger('calendar_id')->nullable();
            $t->boolean('is_confidential')->default(false);
            $t->jsonb('restricted_user_ids')->default('[]');   // confidential: only these (plus participants) see it
            $t->unsignedInteger('retention_days')->nullable();
            $t->unsignedBigInteger('current_version_id')->nullable(); // the published version new instances use
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'key']);
            $t->index(['company_id', 'status']);
        });

        Schema::create('workflow_versions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 20)->default('draft');        // draft | in_review | published | superseded | retired | rejected
            $t->jsonb('graph');                                // {nodes:[{key,type,name,x,y,config}], edges:[{key,from,to,label,condition,default}]}
            $t->jsonb('pinned_forms')->default('{}');          // form_id => form_version_id, frozen at publication
            $t->text('change_summary')->nullable();
            $t->jsonb('validation')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_comment')->nullable();
            $t->unsignedBigInteger('published_by')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->date('effective_from')->nullable();
            $t->string('origin', 20)->default('manual');       // manual | template | ai | import | rollback
            $t->timestampsTz();
            $t->unique(['definition_id', 'version']);
        });

        Schema::create('workflow_instances', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('definition_id')->constrained('workflow_definitions');
            $t->foreignId('version_id')->constrained('workflow_versions');
            $t->string('reference', 40);
            $t->string('title', 200);
            $t->string('status', 20)->default('running');      // running | completed | rejected | cancelled | failed | suspended
            $t->string('outcome', 30)->nullable();
            $t->string('subject_type', 80)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->string('subject_ref', 120)->nullable();
            $t->unsignedBigInteger('requester_id')->nullable()->index();
            $t->string('priority', 10)->default('normal');
            $t->jsonb('data')->default('{}');                  // form values and values set by actions
            $t->string('dedupe_key', 128)->nullable();
            $t->unsignedBigInteger('parent_instance_id')->nullable();
            $t->text('error')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['company_id', 'dedupe_key']);
            $t->unique(['company_id', 'reference']);
            $t->index(['company_id', 'status']);
            $t->index(['subject_type', 'subject_id']);
        });

        // Every node execution, including skipped, cancelled, retried and failed ones (§19.6).
        Schema::create('workflow_node_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $t->string('node_key', 60);
            $t->string('node_type', 30);
            $t->unsignedInteger('activation')->default(1);     // a node re-entered after "return for correction" runs again
            $t->string('status', 20);                          // active | waiting | completed | skipped | cancelled | failed
            $t->string('outcome', 40)->nullable();
            $t->string('via_edge', 60)->nullable();
            $t->jsonb('detail')->nullable();
            $t->timestampTz('started_at')->useCurrent();
            $t->timestampTz('finished_at')->nullable();
            $t->unique(['instance_id', 'node_key', 'activation']);
            $t->index(['instance_id', 'status']);
        });

        Schema::create('workflow_tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $t->foreignId('node_run_id')->constrained('workflow_node_runs')->cascadeOnDelete();
            $t->string('node_key', 60);
            $t->string('type', 20);                            // user_task | approval | review | exception
            $t->string('name', 200);
            $t->string('status', 20)->default('open');         // open | completed | cancelled | exception
            $t->unsignedBigInteger('assignee_id')->nullable(); // null while it waits in a queue
            $t->unsignedBigInteger('original_assignee_id')->nullable();
            $t->jsonb('candidates')->default('[]');            // queue: user ids who may claim it
            $t->string('decision', 30)->nullable();            // approved | rejected | returned | info_requested | completed | abstained
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->unsignedBigInteger('decided_on_behalf_of')->nullable();
            $t->text('comment')->nullable();
            $t->jsonb('data')->default('{}');
            $t->string('priority', 10)->default('normal');
            $t->timestampTz('due_at')->nullable();
            $t->string('sla_state', 20)->default('none');      // none | on_track | warning | breached | paused
            $t->timestampTz('warned_at')->nullable();
            $t->timestampTz('breached_at')->nullable();
            $t->unsignedInteger('reminders_sent')->default(0);
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->index(['company_id', 'assignee_id', 'status']);
            $t->index(['company_id', 'status', 'due_at']);
            $t->index(['instance_id', 'node_key']);
        });

        Schema::create('workflow_task_assignments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('task_id')->constrained('workflow_tasks')->cascadeOnDelete();
            $t->string('action', 20);                          // assigned | claimed | released | delegated | reassigned | escalated
            $t->unsignedBigInteger('from_user_id')->nullable();
            $t->unsignedBigInteger('to_user_id')->nullable();
            $t->unsignedBigInteger('by_user_id')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });

        Schema::create('workflow_timers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $t->unsignedBigInteger('task_id')->nullable();
            $t->unsignedBigInteger('node_run_id')->nullable();
            $t->string('kind', 20);                            // delay | sla_warning | sla_breach | reminder
            $t->timestampTz('fire_at');
            $t->string('status', 20)->default('pending');      // pending | fired | cancelled
            $t->jsonb('payload')->default('{}');
            $t->timestampTz('fired_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['status', 'fire_at']);
        });

        Schema::create('workflow_action_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $t->unsignedBigInteger('node_run_id')->nullable();
            $t->string('action_key', 80);
            $t->string('idempotency_key', 128)->unique();
            $t->string('status', 20)->default('pending');      // pending | succeeded | failed | uncertain | cancelled
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('max_attempts')->default(3);
            $t->timestampTz('next_attempt_at')->nullable();
            $t->jsonb('input')->default('{}');
            $t->jsonb('output')->nullable();
            $t->text('error')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->text('resolution')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'next_attempt_at']);
        });

        Schema::create('workflow_comments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->foreignId('instance_id')->constrained('workflow_instances')->cascadeOnDelete();
            $t->unsignedBigInteger('task_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->text('body');
            $t->timestampTz('created_at')->useCurrent();
        });

        // §8.4: temporary delegation. It adds the delegate as someone who may act for the delegator
        // on matching tasks; it never widens their access to the underlying record.
        Schema::create('workflow_delegations', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('from_user_id');
            $t->unsignedBigInteger('to_user_id');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->jsonb('categories')->default('[]');            // empty = every category
            $t->text('reason')->nullable();
            $t->string('status', 20)->default('active');       // active | revoked
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->index(['company_id', 'from_user_id', 'status']);
        });

        // §6: a request form saved part-way and resumed later. One per person per workflow.
        Schema::create('workflow_drafts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('user_id');
            $t->foreignId('definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $t->jsonb('data')->default('{}');
            $t->timestampsTz();
            $t->unique(['user_id', 'definition_id']);
        });

        // §19 outbox: written in the same transaction as the change, delivered by workflow:tick.
        Schema::create('workflow_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('name', 80);
            $t->jsonb('payload');
            $t->string('dedupe_key', 64)->unique();
            $t->uuid('correlation_id')->nullable();
            $t->string('status', 20)->default('pending');      // pending | delivered | failed
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestampTz('available_at')->useCurrent();
            $t->timestampTz('delivered_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['status', 'available_at']);
        });

        Schema::create('workflow_audit_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('actor_type', 20);                      // user | service | ai
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_name', 160)->nullable();
            $t->string('action', 60);
            $t->string('subject_type', 40);
            $t->string('subject_id', 64)->nullable();
            $t->unsignedBigInteger('instance_id')->nullable();
            $t->unsignedBigInteger('definition_id')->nullable();
            $t->unsignedInteger('version')->nullable();
            $t->string('outcome', 20)->default('success');     // success | denied | failed
            $t->text('reason')->nullable();
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->jsonb('context')->nullable();
            $t->uuid('correlation_id')->nullable();
            $t->string('previous_hash', 64)->nullable();
            $t->string('hash', 64);
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['company_id', 'instance_id']);
            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'action']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            foreach (self::TENANT_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }

            // §18: audit records are append-only. Corrections are new events.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION workflow_audit_events_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'workflow_audit_events is append-only';
                END;
                $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS workflow_audit_events_no_change ON workflow_audit_events;
                CREATE TRIGGER workflow_audit_events_no_change BEFORE UPDATE OR DELETE ON workflow_audit_events
                    FOR EACH ROW EXECUTE FUNCTION workflow_audit_events_immutable();
            SQL);

            // Controlled values (§19 data rules).
            DB::statement("ALTER TABLE workflow_definitions ADD CONSTRAINT workflow_definitions_status CHECK (status IN ('draft','in_review','published','suspended','retired'))");
            DB::statement("ALTER TABLE workflow_versions ADD CONSTRAINT workflow_versions_status CHECK (status IN ('draft','in_review','published','superseded','retired','rejected'))");
            DB::statement("ALTER TABLE workflow_instances ADD CONSTRAINT workflow_instances_status CHECK (status IN ('running','completed','rejected','cancelled','failed','suspended'))");
            DB::statement("ALTER TABLE workflow_tasks ADD CONSTRAINT workflow_tasks_status CHECK (status IN ('open','completed','cancelled','exception'))");
            DB::statement("ALTER TABLE workflow_delegations ADD CONSTRAINT workflow_delegations_dates CHECK (ends_at > starts_at AND from_user_id <> to_user_id)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS workflow_audit_events_no_change ON workflow_audit_events; DROP FUNCTION IF EXISTS workflow_audit_events_immutable();');
        }
        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
