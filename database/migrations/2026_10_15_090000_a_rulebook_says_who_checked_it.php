<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regulatory pack integrity and the legal review gate (REG-FIX-4, REG-FIX-5).
 *
 * A pack version already carried a checksum, a signature, a signed-by, a verified flag, a version
 * and an effective date. Two things were missing, and they are the two that matter commercially.
 *
 * **Release metadata (REG-FIX-4).** Who published this rulebook, when, against which gazette or
 * amendment, and what changed since the last version. A pack is the thing that decides whether an
 * incident is reportable to the Department of Employment and Labour within seven days — and
 * "where did these rules come from" had no answer beyond a filename.
 *
 * **The legal review (REG-FIX-5).** Nothing recorded that a person qualified to say so had read
 * the pack and agreed it states the law. The requirement is explicit that this is outstanding and
 * that the content must not be marketed as statutory compliance until it is done, and a field
 * nobody can fill is a review nobody can evidence.
 *
 * On the activation side, one column: the acknowledgement.
 *
 * The gate this enables is deliberately NOT a hard block on using an unreviewed pack. A hard block
 * would stop every existing installation reporting incidents the moment this migration ran, and
 * would be worked around within a day. Instead: activating an unsigned or unreviewed pack as the
 * live authority requires somebody to acknowledge that in writing, the acknowledgement is kept
 * against the activation, and the screen says plainly which rulebooks are running without a legal
 * review. A tampered pack — one whose stored checksum no longer matches its own manifest — cannot
 * be activated at all, acknowledgement or not, because that is not a judgement call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_regulatory_pack_versions', function (Blueprint $table) {
            /*
             * REG-FIX-5. `capacity` matters as much as the name: "reviewed by J Smith" is worth
             * little and "reviewed by J Smith, admitted attorney, occupational health and safety"
             * is the thing a customer's legal department asks for.
             */
            $table->timestamp('legal_reviewed_at')->nullable();
            $table->string('legal_reviewer_name')->nullable();
            $table->string('legal_reviewer_capacity')->nullable();
            $table->string('legal_review_reference')->nullable();
            $table->text('legal_review_notes')->nullable();
            $table->foreignId('legal_review_recorded_by')->nullable()
                ->constrained('users')->nullOnDelete();

            // A review is of a version, and it expires: the OHS Act's regulations are amended and
            // a 2024 review of a pack does not cover a 2027 amendment.
            $table->date('legal_review_valid_until')->nullable();

            // REG-FIX-4's release metadata: publisher, published date, source instrument,
            // changelog. Jsonb rather than columns because what identifies a source instrument
            // differs by jurisdiction — a gazette number here, a statutory instrument number
            // there — and inventing columns for all of them guarantees the wrong ones.
            $table->jsonb('release_metadata')->nullable();

            $table->index('legal_reviewed_at', 'sheq_pack_legal_review_idx');
        });

        Schema::table('sheq_regulatory_pack_activations', function (Blueprint $table) {
            /*
             * Who accepted that this rulebook is running as the live authority without a
             * signature or a legal review behind it. Not a checkbox: the note is required and
             * kept, because the question it answers in two years' time is "who decided this was
             * good enough".
             */
            $table->timestamp('assurance_acknowledged_at')->nullable();
            $table->foreignId('assurance_acknowledged_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('assurance_acknowledgement')->nullable();

            // What was wrong at the moment of activation, so the record still reads correctly
            // after somebody later signs or reviews the pack.
            $table->jsonb('assurance_gaps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sheq_regulatory_pack_versions', function (Blueprint $table) {
            $table->dropIndex('sheq_pack_legal_review_idx');
            $table->dropConstrainedForeignId('legal_review_recorded_by');
            $table->dropColumn(['legal_reviewed_at', 'legal_reviewer_name',
                'legal_reviewer_capacity', 'legal_review_reference', 'legal_review_notes',
                'legal_review_valid_until', 'release_metadata']);
        });

        Schema::table('sheq_regulatory_pack_activations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assurance_acknowledged_by');
            $table->dropColumn(['assurance_acknowledged_at', 'assurance_acknowledgement',
                'assurance_gaps']);
        });
    }
};
