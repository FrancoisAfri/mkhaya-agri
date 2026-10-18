<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company-designed approval workflows, shared by every part of SHEQ that needs a decision.
 *
 * Almost everything in this module ends with somebody accepting responsibility: a permit is issued,
 * a visitor is let on site, a non-conformance is closed, a supplier is approved to work. Each of
 * those had — or would have had — its own bespoke approval logic, and that is how a module ends up
 * with six subtly different ideas of what "approved" means, only one of which enforces separation
 * of duties.
 *
 * So there is one engine, and each company designs the steps. That second part is not a nicety: a
 * mine with a 2.6.1 appointee, a construction firm with a site agent, and a factory with a shift
 * manager have genuinely different chains of authority, and a hard-coded chain would be wrong for
 * all three.
 *
 * Three decisions worth stating:
 *
 * **Workflows are versioned, and a request keeps the version it started under.** If somebody edits
 * a workflow while approvals are in flight, the half-approved requests must continue under the
 * rules their approvers were shown. Otherwise a person who approved step 2 of 3 discovers they
 * actually approved step 2 of 5, or — worse — a step is removed and their approval silently becomes
 * the final one.
 *
 * **The requester can never approve their own request.** Not configurable, because the entire
 * purpose of an approval is that a second person looked. A workflow that permits self-approval is
 * a workflow that produces a signature without a decision.
 *
 * **A missing workflow blocks rather than passes.** An unconfigured approval means nobody has said
 * who may authorise this; treating that as "anyone may" is how an unapproved permit gets issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The design: what the steps are ────────────────────────────────────────────────────
        Schema::create('sheq_approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            /**
             * What this workflow approves — PERMIT, VISITOR, AUDIT_FINDING, SUPPLIER_ONBOARDING…
             *
             * A string rather than a foreign key: the set grows every time a new area is added, and
             * a lookup table would mean a migration to add an approval to a new feature.
             */
            $table->string('subject_type', 40)->index();

            // Optional narrowing, e.g. only for hot-work permits or only at one site. A company
            // routinely wants confined-space entry to need two approvers and a desk visit to need
            // one, and that distinction lives here rather than in nine `if` statements.
            $table->string('subject_subtype', 60)->nullable();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);

            /**
             * Hours before the whole request is escalated.
             *
             * Null means it waits indefinitely, which is occasionally right and usually how a
             * permit sits unapproved while a crew stands around.
             */
            $table->unsignedInteger('sla_hours')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'subject_type', 'is_active']);
        });

        Schema::create('sheq_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('workflow_id')->index();

            $table->unsignedInteger('position');
            $table->string('name', 160);

            /**
             * Who may act: ROLE, USER, LINE_MANAGER, SITE_ROLE, COMPETENCY_HOLDER, ANY_APPROVER.
             *
             * LINE_MANAGER and COMPETENCY_HOLDER are the two that make this worth building rather
             * than storing a list of user ids: "the requester's manager" and "somebody holding a
             * valid 2.6.1 appointment" are both resolved at the moment of approval, so the workflow
             * keeps working when people move jobs or a certificate lapses.
             */
            $table->string('approver_type', 30);
            $table->string('approver_ref', 160)->nullable();

            /**
             * How many approvals this step needs.
             *
             * A step naming a role with eleven holders and a quorum of two means any two of them.
             */
            $table->unsignedInteger('quorum')->default(1);

            $table->unsignedInteger('sla_hours')->nullable();

            /** A step that may be skipped when nobody can fill it, rather than deadlocking. */
            $table->boolean('is_optional')->default(false);

            /** Whether this step's approver must sign, not merely click. */
            $table->boolean('requires_signature')->default(false);

            $table->timestamps();

            $table->unique(['workflow_id', 'position'], 'sheq_approval_steps_order_unique');
        });

        // ── The instance: one thing awaiting approval ─────────────────────────────────────────
        Schema::create('sheq_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->unsignedBigInteger('workflow_id');

            /**
             * A frozen copy of the steps as they stood when this request opened.
             *
             * The whole reason workflows are versioned. Editing a live workflow must not rewrite
             * what an approver already agreed to, and a join to the current steps would do exactly
             * that.
             */
            $table->jsonb('workflow_snapshot');
            $table->unsignedInteger('workflow_version');

            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label', 255)->nullable();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            /** PENDING, APPROVED, REJECTED, CANCELLED, EXPIRED. */
            $table->string('status', 20)->default('PENDING')->index();

            $table->unsignedInteger('current_position')->default(1);

            $table->unsignedBigInteger('requested_by');
            $table->text('request_note')->nullable();
            $table->jsonb('context')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('outcome_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'status', 'due_at']);
        });

        // ── The decisions ─────────────────────────────────────────────────────────────────────
        Schema::create('sheq_approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('request_id')->index();

            $table->unsignedInteger('position');
            $table->string('step_name', 160);

            $table->unsignedBigInteger('decided_by');

            /** APPROVED, REJECTED, DELEGATED, SKIPPED. */
            $table->string('decision', 20);

            $table->text('note')->nullable();

            // Base64 in the database, like every other signature in this platform.
            $table->longText('signature')->nullable();

            /** Who they were acting as, so "the manager approved it" is checkable later. */
            $table->string('acted_as', 160)->nullable();
            $table->unsignedBigInteger('delegated_to')->nullable();

            $table->timestamp('decided_at');
            $table->timestamps();

            // One decision per person per step. Approving twice must not count towards a quorum.
            $table->unique(['request_id', 'position', 'decided_by'], 'sheq_approval_once_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            foreach (['sheq_approval_workflows', 'sheq_approval_steps',
                'sheq_approval_requests', 'sheq_approval_decisions'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_approval_decisions');
        Schema::dropIfExists('sheq_approval_requests');
        Schema::dropIfExists('sheq_approval_steps');
        Schema::dropIfExists('sheq_approval_workflows');
    }
};
