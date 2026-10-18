<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetMisuseFlag;
use App\Modules\Assets\Models\Assets;

class CreateMisuseFlagAction
{
    public function execute(array $data, ?int $flaggedBy): AssetMisuseFlag
    {
        // Duplicate suppression: no open flag of same type for same asset within 24h
        $existing = AssetMisuseFlag::where('asset_id', $data['asset_id'])
            ->where('flag_type', $data['flag_type'])
            ->where('status', 'open')
            ->where('detected_at', '>=', now()->subHours(24))
            ->first();

        if ($existing) {
            return $existing;
        }

        return AssetMisuseFlag::create([
            'asset_id'        => $data['asset_id'],
            'digital_twin_id' => $data['digital_twin_id'] ?? null,
            'flagged_by'      => $flaggedBy,
            'flag_type'       => $data['flag_type'],
            'severity'        => $data['severity'] ?? 'medium',
            'status'          => 'open',
            'description'     => $data['description'],
            'evidence'        => $data['evidence'] ?? null,
            'detected_at'     => $data['detected_at'] ?? now(),
        ]);
    }
}
