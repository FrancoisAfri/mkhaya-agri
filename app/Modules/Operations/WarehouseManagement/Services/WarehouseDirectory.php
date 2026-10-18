<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockBalance;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\LocationChange;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and configures warehouses (WMS-003), writing every change to the warehouse history.
 *
 * A warehouse is Finance's `fin_warehouses` row. Creating one here is creating one for the ledger:
 * there is no second record to keep in step.
 */
class WarehouseDirectory
{
    public const TYPES = ['GENERAL', 'DISTRIBUTION', 'COLD_CHAIN', 'BONDED', 'PRODUCTION', 'THIRD_PARTY', 'RETAIL_BACKROOM', 'YARD'];

    public const CAPACITY_BASES = ['UNITS', 'WEIGHT', 'VOLUME', 'PALLETS'];

    /** The policy switches WMS-003 names, with the value a new warehouse starts on. */
    public const POLICY_DEFAULTS = [
        'rotation' => 'FEFO',              // FEFO|FIFO|LIFO|NONE
        'allocation' => 'FEFO',
        'receiving_requires_inspection' => false,
        'blind_receiving' => false,
        'putaway' => 'SUGGESTED',          // SUGGESTED|DIRECTED|FREE
        'picking' => 'DISCRETE',           // DISCRETE|BATCH|ZONE|WAVE|CLUSTER
        'scan_to_confirm' => true,
        'replenishment' => 'MIN_MAX',      // MIN_MAX|REORDER_POINT|NONE
        'dispatch_requires_pack_check' => true,
        'returns_to_quarantine' => true,
        'receipt_over_tolerance_pct' => 0,  // % of the ordered quantity accepted over without approval
        'max_backdate_days' => 7,           // adjustments dated further back wait for approval
        'sla_dispatch_hours' => 24,         // a released order should be dispatched within this
        'sla_risk_minutes' => 60,           // warn this long before the dispatch deadline
        // Read by Part C services, and saveable since they were added here (they were silently dropped before).
        'returns_require_inspection' => true,
        'expiry_alert_days' => null,        // list of days-before-expiry warning points; null: the item's or 90/60/30
        'carrying_cost_pct' => null,        // annual carrying cost as % of value; null: not reported
        'shift_hours' => 8,
        'utilisation' => 0.85,
        'metres_per_slot' => 1.5,
        'batch_size' => 8,
        'putaway_hours' => 4,
        'gln' => null,                      // GS1 Global Location Number, 13 digits
        'restrict_to_staff' => false,       // WMS-200: only assigned staff, the manager and wms.warehouses.all
    ];

    /** Fields the history records. Timestamps and computed values are not configuration. */
    private const AUDITED = [
        'code', 'name', 'address', 'is_active', 'is_default', 'warehouse_type', 'timezone', 'work_calendar_id',
        'manager_user_id', 'sheq_site_id', 'sync_from_site', 'capacity_basis', 'capacity_value', 'opening_time',
        'closing_time', 'dispatch_cutoff_time', 'permitted_categories', 'policies', 'is_wms_managed',
    ];

    public function save(array $data, ?FinWarehouse $warehouse = null): FinWarehouse
    {
        // Policies are versioned and checked (WMS-241); they are applied after the warehouse itself.
        $policies = $data['policies'] ?? null;
        unset($data['policies']);

        return DB::transaction(function () use ($data, $warehouse, $policies) {
            $creating = $warehouse === null;
            $warehouse ??= new FinWarehouse(['policies' => self::POLICY_DEFAULTS]);
            $before = $creating ? [] : $this->snapshot($warehouse);

            if ($creating && FinWarehouse::query()->where('code', $data['code'])->exists()) {
                throw new RuntimeException("A warehouse with code {$data['code']} already exists.");
            }

            $warehouse->fill($data)->save();
            if ($policies !== null) {
                app(WarehousePolicies::class)->apply($warehouse, $policies, auth()->user(), $creating ? 'Set when the warehouse was created' : 'Changed on the warehouse form');
            }

            if (! empty($data['is_default'])) {
                // Exactly one default: Finance relies on it for sales relief.
                FinWarehouse::query()->whereKeyNot($warehouse->id)->where('is_default', true)->update(['is_default' => false]);
            }

            if ($warehouse->sync_from_site && $warehouse->sheq_site_id) {
                $this->applySite($warehouse);
            }

            $this->record($warehouse, $creating ? LocationChange::CREATED : LocationChange::UPDATED,
                $this->diff($before, $this->snapshot($warehouse->fresh())));

            return $warehouse;
        });
    }

