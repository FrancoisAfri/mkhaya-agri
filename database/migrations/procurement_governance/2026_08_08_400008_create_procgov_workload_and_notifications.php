<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workload & capacity (§4), notifications & escalations (§9), and the reporting engine's saved
 * definitions and generated runs (§3.1).
 *
 * On workload: §4.1's figures are all derivable live, and the dashboard computes them that way so
 * it is never stale. The snapshot table exists for a different question — "was this officer
 * overloaded in March, when that tender slipped?" — which a live query can no longer answer once
 * the work has completed. One row per officer per day, written by a scheduled command.
 *
 * On reporting: §3 requires reports generated from live data rather than compiled spreadsheets, so
 * a report *definition* stores only the filter selection (§3.1), never numbers. A *run* stores the
 * rendered result, because a quarterly report that was submitted to a board must still read the
 * same next year even as the underlying transactions are corrected.
 *
 * On notifications: every rule in §9 is the same shape — an event, who hears about it, through
 * which channel, how early, and who is told when it is still unresolved after a configured time.
 * One configurable table serves all eleven, and `procgov_notification_log` carries a dedupe key so
 * a daily sweep does not send the same reminder every day.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── §4 Officer workload snapshots ────────────────────────────────────────────────────────
        Schema::create('procgov_officer_workload', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('officer_id')->constrained('users')->cascadeOnDelete();
            $t->date('snapshot_date');

            // §4.1
            $t->unsignedSmallInteger('active_procurements')->default(0);
            $t->unsignedSmallInteger('completed_procurements')->default(0);
            $t->unsignedSmallInteger('pending_procurements')->default(0);
            $t->unsignedSmallInteger('tenders_managed')->default(0);
            $t->unsignedSmallInteger('evaluations_managed')->default(0);
            $t->unsignedSmallInteger('deviations_processed')->default(0);
            $t->unsignedSmallInteger('contracts_managed')->default(0);
            $t->unsignedSmallInteger('committee_participation')->default(0);
            $t->unsignedSmallInteger('pending_actions')->default(0);
            $t->unsignedSmallInteger('overdue_actions')->default(0);
            $t->unsignedInteger('avg_processing_hours')->nullable();

            // UNDER_UTILISED | NORMAL | HIGH | OVERLOADED — computed against the tenant's own
            // thresholds in procgov_settings, never a hard-coded number.
            $t->string('capacity_status', 20)->default('NORMAL');
            $t->decimal('capacity_index', 8, 2)->nullable();   // weighted load / threshold

            $t->timestamp('computed_at');
            $t->timestamps();

            $t->unique(['company_id', 'officer_id', 'snapshot_date'], 'procgov_workload_unique');
            $t->index(['company_id', 'snapshot_date', 'capacity_status'], 'procgov_workload_status_idx');
        });

        // §4.3: a detected overload, with the redistribution the system suggests. Kept as a record
        // rather than a transient banner so management can see whether anything was done about it.
        Schema::create('procgov_capacity_alerts', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->foreignId('officer_id')->constrained('users')->cascadeOnDelete();

            $t->string('severity', 12)->default('HIGH');      // HIGH | OVERLOADED
            $t->unsignedSmallInteger('active_procurements')->default(0);
            $t->unsignedSmallInteger('overdue_actions')->default(0);
            $t->unsignedSmallInteger('awaiting_evaluation')->default(0);
            $t->text('narrative')->nullable();                // the §4.3 sentence, ready to render
            $t->json('suggested_reassignments')->nullable();  // [{record_id, reference, to_officer_id}]

            $t->string('status', 20)->default('OPEN');        // OPEN | ACKNOWLEDGED | ACTIONED | DISMISSED
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->text('resolution_note')->nullable();

            $t->timestamp('detected_at');
            $t->timestamps();

            $t->index(['company_id', 'status', 'detected_at'], 'procgov_capacity_status_idx');
            $t->index(['company_id', 'officer_id'], 'procgov_capacity_officer_idx');
        });

        // ── §9 Notification & escalation rules ───────────────────────────────────────────────────
        Schema::create('procgov_notification_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('code', 60);
            $t->string('name');

            /*
             * The eleven §9 events, plus room for tenant-defined ones:
             *   PENDING_APPROVAL | EVALUATION_DEADLINE | OVERDUE_EVALUATION | PROCUREMENT_DELAY |
             *   DEVIATION_DECISION | MISSING_DOCUMENT | EXPIRING_DOCUMENT | EXPIRING_CONTRACT |
             *   BUDGET_PROBLEM | COMPLIANCE_FAILURE | TASK_ASSIGNED | COI_DECLARED | CAPACITY_ALERT
             */
            $t->string('event', 40);

            $t->json('channels')->nullable();      // ["mail","database","sms"]
            /*
             * Who hears about it. Dynamic recipients resolve at send time, which matters because
             * the right person is usually defined by their relationship to the record rather than
             * by name: {"users":[], "roles":[], "dynamic":["officer","unit_manager","requester",
             * "approver","committee_chair","budget_owner"]}
             */
            $t->json('recipients')->nullable();

            $t->unsignedSmallInteger('lead_days')->default(0);       // notify this far ahead
            $t->unsignedSmallInteger('repeat_every_days')->nullable();
            $t->unsignedSmallInteger('escalate_after_days')->nullable();
            $t->json('escalate_to')->nullable();                     // same shape as recipients

            $t->json('conditions')->nullable();     // value band, department, category …
            $t->string('template_key', 120)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();

            $t->unique(['company_id', 'code'], 'procgov_notif_rule_unique');
            $t->index(['company_id', 'event', 'is_active'], 'procgov_notif_event_idx');
        });

        Schema::create('procgov_notification_log', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->unsignedBigInteger('rule_id')->nullable();
            $t->string('event', 40);

            $t->string('subject_type', 80)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('subject_reference', 80)->nullable();

            $t->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('recipient_address', 190)->nullable();
            $t->string('channel', 20)->default('database');
            $t->unsignedTinyInteger('escalation_level')->default(0);  // 0 = the original notice

            $t->string('status', 20)->default('SENT');   // SENT | FAILED | SKIPPED
            $t->string('failure_reason', 500)->nullable();

            /*
             * Idempotence for the daily sweep. Without it a "contract expiring in 30 days" rule
             * mails the same person every morning for a month, and people stop reading procurement
             * mail — which defeats §9 more thoroughly than not sending at all.
             */
            $t->string('dedupe_key', 190);

            $t->timestamp('sent_at');
            $t->timestamps();

            $t->unique(['company_id', 'dedupe_key'], 'procgov_notif_dedupe_unique');
            $t->index(['company_id', 'event', 'sent_at'], 'procgov_notif_event_log_idx');
            $t->index(['company_id', 'subject_type', 'subject_id'], 'procgov_notif_subject_idx');
        });

        // ── §3.1 Saved report definitions and their generated runs ───────────────────────────────
        Schema::create('procgov_report_definitions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $t->string('code', 60);
            $t->string('name');
            $t->text('description')->nullable();

            // SPEND | SUPPLIER | PERFORMANCE | COMPLIANCE | WORKLOAD | PLAN_STATUS | CUSTOM
            $t->string('report_type', 30);
            $t->string('period_type', 20)->default('QUARTER');   // QUARTER | ANNUAL | MONTH | CUSTOM

            // The §3.1 selection: financial year, quarter, department, category, project, supplier,
            // officer, contract, method. Stored as filters, never as results.
            $t->json('filters')->nullable();
            $t->json('columns')->nullable();

            // Scheduled generation — §3 asks for reports to be produced automatically.
            $t->boolean('is_scheduled')->default(false);
            $t->string('schedule_cron', 60)->nullable();
            $t->json('schedule_recipients')->nullable();
            $t->timestamp('last_run_at')->nullable();

            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['company_id', 'code'], 'procgov_report_def_unique');
            $t->index(['company_id', 'report_type'], 'procgov_report_type_idx');
        });

        Schema::create('procgov_report_runs', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->unsignedBigInteger('definition_id')->nullable();

            $t->string('report_type', 30);
            $t->string('title', 190);
            $t->string('period_label', 60)->nullable();      // "FY2026/27 Q2"
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->json('filters')->nullable();

            // The rendered result, frozen. See the class docblock.
            $t->json('payload')->nullable();
            $t->char('payload_hash', 64)->nullable();
            $t->unsignedBigInteger('document_id')->nullable();  // exported PDF, when one was made

            $t->string('status', 20)->default('COMPLETE');  // RUNNING | COMPLETE | FAILED
            $t->string('failure_reason', 500)->nullable();

            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('generated_at');
            $t->timestamps();

            $t->index(['company_id', 'report_type', 'generated_at'], 'procgov_report_run_idx');
            $t->index(['company_id', 'definition_id'], 'procgov_report_run_def_idx');
        });

        $this->applyTenantRls([
            'procgov_officer_workload',
            'procgov_capacity_alerts',
            'procgov_notification_rules',
            'procgov_notification_log',
            'procgov_report_definitions',
            'procgov_report_runs',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('procgov_report_runs');
        Schema::dropIfExists('procgov_report_definitions');
        Schema::dropIfExists('procgov_notification_log');
        Schema::dropIfExists('procgov_notification_rules');
        Schema::dropIfExists('procgov_capacity_alerts');
        Schema::dropIfExists('procgov_officer_workload');
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
