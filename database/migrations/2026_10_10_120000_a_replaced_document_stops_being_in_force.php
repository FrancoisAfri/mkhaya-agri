<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close the version chain on contractor documents (CON-24).
 *
 * Versioning half existed. Attaching a renewal created a new row with the next version number
 * and a `replaces_document_id` pointing back — so the chain was recorded — and the row it
 * replaced was never marked as replaced. Both stayed live.
 *
 * Two consequences, and the second is the one CON-24 is about.
 *
 * The compliance file had to CHOOSE between live versions, and it chose the one with the furthest
 * expiry. The comment in that code admits what that means: a date typed as 2030 instead of 2026
 * beats the correct certificate attached afterwards, so the contractor reads COMPLIANT and
 * nothing short of deleting the evidence corrects it.
 *
 * And "which version was in force in March" had no answer. Nothing recorded when version 1
 * stopped counting, so a safety file exported in March, an access decision taken in March, and an
 * audit asking about March could only ever be shown today's document. CON-24 requires historical
 * records to keep pointing at the version actually in force at the time, and the fact that makes
 * that possible — the moment each version stopped being current — was not being written.
 *
 * This backfills it. For every contractor and document type with more than one live version, all
 * but the highest version are stamped as superseded. The timestamp used is the moment the
 * replacement was filed, which is when it actually stopped being current, rather than now — now
 * would claim every historical version was live until this migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Keyed on (contractor, type) and ordered by version, so each row is superseded as at the
         * creation of the row that replaced it. Written as one statement rather than a loop
         * because an installation with a few years of renewals has tens of thousands of these,
         * and Eloquent in a migration is how a deploy times out.
         */
        DB::statement(<<<'SQL'
            WITH chains AS (
                SELECT
                    id,
                    LEAD(created_at) OVER (
                        PARTITION BY company_id, contractor_id, document_type
                        ORDER BY version, id
                    ) AS replaced_at,
                    LEAD(id) OVER (
                        PARTITION BY company_id, contractor_id, document_type
                        ORDER BY version, id
                    ) AS replaced_by
                FROM sheq_contractor_documents
                WHERE deleted_at IS NULL AND superseded_at IS NULL
            )
            UPDATE sheq_contractor_documents AS d
            SET superseded_at = chains.replaced_at,
                supersede_reason = COALESCE(
                    d.supersede_reason,
                    'Replaced by a later version. Stamped when version history was closed.'
                )
            FROM chains
            WHERE d.id = chains.id
              AND chains.replaced_by IS NOT NULL
              AND chains.replaced_at IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        /*
         * Only the rows this migration stamped, identified by the reason it wrote. Clearing every
         * `superseded_at` would undo supersessions that real people performed deliberately.
         */
        DB::table('sheq_contractor_documents')
            ->where('supersede_reason', 'Replaced by a later version. Stamped when version '
                .'history was closed.')
            ->update(['superseded_at' => null, 'supersede_reason' => null]);
    }
};