    /**
     * Pull the linked SHEQ site's details onto the warehouse (WHMI decision 4).
     *
     * Only what a site actually holds is copied: its timezone, and its name where the warehouse
     * has no address yet. A site carries no postal address, so nothing pretends to sync one.
     */
    public function syncFromSite(FinWarehouse $warehouse): FinWarehouse
    {
        if (! $warehouse->sheq_site_id) {
            throw new RuntimeException('This warehouse is not linked to a site, so there is nothing to sync from.');
        }

        return DB::transaction(function () use ($warehouse) {
            $before = $this->snapshot($warehouse);
            $this->applySite($warehouse);
            $this->record($warehouse, LocationChange::SYNCED, $this->diff($before, $this->snapshot($warehouse->fresh())));

            return $warehouse;
        });
    }

    /**
     * Retire a warehouse. One still holding stock cannot be retired: its stock would be on the
     * books and nowhere a person could find it.
     */
    public function deactivate(FinWarehouse $warehouse, ?string $reason = null): void
    {
        $holding = (float) FinStockBalance::query()->where('warehouse_id', $warehouse->id)->sum('qty');

        if (abs($holding) > 0.0001) {
            throw new RuntimeException("{$warehouse->name} still holds stock. Move or count it out before retiring the warehouse.");
        }
        if ($warehouse->is_default) {
            throw new RuntimeException('The default warehouse cannot be retired. Make another warehouse the default first.');
        }

        DB::transaction(function () use ($warehouse, $reason) {
            $warehouse->update(['is_active' => false]);
            $this->record($warehouse, LocationChange::DEACTIVATED, ['is_active' => [true, false]], $reason);
        });
    }

    /** Assign a person to a warehouse with a shift and the activities they may perform. */
    public function assignStaff(FinWarehouse $warehouse, int $userId, array $activities, ?int $shiftId = null): WarehouseStaff
    {
        $activities = array_values(array_intersect(WarehouseStaff::ACTIVITIES, array_map('strtoupper', $activities)));

        if ($activities === []) {
            throw new RuntimeException('Choose at least one activity this person performs in the warehouse.');
        }

        return DB::transaction(function () use ($warehouse, $userId, $activities, $shiftId) {
            $staff = WarehouseStaff::query()->updateOrCreate(
                ['warehouse_id' => $warehouse->id, 'user_id' => $userId],
                ['company_id' => $warehouse->company_id, 'activities' => $activities, 'shift_id' => $shiftId, 'is_active' => true],
            );
            $this->record($warehouse, LocationChange::UPDATED, ['staff' => [null, ['user_id' => $userId, 'activities' => $activities]]]);

            return $staff;
        });
    }

    public function removeStaff(WarehouseStaff $staff): void
    {
        DB::transaction(function () use ($staff) {
            $this->record($staff->warehouse, LocationChange::UPDATED, ['staff' => [['user_id' => $staff->user_id], null]]);
            $staff->delete();
        });
    }

    /** Is this person allowed to perform this activity here? Unassigned warehouses are open to all. */
    public function permits(FinWarehouse $warehouse, int $userId, string $activity): bool
    {
        $assigned = WarehouseStaff::query()->where('warehouse_id', $warehouse->id)->where('is_active', true);

        if (! (clone $assigned)->exists()) {
            return true;
        }

        $row = $assigned->where('user_id', $userId)->first();

        return $row !== null && (in_array(strtoupper($activity), $row->activities ?? [], true)
            || in_array('SUPERVISE', $row->activities ?? [], true));
    }

    private function applySite(FinWarehouse $warehouse): void
    {
        $site = DB::table('sheq_sites')->where('id', $warehouse->sheq_site_id)->first(['name', 'timezone']);

        if ($site === null) {
            throw new RuntimeException('The linked site no longer exists.');
        }

        $warehouse->forceFill([
            'timezone' => $site->timezone ?: $warehouse->timezone,
            'address' => $warehouse->address ?: $site->name,
            'site_synced_at' => now(),
        ])->save();
    }

    private function snapshot(FinWarehouse $warehouse): array
    {
        return collect(self::AUDITED)->mapWithKeys(fn ($f) => [$f => $warehouse->getAttribute($f)])->all();
    }

    private function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            if (json_encode($old) !== json_encode($value)) {
                $out[$field] = [$old, $value];
            }
        }

        return $out;
    }

    private function record(FinWarehouse $warehouse, string $action, array $changes, ?string $reason = null): void
    {
        if ($changes === [] && $action === LocationChange::UPDATED) {
            return;
        }

        LocationChange::create([
            'company_id' => $warehouse->company_id, 'warehouse_id' => $warehouse->id, 'bin_id' => null,
            'action' => $action, 'changes' => $changes, 'reason' => $reason, 'actor_id' => Auth::id(),
            'created_at' => now(),
        ]);
    }
}
