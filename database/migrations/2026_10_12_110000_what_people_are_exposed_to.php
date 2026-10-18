<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Occupational health and hygiene (OH-1 to OH-6).
 *
 * `sheq_exposure_hours` records how many hours people worked, which is the denominator for injury
 * rates. It says nothing about what they were exposed to while working them — and occupational
 * disease, not injury, is what the Mine Health and Safety Act and the Hazardous Chemical Agents
 * Regulations are mostly about.
 *
 * The distinction that shapes this: an injury is an event and an exposure is a dose. An injury
 * register answers "what happened"; an exposure register has to answer "what has this person
 * accumulated over fourteen years", because that is the question asked in a silicosis claim long
 * after the person has left and the site has closed. So the results are immutable history keyed
 * to a person, and the limit in force at the time is stamped on each one — a limit tightened in
 * 2031 must not retrospectively make 2026 a breach, and a limit relaxed must not retrospectively
 * make it compliant.
 *
 * Two tables and two columns:
 *
 * **Agents (OH-1)** — what the workplace exposes people to, with the applicable limit and the
 * surveillance it triggers.
 *
 * **Results (OH-2, OH-3)** — each measurement, against a person or a similar-exposure group.
 *
 * The columns on the existing PPE and medical tables are OH-5 and OH-4: the link from an agent to
 * the PPE that controls it and the medical that monitors it. Those are relationships, not new
 * registers, and inventing parallel tables for them would leave a site with two lists of
 * respirators.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** OH-1. */
        Schema::create('sheq_exposure_agents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('name', 160);

            /**
             * The kind of agent: NOISE, DUST, SILICA, CHEMICAL, FUME, HEAT, VIBRATION, RADIATION.
             *
             * The eight OH-1 names, as a vocabulary, because the report a host needs is "every
             * silica result across every site" and that cannot be assembled from free text.
             */
            $table->string('agent_type', 30);

            $table->text('description')->nullable();

            // ── The applicable limit (OH-2) ──────────────────────────────────────────────────
            /**
             * The occupational exposure limit, and the unit it is expressed in.
             *
             * Nullable because some agents have no numerical limit in South African law and are
             * managed by control banding instead — and a register that demanded a number would
             * have those entered as zero, which reads as a limit of nothing.
             */
            $table->decimal('limit_value', 14, 4)->nullable();
            $table->string('limit_unit', 40)->nullable();

            /**
             * Where the limit comes from.
             *
             * Carried because the limits move: the OEL for respirable crystalline silica was
             * revised, and a result judged against an unattributed number cannot be defended or
             * recomputed.
             */
            $table->string('limit_source', 255)->nullable();

            /** An action level, conventionally half the limit, where one is used. */
            $table->decimal('action_level', 14, 4)->nullable();

            // ── OH-4 ─────────────────────────────────────────────────────────────────────────
            /**
             * Whether exposure to this agent puts a person into medical surveillance.
             *
             * The whole of OH-4. Noise means audiometry, silica means spirometry and a chest
             * radiograph, and the obligation follows from the exposure rather than from somebody
             * remembering to enrol them.
             */
            $table->boolean('requires_medical_surveillance')->default(false);

            /** Which examination, as a real link into the medical types already configured. */
            $table->foreignId('medical_type_id')->nullable()
                ->constrained('sheq_medical_types')->nullOnDelete();

            // ── OH-6 ─────────────────────────────────────────────────────────────────────────
            /** Whether a result over the limit raises a corrective action. */
            $table->boolean('capa_on_exceedance')->default(true);

            /** And whether it opens a root-cause investigation. */
            $table->boolean('investigate_on_exceedance')->default(false);

            $table->foreignId('legal_register_line_id')->nullable()
                ->constrained('sheq_legal_register_lines')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'agent_type'], 'sheq_agent_type_idx');
            $table->index(['company_id', 'is_active'], 'sheq_agent_active_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_exposure_agent_code_uniq ON sheq_exposure_agents '
            .'(company_id, code) WHERE deleted_at IS NULL'
        );

        /**
         * A similar-exposure group (OH-2's "group").
         *
         * Hygiene monitoring is done by group rather than by person, because measuring every
         * individual every time is not how occupational hygiene works: a sample is taken on a
         * representative worker and applies to everybody doing that job in that area. A register
         * that could only record results against individuals would either be empty or would
         * attribute one person's sample to one person.
         */
        Schema::create('sheq_exposure_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /** The work this group does, from the activity catalogue where it is in it. */
            $table->string('activity', 60)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active'], 'sheq_exposure_group_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_exposure_group_code_uniq ON sheq_exposure_groups '
            .'(company_id, code) WHERE deleted_at IS NULL'
        );

        /** Who is in a group. */
        Schema::create('sheq_exposure_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('sheq_exposure_groups')->cascadeOnDelete();

            /** An employee or a contractor worker — both are exposed, and both have histories. */
            $table->foreignId('hr_person_id')->nullable()
                ->constrained('hr_people')->nullOnDelete();
            $table->foreignId('contractor_worker_id')->nullable()
                ->constrained('sheq_contractor_workers')->nullOnDelete();

            /**
             * When they joined and left the group.
             *
             * Dated, because a person's exposure history is the sum of the groups they were in
             * at the times they were in them. Membership as a simple flag would attribute this
             * year's results to somebody who left the area two years ago, and spare somebody who
             * has just joined their predecessor's dose.
             */
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'group_id'], 'sheq_exposure_member_group_idx');
            $table->index(['company_id', 'hr_person_id'], 'sheq_exposure_member_person_idx');
            $table->index(['company_id', 'contractor_worker_id'], 'sheq_exposure_member_worker_idx');
        });

        /** OH-2 and OH-3. */
        Schema::create('sheq_exposure_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference', 40);

            $table->foreignId('agent_id')->constrained('sheq_exposure_agents')->cascadeOnDelete();

            // ── Who it applies to ────────────────────────────────────────────────────────────
            $table->foreignId('group_id')->nullable()
                ->constrained('sheq_exposure_groups')->nullOnDelete();
            $table->foreignId('hr_person_id')->nullable()
                ->constrained('hr_people')->nullOnDelete();
            $table->foreignId('contractor_worker_id')->nullable()
                ->constrained('sheq_contractor_workers')->nullOnDelete();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->string('location_text', 255)->nullable();

            // ── The measurement ──────────────────────────────────────────────────────────────
            $table->decimal('value', 14, 4);
            $table->string('unit', 40)->nullable();

            /** PERSONAL, STATIC or BIOLOGICAL — what kind of sample it was. */
            $table->string('sample_type', 20)->default('PERSONAL');

            $table->decimal('duration_minutes', 10, 2)->nullable();
            $table->date('measured_on');

            $table->string('measured_by', 255)->nullable();
            $table->string('laboratory', 255)->nullable();
            $table->string('certificate_reference', 120)->nullable();

            /**
             * The limit in force when the measurement was taken, stamped.
             *
             * The single most important column in this table. Limits move — the silica OEL was
             * revised — and a result judged against today's limit would retrospectively make a
             * compliant year non-compliant, or the reverse. In a disease claim brought fourteen
             * years later, what matters is whether the exposure was within the limit AT THE TIME.
             */
            $table->decimal('limit_at_measurement', 14, 4)->nullable();
            $table->decimal('action_level_at_measurement', 14, 4)->nullable();

            /** And the conclusions, decided once and kept. */
            $table->boolean('exceeded_limit')->default(false);
            $table->boolean('exceeded_action_level')->default(false);

            /** What came of it (OH-6). */
            $table->foreignId('action_id')->nullable()
                ->constrained('sheq_actions')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'agent_id', 'measured_on'], 'sheq_exposure_agent_idx');
            $table->index(['company_id', 'hr_person_id'], 'sheq_exposure_person_idx');
            $table->index(['company_id', 'exceeded_limit'], 'sheq_exposure_exceeded_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_exposure_result_ref_uniq ON sheq_exposure_results '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /**
         * OH-5: the PPE that controls an agent.
         *
         * A join table rather than a column, because the relationship is many to many — a
         * respirator controls silica, dust and fumes, and silica is controlled by a respirator
         * AND by water suppression — and because the PPE catalogue already exists. Giving agents
         * their own list of protective equipment would leave a site with two lists of
         * respirators that disagree.
         */
        Schema::create('sheq_exposure_agent_ppe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('sheq_exposure_agents')->cascadeOnDelete();
            $table->foreignId('ppe_item_id')->constrained('sheq_ppe_items')->cascadeOnDelete();

            /**
             * Whether this PPE is required or merely one option.
             *
             * PPE is the weakest control in the hierarchy and the one most often treated as the
             * whole answer. Marking an item as required is a statement that nothing higher up
             * the hierarchy is available, which is worth being explicit about.
             */
            $table->boolean('is_required')->default(true);

            /** The minimum specification: an FFP3 rather than any dust mask. */
            $table->string('minimum_specification', 255)->nullable();

            $table->timestamps();

            $table->unique(['agent_id', 'ppe_item_id'], 'sheq_agent_ppe_uniq');
            $table->index(['company_id', 'agent_id'], 'sheq_agent_ppe_idx');
        });

        $this->applyTenantRls(['sheq_exposure_agents', 'sheq_exposure_groups',
            'sheq_exposure_group_members', 'sheq_exposure_results',
            'sheq_exposure_agent_ppe']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_exposure_agent_ppe');
        Schema::dropIfExists('sheq_exposure_results');
        Schema::dropIfExists('sheq_exposure_group_members');
        Schema::dropIfExists('sheq_exposure_groups');
        Schema::dropIfExists('sheq_exposure_agents');
    }

    /** The same row-level security every other SHEQ table carries. */
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
