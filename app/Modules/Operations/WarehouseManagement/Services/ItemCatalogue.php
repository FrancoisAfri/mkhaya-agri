<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Operations\Manufacturing\Models\Item as ItemProfileMfg;
use App\Modules\Operations\Manufacturing\Models\ItemSubstitute;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Models\UomConversion;
use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use App\Modules\Operations\WarehouseManagement\Models\ItemBundle;
use App\Modules\Operations\WarehouseManagement\Models\ItemEvent;
use App\Modules\Operations\WarehouseManagement\Models\ItemIdentifier;
use App\Modules\Operations\WarehouseManagement\Models\ItemPackaging;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The product master as the warehouse sees it (WMS-010, WMS-011), assembled from the records that
 * already own each part rather than copied into a new one:
 *
 *  - the SKU, name, base unit, costing and reorder level: Finance's `fin_stock_items`;
 *  - weight, dimensions, shelf life, storage, handling, lot/serial control, category:
 *    Manufacturing's `mfg_items` profile (created here, kind TRADED, for an item nobody makes);
 *  - units and conversions: `mfg_uoms` / `mfg_uom_conversions`, read through UomConverter;
 *  - substitutes: `mfg_item_substitutes`.
 *
 * Packaging is where a conversion is entered: saving "carton = 12" writes the one conversion row
 * the converter reads, so the packaging table and the converter can never disagree.
 */
class ItemCatalogue
{
    /** Container units a warehouse needs beyond Manufacturing's pack/pallet/roll. */
    private const WAREHOUSE_UNITS = [
        ['code' => 'inner', 'name' => 'Inner pack'],
        ['code' => 'ctn', 'name' => 'Carton'],
        ['code' => 'case', 'name' => 'Case'],
        ['code' => 'layer', 'name' => 'Pallet layer'],
    ];

    private const PROFILE_FIELDS = ['description', 'brand', 'purchase_uom_id', 'storage_uom_id', 'issue_uom_id',
        'is_variable_weight', 'safety_stock', 'max_stock', 'reorder_qty', 'lead_time_days',
        'min_remaining_shelf_life_days', 'expiry_warning_days', 'temperature_min_c', 'temperature_max_c',
        'hazard_class', 'requires_inspection', 'loss_risk', 'rotation', 'expiry_alert_days'];

    private const MFG_FIELDS = ['category_id', 'weight_kg', 'dimensions', 'shelf_life_days', 'storage_conditions',
        'handling_instructions', 'is_lot_tracked', 'is_serial_tracked'];

    public function __construct(private readonly InventoryService $inventory) {}

