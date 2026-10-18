<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetCustodyLog;
use Illuminate\Support\Facades\DB;

/**
 * Handing an asset back.
 *
 * Assets could be given out and never returned: `AssignAssetAction` closed a previous assignment
 * when the asset was handed to somebody *else*, so the only way to release a laptop was to issue it
 * to another person. An employee returning one at the end of a project left it showing as still in
 * their custody — which is also what the exit-clearance report reads.
 *
 * The ledger matters as much as the flag. A custody log entry is written for the release, the same
 * way an issue writes one, so the asset's history reads as a continuous chain of holders rather
 * than a series of handovers with no releases between them.
 */
class ReturnAssetAction
{
    public function execute(AssetAssignment $assignment, int $performedBy, ?string $notes = null): AssetAssignment
    {
        return DB::transaction(function () use ($assignment, $performedBy, $notes) {
            // Locked and re-read: two people pressing return on the same assignment must not both
            // write a release into the custody ledger.
            $assignment = AssetAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if (! $assignment->is_current) {
                return $assignment;     // already back; returning it twice is not an event
            }

            $assignment->update(['is_current' => false, 'returned_at' => now()]);

            AssetCustodyLog::create([
                'asset_id'      => $assignment->asset_id,
                'assignment_id' => $assignment->id,
                'action'        => 'returned',
                'from_user_id'  => $assignment->assigned_to,
                'performed_by'  => $performedBy,
                'notes'         => $notes,
                'occurred_at'   => now(),
            ]);

            /*
             * Unallocated, not active.
             *
             * `active` on an asset means somebody is holding it — that is what `assign` sets. An
             * asset back in the store is available for the next person, and the difference is what
             * the availability screens and the assignment picker read.
             */
            $assignment->asset?->update(['asset_status' => 'unallocated', 'user_id' => null]);

            return $assignment->fresh();
        });
    }
}
