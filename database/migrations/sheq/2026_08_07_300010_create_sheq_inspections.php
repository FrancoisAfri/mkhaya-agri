<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspections (§5.4).
 *
 * The template is stored as a JSON schema rather than normalised into question and option tables.
 * That is a deliberate trade: SHEQ-INS-1 wants a drag-and-drop builder with a dozen response types,
 * conditional logic and per-answer weighting, and SHEQ-INS-3 requires a completed inspection to
 * "render identically forever". A normalised design makes the first hard and the second nearly
 * impossible — the questions would have to be versioned row by row, and a rendering would depend on
 * joining tables that have since moved on.
 *
 * Instead: a template version is immutable, the inspection stores the version it used, and the
 * responses are keyed to question ids inside that frozen schema. An inspection from 2026 renders
 * from the schema of 2026, whatever the current template looks like.
 *
 * Responses are jsonb rather than rows for the same reason, plus one more: an inspection is written
 * once by one person on one device, usually offline. There is no partial-update contention to
 * design for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_inspection_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Null company = a template shipped by a regulatory pack, available to every tenant.
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->string('status', 20)->default('DRAFT');   // DRAFT|PUBLISHED|RETIRED

            // sections → questions → response type, logic, weighting, evidence rules (SHEQ-INS-1)
            $table->jsonb('schema');

            $table->string('scoring_mode', 20)->default('NONE');   // NONE|PERCENT|WEIGHTED|PASS_FAIL
            $table->decimal('pass_threshold', 5, 2)->nullable();

            $table->string('pack_source', 60)->nullable();     // e.g. za-ohsa (SHEQ-INS-8)
            $table->string('subject_type', 20)->nullable();    // ASSET|VEHICLE|AREA|CONTRACTOR|PERSON

            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code', 'version']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sheq_inspections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference', 40);
            $table->uuid('client_id')->nullable();          // offline capture (SHEQ-INS-6)

            $table->foreignId('template_id')->constrained('sheq_inspection_templates')->restrictOnDelete();
            // The version used, so the record renders from the schema it was answered against.
            $table->unsignedInteger('template_version');

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->string('subject_type', 20)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->date('scheduled_for')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            // SCHEDULED|IN_PROGRESS|COMPLETED|OVERDUE|SKIPPED
            $table->string('status', 20)->default('SCHEDULED');

            $table->decimal('score', 7, 2)->nullable();
            $table->decimal('max_score', 7, 2)->nullable();
            $table->decimal('score_percent', 5, 2)->nullable();
            $table->boolean('passed')->nullable();

            // question_id → {value, comment, media[], gps, answered_at}
            $table->jsonb('responses')->nullable();
            $table->unsignedSmallInteger('failed_count')->default(0);

            // SHEQ-INS-7: completed outside the subject's geofence is flagged, never blocked —
            // blocking breaks legitimate field work, and the flag is what a reviewer needs anyway.
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->boolean('location_mismatch')->default(false);
            $table->unsignedInteger('location_distance_m')->nullable();

            $table->boolean('captured_offline')->default(false);
            $table->string('device_id', 120)->nullable();
            $table->text('signature')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference']);
            $table->unique(['company_id', 'client_id']);
            $table->index(['company_id', 'status', 'scheduled_for']);
            $table->index(['company_id', 'template_id', 'completed_at']);
            $table->index(['company_id', 'subject_type', 'subject_id']);
        });

        // ── Scheduling (SHEQ-INS-4) ───────────────────────────────────────────────────────────
        Schema::create('sheq_inspection_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained('sheq_inspection_templates')->cascadeOnDelete();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->string('subject_type', 20)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // FIXED|CALENDAR|USAGE|AD_HOC — usage covers "every 250 engine hours" from Fleet/Assets.
            $table->string('trigger_type', 20)->default('FIXED');
            $table->unsignedSmallInteger('frequency_days')->nullable();
            $table->unsignedInteger('usage_interval')->nullable();
            $table->string('usage_unit', 20)->nullable();       // HOURS|KM|CYCLES

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_due')->nullable();
            $table->date('last_completed')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active', 'next_due']);
        });

        $this->applyTenantRls(['sheq_inspections', 'sheq_inspection_schedules']);

        // Templates carry a nullable company_id for pack-provided rows, so the policy must let a
        // NULL row through — a shared template belongs to no tenant and must be visible to all.
        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id IS NULL OR company_id = {$session}::bigint";
            DB::statement('ALTER TABLE sheq_inspection_templates ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE sheq_inspection_templates FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sheq_inspection_templates');
            DB::statement("CREATE POLICY tenant_isolation ON sheq_inspection_templates
                           USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_inspection_schedules');
        Schema::dropIfExists('sheq_inspections');
        Schema::dropIfExists('sheq_inspection_templates');
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
