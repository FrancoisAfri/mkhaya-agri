<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Models\AssetExitClearanceItem;
use App\Modules\Assets\Models\Assets;
use Illuminate\Support\Facades\DB;

class ResolveExitClearanceItemAction
{
    public function execute(AssetExitClearanceItem $item, array $data, int $resolvedBy): AssetExitClearanceItem
    {
        // Idempotency: only a still-pending item may be resolved. Re-posting a
        // resolved item would otherwise create a second AssetAssignment/custody log.
        abort_if($item->resolution !== 'pending', 422, 'This clearance item has already been resolved.');

        return DB::transaction(function () use ($item, $data, $resolvedBy) {
            $resolution = $data['resolution'];

            $item->update([
                'resolution'             => $resolution,
                'resolved_by'            => $resolvedBy,
                'resolved_at'            => now(),
                'transfer_to_user_id'    => $data['transfer_to_user_id']    ?? null,
                'transfer_to_location_id' => $data['transfer_to_location_id'] ?? null,
                'waiver_reason'          => $data['waiver_reason']           ?? null,
                'notes'                  => $data['notes']                   ?? null,
            ]);

            $assignment = $item->assignment;

            if ($resolution === 'returned' && $assignment) {
                $assignment->update(['is_current' => false, 'returned_at' => now()]);
                Assets::where('id', $item->asset_id)->update(['asset_status' => 'unallocated']);

                AssetCustodyLog::create([
                    'asset_id'     => $item->asset_id,
                    'action'       => 'returned',
                    'performed_by' => $resolvedBy,
                    'notes'        => 'Returned via exit clearance.',
                    'occurred_at'  => now(),
                ]);
            }

            if ($resolution === 'transferred' && $assignment) {
                $assignment->update(['is_current' => false, 'returned_at' => now()]);

                if ($data['transfer_to_user_id'] ?? null) {
                    AssetAssignment::create([
                        'asset_id'        => $item->asset_id,
                        'assigned_to'     => $data['transfer_to_user_id'],
                        'assigned_by'     => $resolvedBy,
                        'location_id'     => $data['transfer_to_location_id'] ?? null,
                        'assignment_type' => 'permanent',
                        'effective_date'  => now()->toDateString(),
                        'is_current'      => true,
                    ]);
                }

                AssetCustodyLog::create([
                    'asset_id'     => $item->asset_id,
                    'action'       => 'transferred',
                    'performed_by' => $resolvedBy,
                    'notes'        => 'Transferred via exit clearance.',
                    'occurred_at'  => now(),
                ]);
            }

            // Re-evaluate the clearance status
            $this->evaluateClearanceStatus($item->exitClearance);

            return $item->refresh();
        });
    }

    private function evaluateClearanceStatus(AssetExitClearance $clearance): void
    {
        $clearance->load('items');
        $allResolved = $clearance->items->every(fn($i) => $i->resolution !== 'pending');

        if ($allResolved) {
            $clearance->update(['status' => 'cleared', 'cleared_at' => now()]);
        } elseif ($clearance->items->some(fn($i) => $i->resolution !== 'pending')) {
            $clearance->update(['status' => 'in_progress']);
        }
    }
}
