<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetWorkOrder;

/**
 * Guards and applies work-order status transitions.
 *
 *   open        → in_progress            (start,  stamps started_at)
 *   in_progress → on_hold                (hold)
 *   on_hold     → in_progress            (resume)
 *   open|in_progress|on_hold → completed (complete, stamps completed_at)
 *   open|in_progress|on_hold → cancelled (cancel)
 *
 * Invalid transitions abort with 422. Completion is idempotent (re-completing a
 * completed order is a no-op) and requires all tasks done unless a resolution
 * note / force override is supplied.
 */
class TransitionWorkOrderStatusAction
{
    public function start(AssetWorkOrder $wo): AssetWorkOrder
    {
        abort_if($wo->status !== 'open', 422, 'Only an open work order can be started.');

        $wo->status = 'in_progress';
        $wo->started_at = $wo->started_at ?? now();
        $wo->save();

        return $wo;
    }

    public function hold(AssetWorkOrder $wo): AssetWorkOrder
    {
        abort_if($wo->status !== 'in_progress', 422, 'Only an in-progress work order can be put on hold.');

        $wo->status = 'on_hold';
        $wo->save();

        return $wo;
    }

    public function resume(AssetWorkOrder $wo): AssetWorkOrder
    {
        abort_if($wo->status !== 'on_hold', 422, 'Only a work order on hold can be resumed.');

        $wo->status = 'in_progress';
        $wo->started_at = $wo->started_at ?? now();
        $wo->save();

        return $wo;
    }

    public function complete(AssetWorkOrder $wo, ?string $notes = null, bool $force = false): AssetWorkOrder
    {
        // Idempotent: already completed is a no-op.
        if ($wo->status === 'completed') {
            return $wo;
        }

        abort_if($wo->status === 'cancelled', 422, 'A cancelled work order cannot be completed.');

        // Require every task done unless the caller forces completion or supplies a note.
        if (! $force && $notes === null && ! $wo->allTasksDone()) {
            abort(422, 'All tasks must be done before completing, or supply a resolution note.');
        }

        $wo->status = 'completed';
        $wo->completed_at = now();
        $wo->started_at = $wo->started_at ?? now();
        if ($notes !== null) {
            $wo->resolution_notes = $notes;
        }
        $wo->save();

        return $wo;
    }

    public function cancel(AssetWorkOrder $wo, ?string $notes = null): AssetWorkOrder
    {
        if ($wo->status === 'cancelled') {
            return $wo;
        }

        abort_if($wo->status === 'completed', 422, 'A completed work order cannot be cancelled.');

        $wo->status = 'cancelled';
        if ($notes !== null) {
            $wo->resolution_notes = $notes;
        }
        $wo->save();

        return $wo;
    }
}
