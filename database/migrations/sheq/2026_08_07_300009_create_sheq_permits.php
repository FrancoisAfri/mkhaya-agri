<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permit to Work (§5.7).
 *
 * A permit is the highest-consequence workflow in the module: it is the document that says people
 * may enter a confined space or put a flame near flammables. Three decisions in this schema come
 * straight from that.
 *
 * **Preconditions are structured, not ticked** (SHEQ-PTW-3). Where a measurement exists — gas in
 * % LEL, oxygen in %, an isolation certificate number, the standby person's identity — it is stored
 * as a value with its reading. "Gas tested ✓" tells an investigator nothing; "18.9% O₂ at 06:412 by
 * J. Mokoena" tells them everything.
 *
 * **Approvals are rows, not a status column** (SHEQ-PTW-2). Permit types need several approvers,
 * sometimes in parallel — an area owner and an electrical authority — and a single `approved_by`
 * cannot represent that or show who is still outstanding.
 *
 * **Workers are rows too**, because competency gating (SHEQ-COMP-9) has to name the person whose
 * ticket expired, and an override has to record who accepted that risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_permits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference', 40);
            $table->uuid('client_id')->nullable();          // offline issue (SHEQ-PTW-7)

            // HOT_WORK|HEIGHTS|CONFINED_SPACE|ELECTRICAL_ISOLATION|EXCAVATION|LIFTING|RADIATION|BREAKING_GROUND
            $table->string('permit_type', 30);
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            $table->text('work_description');
            $table->string('location_text')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();

            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to');

            // DRAFT|SUBMITTED|APPROVED|ACTIVE|SUSPENDED|CLOSED|EXPIRED|CANCELLED
            $table->string('status', 20)->default('DRAFT');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issuer_id')->nullable()->constrained('users')->nullOnDelete();

            // SHEQ-PTW-1: no risk assessment, no permit. Nullable only so a draft can be started
            // before one is selected; issuing without it is refused by the service.
            $table->foreignId('risk_assessment_id')->nullable()
                ->constrained('sheq_risk_assessments')->nullOnDelete();

            $table->jsonb('precondition_checks')->nullable();   // structured readings (SHEQ-PTW-3)
            $table->jsonb('closure_checks')->nullable();        // handback, housekeeping, fire watch

            // SHEQ-PTW-4: hot work needs a fire watch after the flame goes out, and the closure
            // check cannot be completed before it has elapsed.
            $table->unsignedSmallInteger('fire_watch_minutes')->nullable();
            $table->timestampTz('work_completed_at')->nullable();

            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            // SHEQ-COMP-9: an override is a recorded acceptance of risk, not a silent bypass.
            $table->boolean('competency_overridden')->default(false);
            $table->text('competency_override_reason')->nullable();
            $table->foreignId('competency_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('competency_overridden_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'client_id']);
            $table->index(['company_id', 'status', 'valid_to']);
            $table->index(['company_id', 'site_id', 'status']);
            $table->index(['company_id', 'permit_type', 'valid_from', 'valid_to']);
        });

        // ── Approvals (SHEQ-PTW-2) ────────────────────────────────────────────────────────────
        Schema::create('sheq_permit_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('permit_id')->constrained('sheq_permits')->cascadeOnDelete();

            $table->unsignedTinyInteger('stage')->default(1);   // same stage = parallel approvers
            $table->string('approver_role', 60);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('PENDING');   // PENDING|APPROVED|REJECTED
            $table->timestampTz('decided_at')->nullable();
            $table->text('comments')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'permit_id', 'stage']);
        });

        // ── Workers named on the permit ───────────────────────────────────────────────────────
        Schema::create('sheq_permit_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('permit_id')->constrained('sheq_permits')->cascadeOnDelete();

            $table->unsignedBigInteger('hr_person_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('worker_role', 40)->nullable();   // WORKER|STANDBY|SUPERVISOR|FIRE_WATCH

            // The competency verdict at issue time, frozen — the same reasoning as the incident
            // context snapshot. What was true when the permit was issued is what matters later.
            $table->jsonb('competency_snapshot')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'permit_id']);
        });

        // ── Which competencies a permit type demands (SHEQ-COMP-9) ────────────────────────────
        Schema::create('sheq_permit_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('permit_type', 30);
            $table->string('competency_code', 120);   // matches the competency name in LMS
            $table->boolean('is_mandatory')->default(true);
            $table->string('applies_to_role', 40)->nullable();   // null = every worker on the permit

            $table->timestamps();

            $table->unique(['company_id', 'permit_type', 'competency_code', 'applies_to_role'], 'sheq_permit_req_unique');
        });

        $this->applyTenantRls([
            'sheq_permits', 'sheq_permit_approvals', 'sheq_permit_workers', 'sheq_permit_requirements',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_permit_requirements');
        Schema::dropIfExists('sheq_permit_workers');
        Schema::dropIfExists('sheq_permit_approvals');
        Schema::dropIfExists('sheq_permits');
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
