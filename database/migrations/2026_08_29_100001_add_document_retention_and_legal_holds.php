<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retention schedules and legal holds.
 *
 * These are one feature wearing two names. Retention says a document stops being worth keeping
 * after a stated period; a legal hold says that for this document, right now, that judgement is
 * suspended because somebody may have to produce it. A retention schedule without holds destroys
 * evidence on time, which is the one outcome the schedule exists to make defensible.
 *
 * Two rules shape the schema:
 *
 *   Nothing is destroyed by a timer. The sweep marks documents as due and stops. Somebody with the
 *   permission looks at the queue and decides. An organisation that cannot say who authorised a
 *   destruction has not complied with a retention policy, it has merely lost files on schedule.
 *
 *   A hold is a record, not a flag. is_legally_sensitive could say a document was held but not by
 *   whom, under what matter, since when, or why it was lifted — which is precisely what is asked
 *   for when a hold is challenged. The flag stays and keeps working; it is now the shadow of the
 *   hold records rather than the truth itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_categories', function (Blueprint $table) {
            /*
             * How long documents of this category are kept, and from when.
             *
             * On the category because that is the unit a retention schedule is actually written in
             * — "tax records: five years", "employee files: seven years after termination" — and
             * because a rule set per document is a rule nobody can audit.
             *
             * Null means no schedule, which is different from zero. Most categories will have none
             * until somebody sets one, and a null must never be read as "destroy immediately".
             */
            $table->unsignedSmallInteger('retention_years')->nullable();

            /*
             * The date the clock starts from.
             *
             * Not always the upload: a contract's retention runs from when it ended, a tax record's
             * from the year it covers. Storing the basis rather than a computed date means a
             * corrected document_date moves the disposal date with it.
             */
            $table->string('retention_basis', 32)->nullable();

            // What the schedule is for, in the words of whoever set it. Shown next to the queue,
            // because "why is this due?" is the first question anybody reviewing it asks.
            $table->string('retention_note')->nullable();
        });

        Schema::table('documents', function (Blueprint $table) {
            // Computed from the category's schedule, recomputed by the sweep. Stored rather than
            // derived on read so the queue is one indexed query instead of a scan.
            $table->timestamp('disposal_due_at')->nullable();

            /*
             * Where this document has got to in the disposal conversation.
             *
             * Null for the overwhelming majority — no schedule, or not due yet. The states exist so
             * that "nobody has looked at this" and "somebody looked and decided to keep it" are
             * different answers; without that, a reviewed document reappears in the queue every day
             * and the queue stops being read.
             */
            $table->string('disposal_state', 24)->nullable();
            $table->timestamp('disposal_reviewed_at')->nullable();
            $table->unsignedBigInteger('disposal_reviewed_by')->nullable();
            $table->string('disposal_note')->nullable();

            $table->index(['disposal_due_at', 'disposal_state']);
        });

        /*
         * One row per hold placed on one document.
         *
         * Deliberately not a join to a "matters" table. A hold's reference is whatever the people
         * placing it use — a case number, an internal investigation name, a regulator's letter —
         * and forcing them to create a record in a matter register first is how holds end up not
         * being placed at all. The reference is indexed so a register can still group by it.
         *
         * Rows are kept after release. A released hold is the evidence that a document was
         * preserved during the period it needed to be, which is the whole point of holding it.
         */
        Schema::create('document_legal_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('document_id');

            // The matter this hold belongs to, as the people placing it would name it.
            $table->string('reference', 120);
            $table->text('reason')->nullable();

            $table->unsignedBigInteger('placed_by')->nullable();
            $table->timestamp('placed_at');

            // Null while the hold is live. Set on release, never cleared: a hold that was lifted
            // and then re-placed is two rows, because it was two decisions.
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'released_at']);
            $table->index(['reference', 'released_at']);
        });

        DB::statement("ALTER TABLE document_legal_holds ALTER COLUMN company_id SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
        DB::statement('ALTER TABLE document_legal_holds ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_legal_holds FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY document_legal_holds_tenant ON document_legal_holds USING (
            NULLIF(current_setting('app.company_id', true), '') IS NULL
            OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
        )");

        /*
         * Documents already marked legally sensitive become held under a hold record, so the
         * register is complete from the first day rather than only describing holds placed after
         * this migration. The reference says where they came from: nobody should read these as
         * having been placed under a matter that was never named.
         */
        $migrated = DB::table('documents')
            ->where('is_legally_sensitive', true)
            ->whereNull('deleted_at')
            ->get(['id', 'company_id', 'sensitivity_note', 'updated_at']);

        foreach ($migrated as $document) {
            DB::table('document_legal_holds')->insert([
                'uuid' => (string) Illuminate\Support\Str::uuid(),
                'company_id' => $document->company_id,
                'document_id' => $document->id,
                'reference' => 'Pre-existing hold',
                'reason' => $document->sensitivity_note
                    ?: 'Marked legally sensitive before holds were recorded individually.',
                'placed_at' => $document->updated_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_legal_holds');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'disposal_due_at', 'disposal_state', 'disposal_reviewed_at',
                'disposal_reviewed_by', 'disposal_note',
            ]);
        });

        Schema::table('document_categories', function (Blueprint $table) {
            $table->dropColumn(['retention_years', 'retention_basis', 'retention_note']);
        });
    }
};
