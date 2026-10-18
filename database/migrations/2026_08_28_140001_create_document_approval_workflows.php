<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval workflows.
 *
 * A workflow is a reusable definition — "Contracts: manager, then finance" — and a request is one
 * document going through it. Separating them is what lets the definition be edited without
 * rewriting the history of everything that already went through the old one.
 *
 * Approval status is its own column rather than more values in `status`. The existing one answers
 * whether a document is active or archived, which stays true throughout an approval and after it;
 * conflating the two would mean a rejected document could not also be archived, and "archived"
 * would silently lose whether it had ever been approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('name');
            $table->text('description')->nullable();

            // Which documents this is offered for. Null means any — an organisation with one
            // workflow should not have to enumerate every category to use it.
            $table->unsignedBigInteger('category_id')->nullable();

            $table->boolean('is_active')->default(true);

            /*
             * Whether approval is required before a document counts as published.
             *
             * Off means the workflow is available but optional, which is the common case: most
             * documents are filed, not approved, and forcing every upload through review is how a
             * repository becomes something people route around.
             */
            $table->boolean('is_mandatory')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('document_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('workflow_id');

            $table->string('name');                       // "Manager review", "Finance review"
            $table->unsignedInteger('position')->default(1);

            /*
             * How this step's approvers relate to each other.
             *
             * 'all' is everybody named must approve — two directors signing off a contract.
             * 'any' is one of them is enough — whichever manager is at their desk.
             *
             * Sequential versus parallel is the relationship between *steps*, and falls out of
             * `position`: two steps sharing a position run at once, consecutive positions run in
             * order. That is simpler than a flag, and it makes a diagram of the workflow the same
             * shape as the data.
             */
            $table->string('quorum', 10)->default('all');

            // Who may act. A role is the usual case; a named user is for the exception.
            $table->string('approver_type', 20)->default('role');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->string('approver_name')->nullable();

            // How long this step gets. Null means no deadline — a step nobody is chasing.
            $table->unsignedInteger('deadline_days')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'workflow_id', 'position']);
        });

        Schema::create('document_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('workflow_id')->nullable();

            // The version being approved. An approval is about specific bytes — approving a
            // contract and then replacing the file would otherwise leave the approval attached to
            // something nobody agreed to.
            $table->unsignedBigInteger('version_id')->nullable();

            $table->string('status', 30)->default('submitted');
            $table->unsignedInteger('current_position')->default(1);

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('outcome_note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'document_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('document_approval_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('step_id')->nullable();

            $table->unsignedInteger('position')->default(1);
            $table->string('step_name')->nullable();

            // Who was asked, and who is actually answering. Delegation keeps both: the audit trail
            // has to say the request went to the finance manager and their deputy answered it.
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('assigned_role')->nullable();
            $table->unsignedBigInteger('delegated_to')->nullable();
            $table->timestamp('delegated_at')->nullable();
            $table->string('delegation_reason')->nullable();

            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('comment')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('last_reminded_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'request_id', 'position']);
            $table->index(['company_id', 'status', 'due_at']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::table('documents', function (Blueprint $table) {
            // Draft until somebody submits it. The states from the requirement, kept apart from
            // active/archived for the reason in the class comment.
            $table->string('approval_status', 30)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();

            $table->index(['company_id', 'approval_status']);
        });

        foreach ([
            'document_approval_workflows',
            'document_approval_steps',
            'document_approval_requests',
            'document_approval_assignments',
        ] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant ON {$table} USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            )");
        }

        /*
         * Documents that already exist were never submitted for approval, and calling them drafts
         * would put the entire library into a review queue nobody asked for.
         */
        DB::statement("UPDATE documents SET approval_status = 'published', published_at = created_at");
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'published_at', 'approval_request_id']);
        });

        Schema::dropIfExists('document_approval_assignments');
        Schema::dropIfExists('document_approval_requests');
        Schema::dropIfExists('document_approval_steps');
        Schema::dropIfExists('document_approval_workflows');
    }
};
