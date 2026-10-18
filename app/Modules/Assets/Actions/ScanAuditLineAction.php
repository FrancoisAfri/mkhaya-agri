<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetAudit;
use App\Modules\Assets\Models\AssetAuditLine;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

/**
 * Records a single scan against an open audit.
 *
 * Resolution order for a scanned code (barcode or asset_number):
 *  1. Matches an asset that IS in the expected set  -> mark that line `found`.
 *  2. Matches an asset NOT in the expected set      -> add an `unexpected` line
 *     (asset_id set).
 *  3. Matches no asset at all                        -> add an `unexpected` line
 *     (scanned_code only).
 *
 * Counts are recomputed and returned alongside the affected line.
 */
class ScanAuditLineAction
{
    /**
     * @return array{line: AssetAuditLine, result: string, counts: array<string,int>}
     */
    public function execute(AssetAudit $audit, string $code, ?int $scannedBy = null): array
    {
        $code = trim($code);

        return DB::transaction(function () use ($audit, $code, $scannedBy) {
            $asset = Assets::query()
                ->where('barcode', $code)
                ->orWhere('asset_number', $code)
                ->first();

            $result = 'unexpected';
            $line   = null;

            if ($asset) {
                // Is this asset part of the expected population for this audit?
                $expectedLine = AssetAuditLine::where('audit_id', $audit->id)
                    ->where('asset_id', $asset->id)
                    ->whereIn('status', [
                        AssetAuditLine::STATUS_EXPECTED,
                        AssetAuditLine::STATUS_FOUND,
                    ])
                    ->first();

                if ($expectedLine) {
                    $expectedLine->update([
                        'status'       => AssetAuditLine::STATUS_FOUND,
                        'scanned_code' => $code,
                        'scanned_by'   => $scannedBy,
                        'scanned_at'   => now(),
                    ]);
                    $line   = $expectedLine;
                    $result = 'found';
                } else {
                    // Asset exists but was not expected here — avoid duplicate rows.
                    $line = AssetAuditLine::firstOrNew([
                        'audit_id' => $audit->id,
                        'asset_id' => $asset->id,
                        'status'   => AssetAuditLine::STATUS_UNEXPECTED,
                    ]);
                    $line->fill([
                        'scanned_code' => $code,
                        'scanned_by'   => $scannedBy,
                        'scanned_at'   => now(),
                    ])->save();
                    $result = 'unexpected';
                }
            } else {
                // No asset matches this code at all.
                $line = AssetAuditLine::firstOrNew([
                    'audit_id'     => $audit->id,
                    'asset_id'     => null,
                    'scanned_code' => $code,
                    'status'       => AssetAuditLine::STATUS_UNEXPECTED,
                ]);
                $line->fill([
                    'scanned_by' => $scannedBy,
                    'scanned_at' => now(),
                ])->save();
                $result = 'unexpected';
            }

            $counts = $this->recomputeCounts($audit);

            return [
                'line'   => $line->fresh(['asset']),
                'result' => $result,
                'counts' => $counts,
            ];
        });
    }

    /**
     * @return array<string,int>
     */
    private function recomputeCounts(AssetAudit $audit): array
    {
        $found      = AssetAuditLine::where('audit_id', $audit->id)->where('status', AssetAuditLine::STATUS_FOUND)->count();
        $unexpected = AssetAuditLine::where('audit_id', $audit->id)->where('status', AssetAuditLine::STATUS_UNEXPECTED)->count();
        $missing    = AssetAuditLine::where('audit_id', $audit->id)->where('status', AssetAuditLine::STATUS_MISSING)->count();

        $audit->update([
            'found_count'      => $found,
            'unexpected_count' => $unexpected,
            'missing_count'    => $missing,
        ]);

        return [
            'expected'   => (int) $audit->expected_count,
            'found'      => $found,
            'missing'    => $missing,
            'unexpected' => $unexpected,
        ];
    }
}
