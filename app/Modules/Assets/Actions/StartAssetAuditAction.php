<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetAudit;
use App\Modules\Assets\Models\AssetAuditLine;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

/**
 * Opens a new physical audit and snapshots the current asset population as
 * `expected` lines (one line per asset). Optionally filtered to a location.
 */
class StartAssetAuditAction
{
    /**
     * @param array{name:string, location_id?:int|null, notes?:string|null} $data
     */
    public function execute(array $data, ?int $startedBy = null): AssetAudit
    {
        return DB::transaction(function () use ($data, $startedBy) {
            $locationId = $data['location_id'] ?? null;

            $audit = AssetAudit::create([
                'name'        => $data['name'],
                'location_id' => $locationId,
                'status'      => AssetAudit::STATUS_OPEN,
                'started_by'  => $startedBy,
                'started_at'  => now(),
                'notes'       => $data['notes'] ?? null,
            ]);

            $query = Assets::query()
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->select('id');

            $expected = 0;

            $query->orderBy('id')->chunk(500, function ($assets) use ($audit, &$expected) {
                foreach ($assets as $asset) {
                    AssetAuditLine::create([
                        'audit_id' => $audit->id,
                        'asset_id' => $asset->id,
                        'status'   => AssetAuditLine::STATUS_EXPECTED,
                    ]);
                    $expected++;
                }
            });

            $audit->update(['expected_count' => $expected]);

            return $audit->fresh();
        });
    }
}
