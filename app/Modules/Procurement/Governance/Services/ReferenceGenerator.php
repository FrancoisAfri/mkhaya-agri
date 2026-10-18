<?php

namespace App\Modules\Procurement\Governance\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reference numbers — PROC-2026-000145, DEV-2026-0042, TR-2026-0011.
 *
 * The obvious implementation is `max(id) + 1`, which is what several older modules here do. It is
 * a read-then-write race: two officers submitting at the same moment both read 41 and both write
 * DEV-2026-0042. For a procurement reference that duplicate is not cosmetic — it is the identifier
 * an audit, a board paper and a supplier's invoice all quote, and the two records are then
 * indistinguishable in correspondence.
 *
 * So the counter is a row, claimed under `lockForUpdate` inside the caller's transaction. Two
 * concurrent claims serialise; the second waits and gets the next number.
 *
 * Numbering restarts each year per tenant, which is what people expect of a document reference and
 * what makes DEV-2026-0042 sortable and meaningful on its own.
 */
class ReferenceGenerator
{
    /** sequence type => [settings column holding the prefix, fallback prefix, zero-pad width] */
    private const TYPES = [
        'record' => ['record_prefix', 'PROC', 6],
        'review' => ['review_prefix', 'TR', 4],
        'plan' => ['plan_prefix', 'PP', 4],
        'plan_item' => ['plan_prefix', 'PP', 4],
        'deviation' => ['deviation_prefix', 'DEV', 4],
        'committee' => ['committee_prefix', 'EC', 4],
        'report' => ['record_prefix', 'RPT', 4],
        'document' => ['record_prefix', 'DOC', 6],
    ];

    /**
     * Claim the next reference for a type. Call inside a transaction that also writes the row the
     * reference belongs to, so a rolled-back create does not burn a number.
     */
    public function next(int $companyId, string $type, ?Carbon $on = null): string
    {
        [$settingColumn, $fallbackPrefix, $width] = self::TYPES[$type]
            ?? [null, strtoupper(substr($type, 0, 4)), 4];

        $year = ($on ?? Carbon::now())->year;
        $value = $this->claim($companyId, $type, $year);

        $prefix = $settingColumn
            ? ($this->settingValue($companyId, $settingColumn) ?: $fallbackPrefix)
            : $fallbackPrefix;

        // plan_item numbers hang off the plan prefix but must not collide with plan numbers.
        if ($type === 'plan_item') {
            $prefix .= 'I';
        }

        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $value, $width, '0', STR_PAD_LEFT));
    }

    /**
     * Claim the next value in a counter. Also used by the audit ledger for its chain position,
     * which needs the same guarantee for a different reason (a gap-free sequence is what makes a
     * deleted row visible).
     */
    public function claim(int $companyId, string $type, ?int $year = null): int
    {
        $year ??= Carbon::now()->year;

        return DB::transaction(function () use ($companyId, $type, $year) {
            $row = DB::table('procgov_reference_sequences')
                ->where('company_id', $companyId)
                ->where('sequence_type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                // First use this year. The unique key makes a concurrent insert fail rather than
                // duplicate, and the retry below then takes the lock path.
                try {
                    DB::table('procgov_reference_sequences')->insert([
                        'company_id' => $companyId,
                        'sequence_type' => $type,
                        'year' => $year,
                        'next_value' => 2,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return 1;
                } catch (QueryException $e) {
                    $row = DB::table('procgov_reference_sequences')
                        ->where('company_id', $companyId)
                        ->where('sequence_type', $type)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->first();

                    if (! $row) {
                        throw $e;
                    }
                }
            }

            DB::table('procgov_reference_sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (int) $row->next_value;
        });
    }

    private function settingValue(int $companyId, string $column): ?string
    {
        $value = DB::table('procgov_settings')->where('company_id', $companyId)->value($column);

        return $value !== null ? (string) $value : null;
    }
}
