<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The incident core (§4.2, §5.1).
 *
 * Four tables: the incident, the people involved, the immutable context snapshot taken at
 * `occurred_at` (SHEQ-INC-6), and the statutory obligations the Regulatory Pack Engine derived
 * (SHEQ-REG-5).
 *
 * Two deliberate departures from the specification's DDL, both recorded rather than silent:
 *
 * 1. **No range partitioning on `occurred_at` yet.** Partitioning a table in PostgreSQL requires the
 *    partition key inside every unique constraint, so the primary key becomes `(id, occurred_at)`
 *    and every child foreign key has to carry the timestamp too. That composite ripples into people,
 *    causes, evidence, obligations and every query in the module, for a benefit that only appears at
 *    a volume no tenant is near. Revisit via pg_partman when a single tenant passes ~10M rows; the
 *    column order here is already partition-friendly.
 * 2. **`client_id` for offline idempotency is on the incident**, not a platform-wide convention yet
 *    (SHEQ-ADR-004). It is unique per company so a replayed mobile submission returns the original
 *    incident instead of creating a second one (SHEQ-OFF-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_incidents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference', 40);                       // INC-2026-001234

            // Offline idempotency key, generated on the device (SHEQ-OFF-2).
            $table->uuid('client_id')->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->timestampTz('occurred_at');
            $table->timestampTz('reported_at');
            $table->timestampTz('discovered_at')->nullable();

            $table->string('location_text')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();

            // INJURY | NEAR_MISS | ENVIRONMENTAL | PROPERTY_DAMAGE | VEHICLE | SECURITY | FIRE |
            // QUALITY | OCCUPATIONAL_ILLNESS
            $table->string('incident_type', 40);
            $table->string('activity_at_time')->nullable();
            $table->text('description');
            $table->text('immediate_action')->nullable();

            // Outcome — the facts the Regulatory Pack Engine classifies on.
            $table->string('outcome', 40)->nullable();
            $table->string('treatment_level', 40)->nullable();
            $table->unsignedTinyInteger('actual_severity')->nullable();      // 1..5
            $table->unsignedTinyInteger('potential_severity')->nullable();   // 1..5 — drives rigour
            $table->unsignedSmallInteger('likely_incapacity_days')->nullable();
            $table->unsignedSmallInteger('days_lost')->default(0);
            $table->unsignedSmallInteger('days_restricted')->default(0);
            $table->boolean('dangerous_substance_release')->default(false);
            $table->boolean('machinery_failure_causing_danger')->default(false);

            // Statutory verdict, frozen with the pack version that produced it (SHEQ-REG-2).
            $table->string('regulatory_pack_id', 60)->nullable();
            $table->string('regulatory_pack_version', 40)->nullable();
            $table->string('classification_rule_id', 60)->nullable();
            $table->string('statutory_category', 60)->nullable();
            $table->boolean('is_reportable')->default(false);
            $table->jsonb('required_forms')->nullable();

            // DRAFT|REPORTED|UNDER_INVESTIGATION|PENDING_APPROVAL|CLOSED|REOPENED|VOID
            $table->string('status', 30)->default('REPORTED');
            $table->string('investigation_method', 30)->nullable();

            // SHEQ-TEN-4 — content restricted to sheq.confidential.read regardless of site role.
            $table->boolean('is_confidential')->default(false);
            // SHEQ-INC-3 — anonymous reports store no reporter, IP or device.
            $table->boolean('is_anonymous')->default(false);

            // Cross-module links (SHEQ-ADR-002): references, never copies.
            $table->unsignedBigInteger('linked_asset_id')->nullable();
            $table->unsignedBigInteger('linked_vehicle_id')->nullable();
            $table->unsignedBigInteger('linked_project_id')->nullable();
            $table->unsignedBigInteger('linked_hazard_id')->nullable();

            $table->string('source_channel', 20)->default('WEB');   // WEB|MOBILE|WHATSAPP|USSD|QR|EMAIL|API
            $table->bigInteger('cost_estimate_minor')->nullable();  // minor units (SHEQ-ADR-001)
            $table->char('cost_currency', 3)->default('ZAR');

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();

            // SHEQ-DATA-3 — statutory records cannot be hard-deleted before this date.
            $table->date('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);

            $table->unsignedInteger('version')->default(1);         // optimistic locking
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'client_id']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'is_reportable', 'statutory_category']);
            $table->index(['company_id', 'site_id', 'occurred_at']);
        });

        Schema::create('sheq_incident_people', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('sheq_incidents')->cascadeOnDelete();

            // INJURED|WITNESS|SUPERVISOR|FIRST_AIDER|DRIVER|INVESTIGATOR|THIRD_PARTY
            $table->string('role', 30);

            // Employees are referenced, never copied (principle P3). Non-employees — public,
            // contractors without a login — are captured inline because there is nothing to link to.
            $table->unsignedBigInteger('hr_person_id')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->string('external_name')->nullable();
            $table->string('external_contact')->nullable();

            $table->string('body_part', 60)->nullable();
            $table->string('injury_nature', 60)->nullable();
            $table->text('statement')->nullable();
            $table->timestampTz('statement_signed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'incident_id']);
            $table->index(['company_id', 'hr_person_id']);
        });

        /**
         * SHEQ-INC-6 — the context snapshot. Written once, never updated: its evidentiary value is
         * that it records what was true at the moment of the incident, including the compliance
         * gaps nobody wants to find later. An UPDATE trigger enforces that.
         */
        Schema::create('sheq_incident_context', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('sheq_incidents')->cascadeOnDelete();

            $table->timestampTz('captured_at');
            $table->timestampTz('as_at');                  // the incident's occurred_at
            $table->jsonb('snapshot');                     // the full context blob
            $table->jsonb('compliance_gaps')->nullable();  // the flags raised, extracted for query
            $table->unsignedSmallInteger('gap_count')->default(0);
            $table->jsonb('sources')->nullable();          // which adapters answered, and how stale

            $table->timestamps();
            $table->unique('incident_id');
            $table->index(['company_id', 'gap_count']);
        });

        Schema::create('sheq_statutory_obligations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('obligation_def_id', 60);
            $table->string('label');
            $table->string('subject_type', 40);            // INCIDENT|PERMIT|INSPECTION|TENANT
            $table->unsignedBigInteger('subject_id');

            $table->string('regulatory_pack_id', 60);
            $table->string('regulatory_pack_version', 40);

            $table->timestampTz('anchor_at');
            $table->timestampTz('due_at');
            $table->jsonb('reminders')->nullable();
            $table->jsonb('escalations')->nullable();

            // PENDING|IN_PROGRESS|SUBMITTED|ACKNOWLEDGED|BREACHED|NOT_APPLICABLE
            $table->string('status', 30)->default('PENDING');
            $table->string('responsible_role', 40)->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient')->nullable();

            $table->boolean('evidence_required')->default(false);
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('breach_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status', 'due_at']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        $this->applyTenantRls([
            'sheq_incidents', 'sheq_incident_people', 'sheq_incident_context', 'sheq_statutory_obligations',
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION sheq_context_immutable() RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'sheq_incident_context is written once (SHEQ-INC-6): % rejected', TG_OP
                        USING ERRCODE = 'restrict_violation';
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::statement('CREATE TRIGGER sheq_context_no_update BEFORE UPDATE ON sheq_incident_context
                           FOR EACH ROW EXECUTE FUNCTION sheq_context_immutable()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS sheq_context_no_update ON sheq_incident_context');
            DB::statement('DROP FUNCTION IF EXISTS sheq_context_immutable()');
        }

        Schema::dropIfExists('sheq_statutory_obligations');
        Schema::dropIfExists('sheq_incident_context');
        Schema::dropIfExists('sheq_incident_people');
        Schema::dropIfExists('sheq_incidents');
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
