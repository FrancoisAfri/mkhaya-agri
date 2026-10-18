<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incident investigations (SHEQ-INC-7 … SHEQ-INC-10).
 *
 * Until now an incident could be reported, classified and notified — but never actually
 * investigated. The status could move to UNDER_INVESTIGATION with nothing behind it.
 *
 * Two structural decisions carry the requirements:
 *
 * - **Revisions, not edits.** SHEQ-INC-10 says reopening creates a new revision and preserves the
 *   prior one verbatim. So an investigation is immutable once approved and reopening supersedes it,
 *   rather than a row that gets rewritten. What the investigator concluded in March is evidence,
 *   even after April's reopening contradicts it.
 * - **Depth is driven by potential severity** (SHEQ-INC-7). A near miss that could have killed
 *   somebody gets the same rigour as a lost-time injury, so `required_method` and
 *   `minimum_causes` are stamped on the investigation from the pack when it opens — not chosen by
 *   whoever happens to be filling it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_investigations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('incident_id')->constrained('sheq_incidents')->cascadeOnDelete();

            // Increments on every reopening; the previous revision is kept and marked superseded.
            $table->unsignedSmallInteger('revision')->default(1);
            $table->unsignedBigInteger('supersedes_id')->nullable();

            // FIVE_WHY|ICAM|FISHBONE|BOWTIE|TAPROOT
            $table->string('method', 20);
            // What the pack demanded given the potential severity — recorded so a later reader can
            // see whether the rigour applied was the rigour required.
            $table->string('required_method', 20)->nullable();
            $table->unsignedSmallInteger('minimum_causes')->default(1);
            $table->unsignedTinyInteger('potential_severity')->nullable();

            $table->text('summary')->nullable();
            $table->text('sequence_of_events')->nullable();
            $table->jsonb('method_payload')->nullable();   // the 5-Why chain, the ICAM grid

            // DRAFT|PENDING_APPROVAL|APPROVED|SUPERSEDED
            $table->string('status', 20)->default('DRAFT');

            $table->foreignId('lead_investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('superseded_at')->nullable();
            $table->text('reopen_reason')->nullable();

            $table->timestamps();

            // One live investigation per incident; superseded revisions sit alongside it.
            $table->index(['company_id', 'incident_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sheq_investigation_causes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('investigation_id')->constrained('sheq_investigations')->cascadeOnDelete();

            /**
             * ICAM's layers, which the other methods map onto:
             * ABSENT_FAILED_DEFENCE | INDIVIDUAL_TEAM_ACTION | TASK_ENVIRONMENT | ORGANISATIONAL
             *
             * ROOT is what SHEQ-INC-9 requires before closure. A finding that stops at "the
             * operator made a mistake" is an individual action, not a root cause, and the
             * distinction is the whole reason ICAM exists.
             */
            $table->string('category', 40);
            $table->string('cause_type', 20)->default('CONTRIBUTING');   // ROOT|CONTRIBUTING
            $table->text('description');
            $table->text('evidence')->nullable();

            // Where a 5-Why chain produced it, the depth it sat at.
            $table->unsignedTinyInteger('depth')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'investigation_id', 'cause_type']);
        });

        $this->applyTenantRls(['sheq_investigations', 'sheq_investigation_causes']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_investigation_causes');
        Schema::dropIfExists('sheq_investigations');
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
