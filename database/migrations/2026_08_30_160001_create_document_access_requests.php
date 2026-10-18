<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody asking to be let in.
 *
 * The repository hides what a person cannot open — a folder they have no access to is absent from
 * their tree rather than present and refusing every click. That is the right default, and it leaves
 * one gap: a colleague sends a link, the person opens it, and the only thing the system has to say
 * is 403. They then ask in a corridor, or by email, and the grant that follows is recorded nowhere.
 *
 * This is that conversation, written down. A request names what was asked for, who asked, and why;
 * the answer names who decided. Granting one creates an ordinary internal share, so there is still
 * exactly one mechanism by which somebody comes to see a document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();

            // One or the other. A request for a folder is a request for what is filed in it, and
            // keeping them in one table means one queue to answer rather than two to remember.
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('folder_id')->nullable();

            $table->unsignedBigInteger('requested_by');
            $table->text('reason')->nullable();

            // pending | granted | declined
            $table->string('status', 16)->default('pending');

            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            $table->timestamps();

            // Asking twice while the first is unanswered is one request, not two — otherwise a
            // queue fills with the same person asking again because nothing visibly happened.
            $table->index(['status', 'created_at']);
            $table->index(['requested_by', 'status']);
        });

        DB::statement("ALTER TABLE document_access_requests ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_access_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_access_requests FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_access_requests_tenant ON document_access_requests USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        // An answer to nothing is a row nobody can act on, and the only way to write one is a bug.
        DB::statement('ALTER TABLE document_access_requests ADD CONSTRAINT document_access_requests_subject
            CHECK (document_id IS NOT NULL OR folder_id IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_requests');
    }
};
