<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The emergency command centre (EMR-1 to EMR-6).
 *
 * Emergency plans and drills already existed, and they are the paperwork: the procedure, the
 * assembly points, the contacts, the evidence that the procedure was rehearsed. What did not
 * exist is the thing that uses them — a declared emergency, happening now, with a list of the
 * people who are inside the fence and a record of which of them have been found.
 *
 * Four tables and two columns.
 *
 * **`sheq_emergencies`** is the declaration itself (EMR-1): the site, the type, who is commanding
 * it, when it started and the instruction that went out. One active emergency per site, enforced
 * by a partial unique index rather than by the code that writes it, because a second declaration
 * during a fire would split the roll call in two and each half would look complete.
 *
 * **`sheq_emergency_roll_call`** is EMR-2 and EMR-3 together, and the reason it is a table rather
 * than a query is that it has to hold a judgement that no other table can derive: that a person
 * is MISSING. Everything else about occupancy is already recorded, but "we have not found Thabo"
 * is a statement somebody makes, at a time, and it is the single most consequential row in this
 * module. It carries its own copy of the person's name: the roll call must still read correctly
 * in an enquiry two years later, after the attendance row has been archived and the contractor
 * has been deleted.
 *
 * It also records the source each person came from, because there are two and a roll call built
 * from one of them is worse than no roll call at all. Employees and contractors are in
 * `sheq_site_attendance`; visitors are in `sheq_visits`. Nothing joins them. A list that reads
 * only the first one is complete, plausible, and leaves the visitor in the building.
 *
 * **`sheq_emergency_resources`** is the half of EMR-4 that nothing else holds: the fire
 * extinguishers and spill kits on the site, and the hospital, fire station and ambulance service
 * nearest to it. Assembly points, contacts and the command structure are already on the plan and
 * are not duplicated here.
 *
 * **`sheq_emergency_drill_attendance`** is EMR-6's attendance. The drills table counts
 * participants, which is enough to compute a percentage and not enough to answer the question a
 * drill exists to answer — whether the people who were on site that day actually came out.
 *
 * **`sheq_hazardous_substances`** is the rest of EMR-5, and it is here because the module turned
 * out not to have it. Waste movements looked like the place to find hazardous materials standing
 * on a site, and they are not: `removed_on` is NOT NULL on that table, so a movement row is a
 * record of waste that has *left*. Exposure agents are what people are monitored against, which
 * is a different question again. Nothing held the inventory — the drums in the store, what they
 * do when they burn, and what must not be sprayed on them — which is the single thing a fire
 * officer asks for on arrival, and is required of a chemical inventory under the Hazardous
 * Chemical Agents Regulations in any case.
 *
 * And two columns:
 *
 * `sheq_competencies.emergency_role` is how the other half of EMR-4 is answered without a second
 * roster to maintain. A first aider is a person holding a current first-aid certificate, and the
 * certificate's expiry is already tracked here; a hand-kept list of first aiders is stale the day
 * somebody's certificate lapses, and in an emergency it sends people to someone who may no longer
 * be qualified. So a tenant marks which of their own competencies confer which emergency role,
 * and the roster derives itself from records that are already kept current.
 *
 * `sheq_emergency_drills.scenario` is EMR-6's scenario. `drill_type` is a category — FIRE,
 * EVACUATION — and the scenario is the narrative that was rehearsed, which is the part a finding
 * refers back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_emergencies', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sheq_sites')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('sheq_emergency_plans')->nullOnDelete();
            $table->string('reference', 40);
            $table->string('emergency_type', 40);

            // The person in command, and the person who pressed the button. Usually not the same:
            // a guard declares, a manager commands.
            $table->foreignId('incident_commander_id')->nullable()->constrained('hr_people')->nullOnDelete();
            $table->string('incident_commander_name')->nullable();
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declared_at');
            $table->text('instructions')->nullable();

            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('stood_down_at')->nullable();
            $table->foreignId('stood_down_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('stand_down_notes')->nullable();

            // Standing down with people still unaccounted for is sometimes the right call — the
            // building is clear and the two names are people who left without signing out. It is
            // never a thing that should happen quietly, so it takes a reason and the reason is
            // kept.
            $table->text('stood_down_with_unaccounted_reason')->nullable();
            $table->unsignedInteger('unaccounted_at_stand_down')->nullable();

            $table->foreignId('incident_id')->nullable()->constrained('sheq_incidents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'site_id', 'status'], 'sheq_emergency_site_idx');
            $table->index(['company_id', 'declared_at'], 'sheq_emergency_when_idx');
        });

        Schema::create('sheq_emergency_roll_call', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emergency_id')->constrained('sheq_emergencies')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sheq_sites')->cascadeOnDelete();

            // Which of the two occupancy tables this person came from, so a roll call that is
            // missing a source can be seen to be missing it.
            $table->string('source', 20);
            $table->uuid('source_reference')->nullable();

            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id')->nullable();

            // Copied, not joined. See the class note.
            $table->string('person_name');
            $table->string('contractor_name')->nullable();
            $table->foreignId('contractor_id')->nullable()->constrained('sheq_contractors')->nullOnDelete();
            $table->timestamp('entered_at')->nullable();

            $table->string('status', 20)->default('UNACCOUNTED');
            $table->timestamp('status_at')->nullable();
            $table->foreignId('status_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            // Where they were found, which is what a rescue team is told.
            $table->string('location_text')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'emergency_id', 'status'], 'sheq_rollcall_status_idx');
            $table->index(['emergency_id', 'source', 'source_reference'], 'sheq_rollcall_source_idx');
        });

        Schema::create('sheq_emergency_resources', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Null means every site: one ambulance service usually covers them all.
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->cascadeOnDelete();

            $table->string('kind', 20);
            $table->string('category', 40);
            $table->string('name');
            $table->string('location_text')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->decimal('distance_km', 6, 2)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->text('notes')->nullable();

            // A fire extinguisher with a lapsed service date is a prop. The board says so.
            $table->date('last_inspected_on')->nullable();
            $table->date('next_inspection_due')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'site_id', 'kind'], 'sheq_emergency_resource_idx');
        });

        Schema::create('sheq_hazardous_substances', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sheq_sites')->cascadeOnDelete();

            $table->string('name');
            $table->string('un_number', 20)->nullable();
            $table->string('hazard_class', 40)->nullable();
            $table->string('ghs_classification')->nullable();

            $table->decimal('quantity', 12, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('location_text')->nullable();
            $table->string('storage_conditions')->nullable();

            // What makes it dangerous to the people fighting the fire rather than to the people
            // working with it: what it does when it burns, and what must not be used on it.
            $table->text('fire_fighting_media')->nullable();
            $table->text('incompatible_with')->nullable();
            $table->boolean('is_flammable')->default(false);
            $table->boolean('is_toxic')->default(false);

            // The safety data sheet, which is the document the fire service asks for by name.
            $table->foreignId('sds_document_id')->nullable()->constrained('sheq_documents')->nullOnDelete();
            $table->date('sds_reviewed_on')->nullable();

            $table->foreignId('agent_id')->nullable()->constrained('sheq_exposure_agents')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'site_id', 'is_active'], 'sheq_hazmat_site_idx');
        });

        Schema::create('sheq_emergency_drill_attendance', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drill_id')->constrained('sheq_emergency_drills')->cascadeOnDelete();

            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('person_name');
            $table->string('contractor_name')->nullable();

            $table->boolean('was_present')->default(true);

            // How long this person took to reach the assembly point. The drill's own
            // `evacuation_seconds` is the whole site's time, which is the slowest person's time
            // and says nothing about who that was.
            $table->unsignedInteger('accounted_seconds')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'drill_id'], 'sheq_drill_attendance_idx');
        });

        Schema::table('sheq_competencies', function (Blueprint $table) {
            $table->string('emergency_role', 30)->nullable()->after('competency_type');
        });

        Schema::table('sheq_emergency_drills', function (Blueprint $table) {
            $table->text('scenario')->nullable()->after('drill_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            // One active emergency per site. A second declaration during the first one splits the
            // roll call, and each half looks complete. Postgres never collides NULLs, so this has
            // to be a partial index rather than a nullable unique key.
            DB::statement("CREATE UNIQUE INDEX sheq_one_active_emergency_per_site
                ON sheq_emergencies (company_id, site_id)
                WHERE status = 'ACTIVE' AND deleted_at IS NULL");

            // A person appears on a roll call once. Re-reading occupancy during an emergency has
            // to be able to add the people who have since walked in without duplicating the ones
            // already there — and a duplicate row is not a cosmetic problem here: it is a name
            // that can be marked accounted for in one row and missing in the other.
            DB::statement('CREATE UNIQUE INDEX sheq_rollcall_one_row_per_person
                ON sheq_emergency_roll_call (emergency_id, source, source_reference)
                WHERE source_reference IS NOT NULL');
        }

        $this->applyTenantRls([
            'sheq_emergencies', 'sheq_emergency_roll_call', 'sheq_emergency_resources',
            'sheq_emergency_drill_attendance', 'sheq_hazardous_substances',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_hazardous_substances');
        Schema::dropIfExists('sheq_emergency_drill_attendance');
        Schema::dropIfExists('sheq_emergency_resources');
        Schema::dropIfExists('sheq_emergency_roll_call');
        Schema::dropIfExists('sheq_emergencies');

        Schema::table('sheq_competencies', function (Blueprint $table) {
            $table->dropColumn('emergency_role');
        });

        Schema::table('sheq_emergency_drills', function (Blueprint $table) {
            $table->dropColumn('scenario');
        });
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