    /** Units for this company, installing the reference set the first time a warehouse needs them. */
    public function ensureUnits(int $companyId): void
    {
        if (! Uom::query()->where('company_id', $companyId)->exists()) {
            app(ManufacturingReferenceData::class)->installFor($companyId);
        }

        foreach (self::WAREHOUSE_UNITS as $u) {
            Uom::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => $u['code']],
                ['name' => $u['name'], 'dimension' => 'CONTAINER', 'is_base' => false, 'factor_to_base' => 1,
                    'decimal_places' => 0, 'is_active' => true],
            );
        }
    }

    public function create(array $data): FinStockItem
    {
        return DB::transaction(function () use ($data) {
            if (FinStockItem::query()->where('code', $data['code'])->exists()) {
                throw new RuntimeException("An item with code {$data['code']} already exists.");
            }

            $item = $this->inventory->createItem(array_intersect_key($data, array_flip([
                'company_id', 'code', 'name', 'uom', 'cost_method', 'standard_cost', 'reorder_level',
            ])) + ['is_active' => true, 'cost_method' => 'weighted_avg']);

            $this->update($item, $data, created: true);

            return $item->fresh();
        });
    }

    public function update(FinStockItem $item, array $data, bool $created = false): FinStockItem
    {
        return DB::transaction(function () use ($item, $data, $created) {
            $before = $created ? [] : $this->snapshot($item);

            if (isset($data['uom']) && $data['uom'] !== $item->uom && $this->hasMovements($item)) {
                throw new RuntimeException('The base unit cannot change once stock has moved: every balance is counted in it.');
            }
            if (array_key_exists('owner_party_id', $data) && (int) $data['owner_party_id'] !== (int) $item->owner_party_id) {
                // Whose goods these are decides whether they are valued. Changing it under stock
                // that has already moved would leave value on the books for goods that are not ours,
                // or none for goods that are.
                if ($this->hasMovements($item)) {
                    throw new RuntimeException('The owner cannot change once stock has moved. Create the item again under its new owner.');
                }
                $item->owner_party_id = $data['owner_party_id'] ?: null;
                $item->is_valued = $item->owner_party_id === null;
            }
            $item->fill(array_intersect_key($data, array_flip(['name', 'uom', 'reorder_level', 'standard_cost'])))->save();

            ItemProfile::query()->updateOrCreate(
                ['stock_item_id' => $item->id],
                ['company_id' => $item->company_id] + array_intersect_key($data, array_flip(self::PROFILE_FIELDS)),
            );

            $mfg = $this->mfgProfile($item);
            $physical = array_intersect_key($data, array_flip(self::MFG_FIELDS));
            foreach (['is_lot_tracked', 'is_serial_tracked'] as $flag) {
                if (array_key_exists($flag, $physical) && (bool) $physical[$flag] !== (bool) $mfg->{$flag} && $this->holdsStock($item)) {
                    throw new RuntimeException('Lot or serial control cannot change while the item is in stock: the stock already on the shelf has no lot to attach.');
                }
            }
            $mfg->fill($physical)->save();

            $this->event($item, $created ? 'CREATED' : 'UPDATED', $this->diff($before, $this->snapshot($item->fresh())));

            return $item;
        });
    }

    /**
     * Add or change a packaging level, and with it the conversion the converter reads.
     *
     * A unit of the same dimension as the base (a 500 g pack of a kg item) already has a factor;
     * a packaging level that contradicts it is refused rather than silently winning. A container
     * conversion already used by a posted movement cannot change: the movement recorded "3
     * cartons", and a new carton size would change what that receipt meant.
     */
    public function savePackaging(FinStockItem $item, array $data, ?ItemPackaging $packaging = null): ItemPackaging
    {
        $uom = Uom::query()->findOrFail($data['uom_id']);
        $base = $this->baseUom($item);
        $qty = (float) $data['qty_in_base'];

        if ($qty <= 0) {
            throw new RuntimeException('A packaging level must hold more than zero units.');
        }
        if (! empty($data['gtin']) && \App\Modules\Operations\WarehouseManagement\Support\Gs1Parser::normaliseGtin($data['gtin']) === null) {
            throw new RuntimeException("GTIN {$data['gtin']} has a wrong check digit.");
        }

        return DB::transaction(function () use ($item, $data, $packaging, $uom, $base, $qty) {
            if ($uom->id === $base->id) {
                if (abs($qty - 1) > 1e-9) {
                    throw new RuntimeException("The base unit {$base->code} always holds exactly 1.");
                }
            } elseif ($uom->isCompatibleWith($base)) {
                $implied = (float) $uom->factor_to_base / (float) $base->factor_to_base;
                if (abs($implied - $qty) > 1e-6) {
                    throw new RuntimeException("One {$uom->code} is always {$implied} {$base->code}; it cannot hold {$qty}.");
                }
            } else {
                $this->writeConversion($item, $uom, $base, $qty);
            }

            $before = $packaging?->only(['level', 'uom_id', 'qty_in_base', 'gtin']);
            $packaging ??= new ItemPackaging(['stock_item_id' => $item->id, 'company_id' => $item->company_id]);
            $packaging->fill(array_intersect_key($data, array_flip([
                'level', 'name', 'uom_id', 'qty_in_base', 'gtin', 'length_cm', 'width_cm', 'height_cm', 'gross_weight_kg', 'is_active',
            ])))->save();

            $this->event($item, 'PACKAGING', ['packaging' => [$before, $packaging->only(['level', 'uom_id', 'qty_in_base', 'gtin'])]]);

            return $packaging;
        });
    }

    public function removePackaging(ItemPackaging $packaging): void
    {
        $item = $packaging->item;
        if (FinStockMovement::query()->where('item_id', $item->id)->where('txn_uom_id', $packaging->uom_id)->exists()) {
            $packaging->update(['is_active' => false]);
            $this->event($item, 'PACKAGING', ['packaging' => [$packaging->only(['level', 'uom_id']), 'retired']]);

            return;
        }

        DB::transaction(function () use ($packaging, $item) {
            UomConversion::query()->where('stock_item_id', $item->id)
                ->where(fn ($q) => $q->where('from_uom_id', $packaging->uom_id)->orWhere('to_uom_id', $packaging->uom_id))->delete();
            $this->event($item, 'PACKAGING', ['packaging' => [$packaging->only(['level', 'uom_id', 'qty_in_base']), null]]);
            $packaging->delete();
        });
    }

    public function addIdentifier(FinStockItem $item, array $data): ItemIdentifier
    {
        $data['code'] = trim((string) $data['code']);
        $clash = ItemIdentifier::query()->where('kind', $data['kind'])->where('code', $data['code'])
            ->where('supplier_id', $data['supplier_id'] ?? null)->where('stock_item_id', '!=', $item->id)->with('item:id,code')->first();
        if ($clash) {
            throw new RuntimeException("That {$data['kind']} code already identifies {$clash->item?->code}.");
        }

        return DB::transaction(function () use ($item, $data) {
            $row = ItemIdentifier::query()->firstOrCreate(
                ['stock_item_id' => $item->id, 'kind' => $data['kind'], 'code' => $data['code'], 'supplier_id' => $data['supplier_id'] ?? null],
                ['company_id' => $item->company_id, 'party_name' => $data['party_name'] ?? null, 'description' => $data['description'] ?? null],
            );
            $this->event($item, 'IDENTIFIER', ['identifier' => [null, $data['kind'].' '.$data['code']]]);

            return $row;
        });
    }

    public function removeIdentifier(ItemIdentifier $identifier): void
    {
        $this->event($identifier->item, 'IDENTIFIER', ['identifier' => [$identifier->kind.' '.$identifier->code, null]]);
        $identifier->delete();
    }

    /**
     * Replace a bundle's components. A bundle may not contain itself, directly or through another
     * bundle -- picking it would never end.
     *
     * @param  array<int,float>  $components  component item id => qty
     */
    public function setBundle(FinStockItem $bundle, array $components): void
    {
        foreach ($components as $componentId => $qty) {
            if ((int) $componentId === $bundle->id || in_array($bundle->id, $this->bundleDescendants((int) $componentId), true)) {
                throw new RuntimeException('A bundle cannot contain itself.');
            }
            if ((float) $qty <= 0) {
                throw new RuntimeException('Every component needs a quantity above zero.');
            }
        }

        DB::transaction(function () use ($bundle, $components) {
            ItemBundle::query()->where('bundle_item_id', $bundle->id)->delete();
            foreach ($components as $componentId => $qty) {
                ItemBundle::create(['company_id' => $bundle->company_id, 'bundle_item_id' => $bundle->id,
                    'component_item_id' => (int) $componentId, 'qty' => $qty]);
            }
            $this->event($bundle, 'BUNDLE', ['components' => [null, $components]]);
        });
    }

    /** Register a permitted substitute, in Manufacturing's substitutes table. */
    public function addSubstitute(FinStockItem $item, FinStockItem $substitute, float $ratio, bool $requiresApproval, ?string $restriction = null): ItemSubstitute
    {
        if ($item->id === $substitute->id) {
            throw new RuntimeException('An item cannot substitute for itself.');
        }

        return DB::transaction(function () use ($item, $substitute, $ratio, $requiresApproval, $restriction) {
            $row = ItemSubstitute::query()->updateOrCreate(
                ['item_id' => $this->mfgProfile($item)->id, 'substitute_stock_item_id' => $substitute->id],
                ['company_id' => $item->company_id, 'ratio' => $ratio, 'requires_approval' => $requiresApproval,
                    'restriction' => $restriction, 'is_active' => true],
            );
            $this->event($item, 'SUBSTITUTE', ['substitute' => [null, $substitute->code]]);

            return $row;
        });
    }

    public function mfgProfile(FinStockItem $item): ItemProfileMfg
    {
        $profile = ItemProfileMfg::withoutGlobalScopes()->where('stock_item_id', $item->id)->first();

        if ($profile === null) {
            $this->ensureUnits((int) $item->company_id);
            $profile = ItemProfileMfg::create([
                'company_id' => $item->company_id, 'stock_item_id' => $item->id, 'kind' => 'TRADED',
                'default_uom_id' => Uom::query()->where('company_id', $item->company_id)->where('code', $item->uom ?: 'ea')->value('id'),
                'is_lot_tracked' => false, 'is_serial_tracked' => false, 'is_active' => true,
            ]);
        }

        return $profile;
    }

    public function baseUom(FinStockItem $item): Uom
    {
        $mfg = $this->mfgProfile($item);
        $uom = $mfg->default_uom_id ? Uom::query()->find($mfg->default_uom_id) : null;
        $uom ??= Uom::query()->where('company_id', $item->company_id)->where('code', $item->uom ?: 'ea')->first();

        if ($uom === null) {
            throw new RuntimeException("The item's base unit {$item->uom} is not a unit this company has set up.");
        }

        return $uom;
    }

    public function event(FinStockItem $item, string $action, array $changes, ?string $reason = null): void
    {
        if ($changes === [] && $action === 'UPDATED') {
            return;
        }

        ItemEvent::create([
            'company_id' => $item->company_id, 'stock_item_id' => $item->id, 'action' => $action,
            'changes' => $changes, 'reason' => $reason, 'actor_id' => Auth::id(), 'created_at' => now(),
        ]);
    }

    private function writeConversion(FinStockItem $item, Uom $uom, Uom $base, float $qty): void
    {
        $existing = UomConversion::query()->where('stock_item_id', $item->id)
            ->where('from_uom_id', $uom->id)->where('to_uom_id', $base->id)->first();

        if ($existing && abs((float) $existing->factor - $qty) > 1e-9
            && FinStockMovement::query()->where('item_id', $item->id)->where('txn_uom_id', $uom->id)->exists()) {
            throw new RuntimeException("Stock has already moved in {$uom->code} at {$existing->factor} {$base->code} each. Add a new packaging unit rather than changing this one.");
        }

        // A reverse row would answer the converter first in the other direction; one row only.
        UomConversion::query()->where('stock_item_id', $item->id)->where('from_uom_id', $base->id)->where('to_uom_id', $uom->id)->delete();
        UomConversion::query()->updateOrCreate(
            ['stock_item_id' => $item->id, 'from_uom_id' => $uom->id, 'to_uom_id' => $base->id],
            ['company_id' => $item->company_id, 'factor' => $qty],
        );
    }

    /** @return list<int> */
    private function bundleDescendants(int $itemId, int $depth = 0): array
    {
        if ($depth > 6) {
            return [];
        }
        $children = ItemBundle::query()->where('bundle_item_id', $itemId)->pluck('component_item_id')->all();
        $all = $children;
        foreach ($children as $c) {
            $all = array_merge($all, $this->bundleDescendants((int) $c, $depth + 1));
        }

        return $all;
    }

    private function hasMovements(FinStockItem $item): bool
    {
        return FinStockMovement::query()->where('item_id', $item->id)->exists();
    }

    private function holdsStock(FinStockItem $item): bool
    {
        return abs((float) FinStockPosition::query()->where('item_id', $item->id)->sum('qty')) > 0.0001;
    }

    private function snapshot(FinStockItem $item): array
    {
        $profile = ItemProfile::query()->where('stock_item_id', $item->id)->first();
        $mfg = ItemProfileMfg::withoutGlobalScopes()->where('stock_item_id', $item->id)->first();

        return array_merge(
            $item->only(['name', 'uom', 'reorder_level', 'standard_cost', 'owner_party_id', 'is_valued']),
            $profile ? $profile->only(self::PROFILE_FIELDS) : [],
            $mfg ? $mfg->only(self::MFG_FIELDS) : [],
        );
    }

    private function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            if (json_encode($before[$k] ?? null) !== json_encode($v)) {
                $out[$k] = [$before[$k] ?? null, $v];
            }
        }

        return $out;
    }
}
