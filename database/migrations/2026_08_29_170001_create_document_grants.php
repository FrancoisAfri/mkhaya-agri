<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sharing one document with somebody inside the organisation.
 *
 * This is document-level permission and internal sharing at once, because they are the same act
 * described from two ends: "share this contract with the finance manager" and "give the finance
 * manager access to this contract" produce the same row, and building them separately would give
 * the platform two ways to answer one question and eventually two different answers.
 *
 * A grant only ever adds. Nothing here takes access away from somebody who already had it — the
 * folder rules and the confidentiality rules remain the floor, and this raises it for one document.
 * That is what makes internal sharing useful: HR can hand one employee file to a manager without
 * granting them the Employee Files folder, and without making them somebody who can read every
 * confidential document in the company.
 *
 * It does not lift the legal-sensitivity gate. A document being preserved for a matter is not made
 * readable to somebody uncleared by sharing it with them, and a share is not the mechanism by which
 * that clearance should be granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');

            // user | role | department | company
            $table->string('subject_type', 20);

            // Null for 'company', which means everybody the module already lets in.
            $table->unsignedBigInteger('subject_id')->nullable();

            /*
             * The subject's name as it stood when the grant was made.
             *
             * Roles are matched on it, the way folder permissions already do. For users and
             * departments it is denormalised so a list of who a document was shared with can be
             * shown without joining four tables, and so it still reads sensibly after somebody
             * leaves and their record is archived.
             */
            $table->string('subject_name')->nullable();

            // Viewing is what a grant confers. Downloading is a separate decision, the same way it
            // is for an external link — sharing a contract for review is not handing over a copy.
            $table->boolean('can_download')->default(false);

            // Optional: "share this with the auditors until the end of the month".
            $table->timestamp('expires_at')->nullable();

            $table->string('note')->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();

            // One live grant per subject per document. Sharing twice with the same person is one
            // decision restated, not two grants to revoke separately.
            $table->unique(['document_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE document_grants ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_grants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_grants FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_grants_tenant ON document_grants USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_grants');
    }
};
