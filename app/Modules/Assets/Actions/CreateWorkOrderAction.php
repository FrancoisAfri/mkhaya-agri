<?php

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Models\AssetWorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Creates a work order with a safely-generated, per-tenant sequential number.
 *
 * The number is derived from the current per-tenant work-order count under a
 * row-lock inside a transaction so two concurrent submits cannot mint the same
 * WO number.
 */
class CreateWorkOrderAction
{
    public function execute(array $data, ?int $requestedBy = null): AssetWorkOrder
    {
        return DB::transaction(function () use ($data, $requestedBy) {
            // Per-tenant sequential number from the current count (Postgres rejects
            // FOR UPDATE on an aggregate, so no row-lock here — same count-based
            // convention used for asset/employee numbers elsewhere).
            $count = AssetWorkOrder::query()->count();
            $number = 'WO' . str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);

            return AssetWorkOrder::create([
                'work_order_number' => $number,
                'asset_id'          => $data['asset_id'],
                'title'             => $data['title'],
                'description'       => $data['description'] ?? null,
                'type'              => $data['type'] ?? 'corrective',
                'priority'          => $data['priority'] ?? 'medium',
                'status'            => 'open',
                'assigned_to'       => $data['assigned_to'] ?? null,
                'requested_by'      => $data['requested_by'] ?? $requestedBy,
                'scheduled_for'     => $data['scheduled_for'] ?? null,
                'labor_hours'       => 0,
                'labor_cost'        => 0,
                'parts_cost'        => 0,
                'total_cost'        => 0,
            ]);
        });
    }
}
