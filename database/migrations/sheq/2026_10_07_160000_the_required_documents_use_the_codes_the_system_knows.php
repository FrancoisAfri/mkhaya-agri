<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correct the required-document codes to the vocabulary the rest of the module uses.
 *
 * `contractor_required_documents` was seeded with codes invented at the time —
 * `LETTER_OF_GOOD_STANDING`, `PUBLIC_LIABILITY_INSURANCE`, `SECTION_37_2_AGREEMENT` — while
 * `ContractorService` had always spoken `COIDA_LETTER`, `PUBLIC_LIABILITY` and so on.
 *
 * The two never met. `complianceFile()` compares a required code against the `document_type` on
 * each uploaded document, so a correctly typed COIDA letter did not satisfy a requirement for
 * `LETTER_OF_GOOD_STANDING`: the host was demanding a certificate nobody could supply, and a
 * contractor with a complete file stayed blocked with no way to see why.
 *
 * Rewritten rather than left for a human to notice, because the symptom is indistinguishable from
 * a genuinely missing document.
 */
return new class extends Migration
{
    private const CORRECTIONS = [
        'LETTER_OF_GOOD_STANDING' => 'COIDA_LETTER',
        'PUBLIC_LIABILITY_INSURANCE' => 'PUBLIC_LIABILITY',
        'SECTION_37_2_AGREEMENT' => 'SECTION_37_2',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('sheq_settings', 'contractor_required_documents')) {
            return;
        }

        foreach (DB::table('sheq_settings')
            ->whereNotNull('contractor_required_documents')
            ->get(['id', 'contractor_required_documents']) as $row) {
            $required = json_decode((string) $row->contractor_required_documents, true);

            if (! is_array($required)) {
                continue;
            }

            $corrected = array_values(array_unique(array_map(
                static fn ($code) => self::CORRECTIONS[$code] ?? $code,
                $required,
            )));

            if ($corrected !== $required) {
                DB::table('sheq_settings')->where('id', $row->id)
                    ->update(['contractor_required_documents' => json_encode($corrected)]);
            }
        }

        // Any document already filed under an invented code is moved too, so a file captured
        // before this stays the evidence it was uploaded as.
        foreach (self::CORRECTIONS as $wrong => $right) {
            DB::table('sheq_contractor_documents')->where('document_type', $wrong)
                ->update(['document_type' => $right]);
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: the previous codes satisfied nothing, so restoring them
        // would re-block every contractor whose file is now correctly matched.
    }
};
