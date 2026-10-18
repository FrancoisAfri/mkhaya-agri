<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetMisuseFlag;

class UpdateMisuseFlagStatusAction
{
    public function execute(AssetMisuseFlag $flag, array $data, int $userId): AssetMisuseFlag
    {
        $newStatus = $data['status'];

        $updates = ['status' => $newStatus];

        if ($newStatus === 'investigating') {
            $updates['assigned_investigator'] = $data['assigned_investigator'] ?? $userId;
            $updates['investigated_at']       = now();
        }

        if (in_array($newStatus, ['dismissed', 'confirmed'])) {
            $updates['resolution_notes'] = $data['resolution_notes'] ?? null;
            $updates['resolved_at']      = now();
            $updates['resolved_by']      = $userId;
        }

        $flag->update($updates);
        return $flag->refresh();
    }
}
