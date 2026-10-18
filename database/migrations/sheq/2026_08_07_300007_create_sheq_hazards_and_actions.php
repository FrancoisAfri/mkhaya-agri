<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hazard reporting (§5.2) and the unified CAPA action (§5.5).
 *
 * One action entity across every source, so a person has a single "My Actions" list whether the
 * work came from an incident, a hazard, an inspection or an audit (SHEQ-CAP-1). Building separate
 * corrective-action tables per feature is the mistake that makes those lists impossible to merge
 * later.
 *
 * Two fields here carry real weight:
 *
 * - `hierarchy_level` on the action. The hierarchy of control is the difference between "we told
 *   them to be careful" and "we removed the hazard". Recording it makes PPE-only remedies visible
 *   as a pattern instead of hiding one at a time.
 * - `rejection_reason` on the hazard. SHEQ-HAZ-3 is blunt about it: silent rejection kills reporting
 *   culture and is the number one reason these systems die. A hazard cannot be rejected without a
 *   reason, and the reporter is told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_hazards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference', 40);
            $table->uuid('client_id')->nullable();               // offline idempotency (SHEQ-OFF-2)

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->text('description');
            // MECHANICAL|ELECTRICAL|CHEMICAL|ERGONOMIC|BIOLOGICAL|PSYCHOSOCIAL|ENVIRONMENTAL|BEHAVIOURAL
            $table->string('hazard_category', 30)->nullable();
            $table->string('location_text')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();

            $table->unsignedTinyInteger('severity_est')->nullable();   // 1..5, reporter's view

            // OPEN|TRIAGED|CONTROLLED|CLOSED|REJECTED
            $table->string('status', 20)->default('OPEN');

            // SHEQ-HAZ-2: triage SLA is measured from reported_at; breaches escalate.
            $table->timestampTz('reported_at');
            $table->timestampTz('triage_due_at')->nullable();
            $table->timestampTz('triaged_at')->nullable();
            $table->foreignId('triaged_by')->nullable()->constrained('users')->nullOnDelete();

            // SHEQ-HAZ-3/4: a rejection must carry a reason, and the reporter must be told.
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('reporter_notified_at')->nullable();

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_anonymous')->default(false);

            $table->unsignedBigInteger('escalated_to_incident_id')->nullable();
            $table->timestampTz('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'client_id']);
            $table->index(['company_id', 'status', 'triage_due_at']);
            $table->index(['company_id', 'site_id', 'status']);
        });

        Schema::create('sheq_actions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference', 40);
            $table->uuid('client_id')->nullable();

            // CORRECTIVE|PREVENTIVE|IMPROVEMENT|IMMEDIATE|INTERIM
            $table->string('action_type', 20)->default('CORRECTIVE');
            $table->string('title');
            $table->text('description')->nullable();

            // INCIDENT|HAZARD|INSPECTION|AUDIT|RISK_LINE|COMPLAINT|PERMIT|MANUAL|AI
            $table->string('source_type', 20)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable();

            // ELIMINATION|SUBSTITUTION|ENGINEERING|ADMINISTRATIVE|PPE (SHEQ-RA-2, SHEQ-CAP hierarchy)
            $table->string('hierarchy_level', 20)->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('priority', 10)->default('MEDIUM');     // CRITICAL|HIGH|MEDIUM|LOW
            $table->date('due_date');

            // OPEN|IN_PROGRESS|PENDING_VERIFICATION|VERIFIED_CLOSED|OVERDUE|CANCELLED|REJECTED
            $table->string('status', 24)->default('OPEN');

            $table->timestampTz('completed_at')->nullable();
            $table->text('completion_notes')->nullable();

            // SHEQ-CAP-2: critical and high actions cannot be verified by the person who did them.
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();
            $table->boolean('evidence_required')->default(true);

            // SHEQ-CAP-3: effectiveness is reviewed after closure; NOT_EFFECTIVE spawns a successor.
            $table->date('effectiveness_review_due')->nullable();
            $table->string('effectiveness_result', 20)->nullable();   // EFFECTIVE|PARTIALLY|NOT_EFFECTIVE
            $table->unsignedBigInteger('supersedes_action_id')->nullable();

            // SHEQ-CAP-4: extensions are explicit, approved and counted — never a silent edit.
            $table->unsignedSmallInteger('extension_count')->default(0);
            $table->date('original_due_date')->nullable();

            $table->unsignedBigInteger('linked_course_id')->nullable();   // LMS remedy (SHEQ-CAP-6)
            $table->bigInteger('cost_actual_minor')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'client_id']);
            $table->index(['company_id', 'status', 'due_date']);
            $table->index(['company_id', 'assigned_to', 'status']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        $this->applyTenantRls(['sheq_hazards', 'sheq_actions']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_actions');
        Schema::dropIfExists('sheq_hazards');
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
