<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — approve: the case, who may move it, and what a decision has to carry.
 *
 * Approval today is `supplier_kyc_approvals`: pending, approved, rejected. That is enough to record
 * a yes and nowhere near enough to defend one. It cannot express a supplier waiting on enhanced due
 * diligence, a conditional approval with a deadline, a compliance sign-off distinct from a
 * procurement one, or the reason any of it happened — and none of those are optional once somebody
 * asks why a supplier who later defrauded them was cleared.
 *
 *  dd_cases             one engagement, its state, its owner, its clock, and its decision
 *  dd_case_transitions  every move, with who made it and what role they held AT THE TIME, because a
 *                       person's role changes and what they held when they approved does not
 *  dd_edd_tasks         the enhanced due-diligence pack: what extra was demanded and what came back
 *  supplier_blacklist   entities and people barred, with the evidence and a review date
 *
 * The existing KYC queue is left exactly as it is. A tenant without the due-diligence add-on keeps
 * approving suppliers the way it does today; nothing here replaces that path, it stands beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dd_cases', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->string('reference', 40)->nullable();          // DD-2026-0042

            $t->string('state', 40)->default('draft');
            $t->unsignedTinyInteger('tier')->nullable();
            $t->decimal('risk_score', 6, 2)->nullable();
            $t->string('risk_band', 20)->nullable();

            // Who raised it, and who owns it now. Separate because the owner changes as the case moves
            // between procurement and compliance, and the raiser never does — that is the pair the
            // separation-of-duties rule compares.
            $t->unsignedBigInteger('raised_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('owner_slot', 40)->nullable();          // which role slot currently holds it

            // The clock. A case with no due date is one nobody is late on.
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->string('decided_role', 60)->nullable();
            $t->text('decision_reason')->nullable();

            // Conditional approval: permitted, but only with conditions and a date they expire on.
            // An "approved subject to" with no deadline is an approval.
            $t->json('conditions')->nullable();
            $t->date('conditions_due')->nullable();
            $t->timestamp('conditions_met_at')->nullable();

            $t->string('closed_reason', 40)->nullable();       // approved | rejected | superseded | withdrawn
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'state'], 'dd_cases_state_idx');
            $t->index(['company_id', 'supplier_id', 'created_at'], 'dd_cases_supplier_idx');
        });

        Schema::create('dd_case_transitions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('case_id')->index();

            $t->string('from_state', 40)->nullable();
            $t->string('to_state', 40);
            $t->string('action', 40);                          // submit | verify | approve | reject | …

            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('actor_label', 190)->nullable();
            // The slot they acted in, captured now. Roles change; what somebody held when they
            // approved a supplier does not.
            $t->string('actor_slot', 40)->nullable();
            $t->string('actor_roles', 190)->nullable();

            $t->text('reason')->nullable();
            $t->json('context')->nullable();                   // gates, blockers, score at the time
            $t->timestamp('occurred_at');
            $t->timestamps();

            $t->index(['company_id', 'case_id', 'occurred_at'], 'dd_case_transitions_history_idx');
        });

        Schema::create('dd_edd_tasks', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('case_id')->index();
            $t->unsignedBigInteger('supplier_id')->index();

            $t->string('code', 60);                            // financial_statements | site_visit | …
            $t->string('label', 190);
            $t->text('why')->nullable();                       // the trigger that demanded it
            $t->boolean('is_required')->default(true);

            $t->string('status', 20)->default('outstanding');   // outstanding | provided | waived | failed
            $t->text('response')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->text('waiver_reason')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'case_id', 'code'], 'dd_edd_tasks_unique');
        });

        Schema::create('supplier_blacklist', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();

            // Nullable: an entity can be barred before it is ever a supplier, which is the point of
            // checking at onboarding rather than after.
            $t->unsignedBigInteger('supplier_id')->nullable()->index();
            $t->string('entity_name', 250);
            $t->string('normalised_name', 250)->nullable()->index();
            $t->string('registration_number', 60)->nullable()->index();

            $t->string('scope', 20)->default('entity');        // entity | person
            $t->unsignedBigInteger('person_id')->nullable();
            $t->string('person_identifier_hash', 64)->nullable();  // hashed, never the number itself

            $t->text('reason');
            $t->json('evidence')->nullable();
            $t->string('severity', 20)->default('permanent');   // permanent | fixed_term

            $t->unsignedBigInteger('decided_by')->nullable();
            $t->string('decided_role', 60)->nullable();
            $t->timestamp('decided_at');
            $t->date('review_on')->nullable();
            $t->date('expires_on')->nullable();

            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('lifted_by')->nullable();
            $t->timestamp('lifted_at')->nullable();
            $t->text('lift_reason')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_active'], 'supplier_blacklist_active_idx');
        });

        // The four role slots, per tenant. Stored as configuration rather than as Spatie roles
        // because the platform's roles are still global: a tenant that maps "Compliance Officer" to
        // its own role must not thereby define that role for every other tenant.
        Schema::table('supplier_settings', function (Blueprint $t) {
            $t->json('dd_roles')->nullable()->after('auto_approve_below');
            $t->unsignedSmallInteger('case_sla_days')->default(10)->after('dd_roles');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['dd_cases', 'dd_case_transitions', 'dd_edd_tasks', 'supplier_blacklist'] as $table) {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::table('supplier_settings', function (Blueprint $t) {
            $t->dropColumn(['dd_roles', 'case_sla_days']);
        });

        Schema::dropIfExists('supplier_blacklist');
        Schema::dropIfExists('dd_edd_tasks');
        Schema::dropIfExists('dd_case_transitions');
        Schema::dropIfExists('dd_cases');
    }
};
