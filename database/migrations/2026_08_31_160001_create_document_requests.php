<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asking somebody for a document.
 *
 * The opposite direction from every other request in this module. Those are somebody asking to be
 * let in to something that exists; this is somebody asking for something that does not exist yet —
 * "send me your ID copy", "we need the signed lease", "where is the tax clearance certificate".
 *
 * That collection happens over email today, and it fails in the same way every time: the request is
 * sent, the file comes back as an attachment, somebody files it by hand, and nobody can say which
 * of the forty people asked have actually replied. The list of who still owes what is the thing
 * being built here — the upload is the easy part.
 *
 * The request carries where the document should be filed and what it should be called, so the
 * person answering it does not have to know the filing structure. They press upload; it lands in
 * the right folder, in the right category, named the right thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();

            $table->string('title', 200);
            $table->text('description')->nullable();

            // Who owes it. One person per row even when forty were asked at once: the queue this
            // exists to produce is "who still owes what", and a row covering several people cannot
            // be half-answered.
            $table->unsignedBigInteger('requested_from')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();

            // Where the answer should land, decided by the person asking. The person answering
            // should not have to learn the filing structure to reply to a request.
            $table->unsignedBigInteger('folder_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();

            $table->date('due_at')->nullable();

            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            // A request can be sent again. Recorded rather than counted in somebody's head, because
            // "I have asked them three times" is the sentence that precedes escalating it.
            $table->unsignedSmallInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'requested_from', 'status']);
            $table->index(['company_id', 'status', 'due_at']);
        });

        DB::statement("ALTER TABLE document_requests ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_requests ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_requests FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_requests_tenant ON document_requests USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
