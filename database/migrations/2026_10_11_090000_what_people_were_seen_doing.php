<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safety observations, and the behaviour behind them (OBS-1 to OBS-4).
 *
 * The module recorded things that went wrong: incidents, hazards, failed inspections. All three
 * are reports of a condition or an outcome, and none of them records what somebody was seen
 * DOING. That is the gap behavioural safety exists to fill, and it is the larger half of why
 * sites get hurt — a hazard register tells you the guard rail is missing, and nothing tells you
 * that three different crews have been climbing the scaffold without clipping on.
 *
 * Four kinds, and the two that are not about failure matter as much as the two that are.
 *
 * A POSITIVE observation is the whole mechanism by which this stops being a punishment system.
 * A register that only ever records people doing wrong teaches a workforce that being observed is
 * a bad thing, and then nobody reports anything — including the near misses. Recording good
 * practice is not decoration; it is what makes the unsafe ones survivable to report.
 *
 * An INTERVENTION is somebody stopping work. That is the single most valuable act anybody on a
 * site performs and it was unrecordable, so a person who stopped a crew had nothing to show for
 * it and nothing protected them if it was questioned afterwards.
 *
 * COACHING is the conversation that followed. Separate from the intervention because they are
 * different events with different authors: a supervisor stops the lift, and a trainer coaches the
 * rigger the next morning.
 *
 * The behaviour vocabulary is a closed list for the same reason the activity catalogue is.
 * OBS-4 asks for recurring unsafe behaviours by site, contractor, activity, department and
 * period — and recurrence cannot be found in free text. "Not clipped on", "no harness" and
 * "working at height unsecured" are one behaviour typed three ways, and a register that cannot
 * group them produces a report that says every behaviour happened once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /** OBS-NNNN. Quoted in a toolbox talk, so it has to be sayable out loud. */
            $table->string('reference', 40);

            /** POSITIVE, UNSAFE, INTERVENTION or COACHING (OBS-1). */
            $table->string('observation_type', 20);

            // ── What was seen (OBS-2) ────────────────────────────────────────────────────────
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /** The activity code, from the same catalogue the gate reads. */
            $table->string('activity', 60)->nullable();

            $table->string('location_text', 255)->nullable();

            /**
             * Who it was about. Any of these, or none.
             *
             * None is a real and common case: a crew observed from a distance, or a behaviour
             * nobody wants attributed to an individual because attributing it is how a
             * behavioural programme turns into a disciplinary one. The observation is still
             * worth recording — it is the behaviour that gets counted, not the person.
             */
            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete();
            $table->foreignId('contractor_worker_id')->nullable()
                ->constrained('sheq_contractor_workers')->nullOnDelete();
            $table->foreignId('hr_person_id')->nullable()
                ->constrained('hr_people')->nullOnDelete();

            /**
             * The department, resolved when the observation was made and then left alone.
             *
             * Stored rather than derived at read time, because departments are reorganised and a
             * trend by department has to describe the organisation as it was. Deriving it live
             * would move last year's observations into this year's structure and make a
             * three-year trend meaningless.
             */
            $table->unsignedBigInteger('department_unit_id')->nullable();

            /** The behaviour, from the vocabulary, so OBS-4 can group on it. */
            $table->string('behaviour_code', 60)->nullable();

            /** And in words, because the code is for counting and this is for reading. */
            $table->text('behaviour')->nullable();

            /**
             * What the risk was, had it carried on. Not what happened — nothing happened, which
             * is the point of an observation.
             */
            $table->string('risk_rating', 20)->nullable();

            /** What the observer did there and then (OBS-2). */
            $table->text('immediate_action')->nullable();

            /** Whether work was stopped. An intervention is the fact worth counting separately. */
            $table->boolean('work_stopped')->default(false);

            // ── Who saw it, and when ─────────────────────────────────────────────────────────
            $table->timestampTz('observed_at')->index();
            $table->foreignId('observed_by')->nullable()->constrained('users')->nullOnDelete();

            /**
             * Reported without a name attached.
             *
             * The observer, not the subject. Somebody reporting their own supervisor's shortcut
             * will not do it under their own name, and an observation nobody dares file is worth
             * nothing. The user id is still stored — audit requires knowing a real person filed
             * it — and the screens hide it.
             */
            $table->boolean('is_anonymous')->default(false);

            /** OPEN, ACTIONED or CLOSED. */
            $table->string('status', 20)->default('OPEN');

            /** The CAPA raised from it (OBS-3), where one was. */
            $table->foreignId('action_id')->nullable()
                ->constrained('sheq_actions')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The three shapes OBS-4 asks for: by site, by contractor, by behaviour over a period.
            $table->index(['company_id', 'site_id', 'observed_at'], 'sheq_obs_site_idx');
            $table->index(['company_id', 'contractor_id', 'observed_at'], 'sheq_obs_contractor_idx');
            $table->index(['company_id', 'behaviour_code', 'observed_at'], 'sheq_obs_behaviour_idx');
            $table->index(['company_id', 'observation_type'], 'sheq_obs_type_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_observation_reference_uniq ON sheq_observations '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /**
         * The behaviours a site actually sees, per tenant and editable.
         *
         * A table rather than a constant because a mine and a kitchen do not share a list, and
         * the one thing that kills a behavioural programme is a vocabulary that does not describe
         * the work — observers then pick the nearest wrong option, and the report counts the
         * wrong thing with great confidence.
         */
        Schema::create('sheq_behaviours', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code', 60);
            $table->string('name', 160);

            /** The grouping a report leads with: PPE, procedure, housekeeping, and so on. */
            $table->string('category', 60)->nullable();
            $table->text('description')->nullable();

            /**
             * Whether this behaviour is one somebody could be hurt by immediately.
             *
             * Carried so the recurrence report can lead with the ones that matter. Three
             * housekeeping observations and three not-clipped-on observations are the same number
             * and not the same problem.
             */
            $table->boolean('is_high_consequence')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active'], 'sheq_behaviour_active_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_behaviour_code_uniq ON sheq_behaviours '
            .'(company_id, code) WHERE deleted_at IS NULL'
        );

        $this->applyTenantRls(['sheq_observations', 'sheq_behaviours']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_observations');
        Schema::dropIfExists('sheq_behaviours');
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
