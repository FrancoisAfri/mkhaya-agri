<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A safety file is submitted, reviewed and approved (CON-7).
 *
 * Until now a contractor's file was a set of documents with a computed compliance status, and that
 * conflates two different questions. "Is every required document on file and in date" is arithmetic.
 * "Has a competent person looked at this file and accepted it" is a decision, and it is the one a
 * client's auditor asks about: a file can hold every demanded certificate and still be rubbish —
 * the method statement describes different work, the appointment names somebody who left.
 *
 * So the file carries a state of its own, and it is versioned. A version is the unit of review: the
 * contractor submits, somebody reviews, and either it is approved or changes are requested and the
 * next submission is version 2. Without versions "rejected then fixed" has nowhere to live and the
 * rejection reason gets overwritten by the approval — which is precisely the history an auditor
 * wants.
 *
 * The review decision itself is not implemented here. `ApprovalService` already owns steps,
 * segregation of duties, delegation, signatures and a frozen workflow snapshot, and `CONTRACTOR` is
 * already one of its subject types. A second decision mechanism beside it would be a second place
 * for "who may approve" to be answered differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sheq_contractors')) {
            return;
        }

        if (! Schema::hasTable('sheq_safety_file_submissions')) {
            Schema::create('sheq_safety_file_submissions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('contractor_id')
                    ->constrained('sheq_contractors')->cascadeOnDelete();

                // The unit of review. Version 2 exists because version 1 was sent back.
                $table->unsignedSmallInteger('version')->default(1);

                $table->string('state', 24)->default('DRAFT');

                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('submitted_at')->nullable();

                // Who reviewed it, and when they decided. Named on the row rather than only in the
                // approval request, because this is the field an auditor reads.
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('reviewed_at')->nullable();

                // Why it was sent back or refused, and what specifically has to change. Separate
                // fields: a reason is for the record, requested changes are a list somebody works
                // through, and collapsing them into one text box loses the second.
                $table->text('decision_reason')->nullable();
                $table->jsonb('requested_changes')->nullable();
                $table->text('comments')->nullable();

                /*
                 * An approval expires. A safety file approved last March says nothing about the
                 * insurance that lapsed in June, so the approval carries its own validity and the
                 * file falls out of APPROVED when it passes.
                 */
                $table->date('valid_until')->nullable();

                // The approval request this version's decision came through, where a workflow is
                // configured. Null where the tenant has none and the reviewer decided directly.
                $table->foreignId('approval_request_id')->nullable()
                    ->constrained('sheq_approval_requests')->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'contractor_id', 'version'], 'sheq_sf_version_uniq');
                $table->index(['company_id', 'contractor_id', 'state']);
            });

            self::protect('sheq_safety_file_submissions');
        }

        /*
         * The approval history, as transitions.
         *
         * Append-only and never updated: the question "what state was this in, when, and who moved
         * it" is the whole point, and a row that can be edited answers it only until somebody edits
         * it. The audit log carries the same facts for the estate; this table is what the screen
         * reads, which is why it exists as well.
         */
        if (! Schema::hasTable('sheq_safety_file_transitions')) {
            Schema::create('sheq_safety_file_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('submission_id')
                    ->constrained('sheq_safety_file_submissions')->cascadeOnDelete();

                $table->string('from_state', 24)->nullable();
                $table->string('to_state', 24);

                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('occurred_at')->useCurrent();
                $table->text('reason')->nullable();
                $table->text('comment')->nullable();

                $table->timestamps();

                $table->index(['company_id', 'submission_id', 'id']);
            });

            self::protect('sheq_safety_file_transitions');
        }

        if (Schema::hasTable('sheq_settings')
            && ! Schema::hasColumn('sheq_settings', 'safety_file_validity_months')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('safety_file_validity_months')->nullable()
                    ->comment('How long a safety file approval stands; 12 when unset.');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_safety_file_transitions');
        Schema::dropIfExists('sheq_safety_file_submissions');

        if (Schema::hasColumn('sheq_settings', 'safety_file_validity_months')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->dropColumn('safety_file_validity_months');
            });
        }
    }

    /** Tenant isolation, matching every other SHEQ table. */
    private static function protect(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id
            SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        ) WITH CHECK (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");
    }
};
