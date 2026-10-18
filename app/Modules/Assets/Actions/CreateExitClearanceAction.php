<?php

namespace App\Modules\Assets\Actions;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Models\AssetExitClearanceItem;
use Illuminate\Support\Facades\DB;

class CreateExitClearanceAction
{
    public function execute(array $data, int $initiatedBy): AssetExitClearance
    {
        return DB::transaction(function () use ($data, $initiatedBy) {
            $clearance = AssetExitClearance::create([
                'user_id'          => $data['user_id'],
                'initiated_by'     => $initiatedBy,
                'target_exit_date' => $data['target_exit_date'],
                'notes'            => $data['notes'] ?? null,
                'status'           => 'pending',
            ]);

            $assignments = AssetAssignment::where('assigned_to', $data['user_id'])
                ->where('is_current', true)
                ->get();

            foreach ($assignments as $assignment) {
                AssetExitClearanceItem::create([
                    'clearance_id'  => $clearance->id,
                    'asset_id'      => $assignment->asset_id,
                    'assignment_id' => $assignment->id,
                    'resolution'    => 'pending',
                ]);
            }

            return $clearance->load('items.asset');
        });
    }
}
