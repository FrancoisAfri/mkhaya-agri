<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Management of change (MOC-1 to MOC-5).
 *
 * Every other register in this module records something that has already happened: an incident,
 * a hazard, an observation, an inspection. Management of change is the only one that is about
 * something that has NOT happened yet, and that is the whole of its value — it is the only place
 * where a safety system can act before the consequences rather than after them.
 *
 * The thing it exists to prevent is specific and extremely common: a change made competently, by
 * people who knew what they were doing, whose knock-on effects nobody traced. A pump is replaced
 * with a larger one and the hot-work permit limits are now wrong. A chemical is substituted and
 * four SDSs, two procedures and the spill plan are now wrong. A contractor is replaced and their
 * safety file, inductions and site approvals are now somebody else's. Nothing failed; something
 * was simply not followed through.
 *
 * So the register holds two things rather than one. The change, and the list of what the change
 * invalidates — and MOC-4 makes the second a gate on the first. A change that cannot be
 * implemented until its mandatory controls are complete is the only version of this feature that
 * does anything; a register of changes with a checklist nobody has to finish is a register of
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference', 40);
            $table->string('title', 255);
            $table->text('description')->nullable();

            /** One of the nine kinds MOC-1 lists. */
            $table->string('change_category', 40);

            /** Whether it is permanent, temporary, or an emergency change made under pressure. */
            $table->string('change_nature', 20)->default('PERMANENT');

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /**
             * The contractor being changed, where the change IS about a contractor.
             *
             * MOC-1 lists contractors as a kind of change, and that case has consequences the
             * others do not: their safety file, their inductions and their site approvals all
             * belong to the outgoing firm.
             */
            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete();

            // ── Risk, which decides who may approve it (MOC-3) ───────────────────────────────
            /**
             * LOW, MEDIUM, HIGH or CRITICAL.
             *
             * Drives the approval workflow chosen for the change, as its subtype — so a host
             * configures "who approves a high-risk change" once and every high-risk change
             * routes there. A single approver for every change is the version of this that gets
             * rubber-stamped.
             */
            $table->string('risk_rating', 20)->nullable();

            /** What the change touches, in the requester's own words. Read by the approver. */
            $table->text('impact_summary')->nullable();

            // ── Where it is up to ────────────────────────────────────────────────────────────
            /**
             * DRAFT, SUBMITTED, APPROVED, REJECTED, IMPLEMENTED, REVIEWED or CANCELLED.
             *
             * IMPLEMENTED and REVIEWED are separate states because MOC-5 requires a review after
             * the fact, and a change marked done is exactly the change nobody goes back to.
             */
            $table->string('status', 20)->default('DRAFT');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('requested_at')->nullable();

            $table->date('planned_date')->nullable();
            $table->timestampTz('implemented_at')->nullable();
            $table->foreignId('implemented_by')->nullable()->constrained('users')->nullOnDelete();

            /** The approval request this change is waiting on, where one was opened. */
            $table->unsignedBigInteger('approval_request_id')->nullable();

            // ── MOC-5: did it work ───────────────────────────────────────────────────────────
            /**
             * When the effectiveness review falls due, set when the change is implemented.
             *
             * A date rather than an intention. "We will review it" with no date is the same as
             * no review, and the overdue sweep needs something to compare against.
             */
            $table->date('effectiveness_review_due')->nullable();
            $table->timestampTz('effectiveness_reviewed_at')->nullable();
            $table->foreignId('effectiveness_reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            /** EFFECTIVE, PARTIALLY_EFFECTIVE or NOT_EFFECTIVE, and why. */
            $table->string('effectiveness_result', 30)->nullable();
            $table->text('effectiveness_notes')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status'], 'sheq_change_status_idx');
            $table->index(['company_id', 'site_id'], 'sheq_change_site_idx');
            // The overdue-review sweep reads exactly this.
            $table->index(['company_id', 'effectiveness_review_due'], 'sheq_change_review_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_change_reference_uniq ON sheq_changes '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /**
         * What the change invalidates, and whether it has been dealt with (MOC-2, MOC-4).
         *
         * Rows rather than a JSON blob on the change, because each one is worked on
         * independently by a different person: the HIRA by a risk assessor, the induction by a
         * trainer, the emergency plan by whoever owns it. A blob cannot be assigned, cannot be
         * signed off individually, and cannot be queried for "what is outstanding across every
         * open change", which is the one report a SHEQ manager actually needs from this.
         */
        Schema::create('sheq_change_impacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('change_id')->constrained('sheq_changes')->cascadeOnDelete();

            /** One of the eight kinds MOC-2 lists. */
            $table->string('impact_type', 40);

            /**
             * The actual record affected, where one was identified.
             *
             * Nullable because some impacts are known without being locatable: a change to
             * operating hours affects the emergency plan whether or not this system holds a plan
             * for that site. Naming the gap is more useful than omitting the row.
             */
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            /** What it is, for a screen: the reference and title as they were when detected. */
            $table->string('subject_reference', 80)->nullable();
            $table->string('subject_title', 255)->nullable();

            /**
             * Whether this system found it or a person added it.
             *
             * Worth keeping apart. An automatic list that people trust completely is dangerous,
             * and one they add to is working as intended — showing which is which is how a
             * reviewer knows whether anybody actually thought about it.
             */
            $table->boolean('detected_automatically')->default(true);

            /**
             * Whether the change is blocked until this is done (MOC-4).
             *
             * Not every impact is a blocker. Reviewing a quality specification can reasonably
             * follow a change; re-inducting the crew cannot. Making everything mandatory means
             * the first change is implemented by somebody switching the gate off.
             */
            $table->boolean('is_mandatory')->default(true);

            /** OUTSTANDING, DONE or NOT_APPLICABLE. */
            $table->string('status', 20)->default('OUTSTANDING');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();

            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            /**
             * What was done about it, or why it does not apply.
             *
             * Required by the service when somebody marks an impact NOT_APPLICABLE: a mandatory
             * control dismissed without a reason is the hole in this whole feature, and it is the
             * easiest one to walk through under deadline pressure.
             */
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'change_id', 'status'], 'sheq_change_impact_idx');
            $table->index(['company_id', 'status', 'due_date'], 'sheq_change_outstanding_idx');
        });

        $this->applyTenantRls(['sheq_changes', 'sheq_change_impacts']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_change_impacts');
        Schema::dropIfExists('sheq_changes');
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
