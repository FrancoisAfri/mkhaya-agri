<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Actions\CreateExitClearanceAction;
use App\Modules\Assets\Models\AssetExitClearance;
use App\Modules\Assets\Models\AssetExitClearanceItem;
use Illuminate\Support\Facades\DB;

/**
 * M5 — Exit Clearance Service
 *
 * Generates an exit-clearance checklist when an employee is terminated (hooks into
 * the HR EmployeeTerminated event) and records asset returns against it.
 *
 * Creation delegates to CreateExitClearanceAction so there is a single, schema-
 * correct code path shared with the manual web flow. `asset_exit_clearances`
 * columns: user_id, initiated_by, target_exit_date, status, cleared_at, notes.
 * `asset_exit_clearance_items` columns: clearance_id, asset_id, assignment_id,
 * resolution, resolved_by, resolved_at, transfer_to_*, waiver_reason, notes.
 * ExitClearanceStatus = pending | in_progress | cleared | blocked.
 */
class AssetExitClearanceService
{
    public function __construct(private CreateExitClearanceAction $createClearance)
    {
    }

    public function generateClearance(int $employeeId, \Carbon\Carbon $terminationDate): AssetExitClearance
    {
        // Reuse the working action (correct schema + per-assignment items). The
        // terminated employee is recorded as the initiator for an auto-generated
        // clearance since the termination event carries no separate actor.
        return $this->createClearance->execute([
            'user_id'          => $employeeId,
            'target_exit_date' => $terminationDate->toDateString(),
            'notes'            => 'Auto-generated on employee termination.',
        ], $employeeId);
    }

    public function recordReturn(AssetExitClearanceItem $item, array $data): AssetExitClearanceItem
    {
        // Idempotency: only a still-pending item can be recorded as returned.
        abort_if($item->resolution !== 'pending', 422, 'This clearance item has already been resolved.');

        return DB::transaction(function () use ($item, $data) {
            $item->update([
                'resolution'  => 'returned',
                'resolved_by' => $data['resolved_by'] ?? null,
                'resolved_at' => $data['return_date'] ?? now(),
                'notes'       => $data['notes'] ?? null,
            ]);

            // Close the underlying assignment.
            if ($item->assignment) {
                $item->assignment->update([
                    'returned_at' => $item->resolved_at,
                    'is_current'  => false,
                ]);
            }

            $this->refreshClearanceStatus($item->exitClearance);

            return $item->fresh();
        });
    }

    /** Roll the parent clearance's status up from its items' resolutions. */
    public function refreshClearanceStatus(AssetExitClearance $clearance): void
    {
        $clearance->load('items');
        $items = $clearance->items;

        $allResolved = $items->isEmpty() || $items->every(fn ($i) => $i->resolution !== 'pending');
        $anyResolved = $items->contains(fn ($i) => $i->resolution !== 'pending');

        if ($allResolved) {
            $clearance->update(['status' => 'cleared', 'cleared_at' => now()]);
        } elseif ($anyResolved) {
            $clearance->update(['status' => 'in_progress']);
        } else {
            $clearance->update(['status' => 'pending']);
        }
    }
}
