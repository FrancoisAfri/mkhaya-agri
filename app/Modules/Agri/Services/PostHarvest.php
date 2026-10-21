<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\ActivityInput;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\GradingStandard;
use App\Modules\Agri\Models\HarvestRecord;
use App\Modules\Agri\Models\PackhouseOperation;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Models\StorageReading;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\Manufacturing\Services\ProductionRuns;
use App\Modules\Operations\Manufacturing\Services\Recalls;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 §8.1–8.4: what happens to produce after the field — on the warehouse and the factory.
 *
 * Every harvest that is kept becomes a produce lot: the farm's record of where it came from (field,
 * crop plan, harvest, grower). Produce in a store is also a stock lot, and the stock lot is what the
 * warehouse holds, picks, quarantines and ships; the produce lot follows it (syncFromStock).
 *
 * Grading, packing and processing stocked produce is a Manufacturing production run: the lots and
 * packaging go in, every grade or pack made comes out as its own lot at its share of the cost, and
 * the run is traced like any production order. The farm keeps the operation's labour, crew, machine
 * and checks for its own costing. Recalls are Manufacturing recalls, opened from the farm's lot; the
 * farm adds what only it knows — the field, the crop and the sprays that went on it — to the trace.
 *
 * Stores keep their conditions: a reading out of range stays open until someone records what was
 * done about it.
 */
class PostHarvest
{
    public function __construct(private readonly StockLedger $stock, private readonly FarmStores $stores) {}

    // ── §8.1 intake ──────────────────────────────────────────────────────────────────────────

    /** The lot a harvest is traced by: what was kept, net of rejects and spoilage. */
    public function lotFromHarvest(HarvestRecord $h): ?ProduceLot
    {
        if ($h->destination === 'loss') {
            return null;
        }
        $net = round((float) $h->quantity - (float) ($h->rejected_quantity ?? 0) - (float) ($h->spoiled_quantity ?? 0), 3);
        if ($net <= 0) {
            return null;
        }
        $plan = CropPlan::withoutGlobalScopes()->with('cropType')->find($h->crop_plan_id);
        $store = $h->store_id ? FarmStore::withoutGlobalScopes()->find($h->store_id) : null;

        $code = $h->lot_code ?: "H-{$h->id}";
        $stockLot = $h->destination === 'store' && $h->stock_item_id
            ? FinStockLot::withoutGlobalScopes()->where('company_id', $h->company_id)->where('item_id', $h->stock_item_id)->where('lot_number', $code)->value('id') : null;

        return ProduceLot::create(['company_id' => $h->company_id, 'farm_id' => $h->farm_id, 'code' => $code, 'kind' => 'harvest', 'stock_lot_id' => $stockLot,
            'product' => $plan?->cropType?->name ?? 'Produce', 'crop_type_id' => $plan?->crop_type_id, 'crop_plan_id' => $h->crop_plan_id, 'field_id' => $h->field_id,
            'harvest_record_id' => $h->id, 'grade' => $h->grade, 'quantity' => $net, 'remaining' => $h->destination === 'store' ? $net : 0, 'unit' => $h->unit,
            'stock_item_id' => $h->destination === 'store' ? $h->stock_item_id : null, 'store_id' => $store?->id, 'warehouse_id' => $h->destination === 'store' ? $h->warehouse_id : null,
            'location' => $h->storage_location, 'unit_cost' => $h->unit_value, 'produced_on' => $h->harvested_on,
            'status' => match ($h->destination) { 'store' => 'available', 'sold' => 'dispatched', default => 'consumed' }, 'created_by' => $h->recorded_by]);
    }

    // ── §8.2 grading, packing, processing ────────────────────────────────────────────────────

    /**
     * @param  list<array{lot: ProduceLot, quantity: float}>  $inputs
     * @param  list<array{product: string, grade?: ?string, quantity: float, unit: string, stock_item_id?: ?int, store_id?: ?int, location?: ?string, pallet_ref?: ?string, best_before?: ?string}>  $outputs
     * @param  list<array{stock_item_id: int, quantity: float}>  $packaging
     */
    public function operate(Farm $farm, string $kind, array $inputs, array $outputs, array $packaging, array $data, User $actor): PackhouseOperation
    {
        if (! in_array($kind, PackhouseOperation::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Say what kind of operation it was.']);
        }
        if (! $inputs || ! $outputs) {
            throw ValidationException::withMessages(['inputs' => 'An operation takes at least one lot in and makes at least one lot.']);
        }
        $standard = ! empty($data['standard_id']) ? GradingStandard::query()->findOrFail($data['standard_id']) : null;
        foreach ($inputs as $i) {
            $lot = $i['lot'];
            if ((int) $lot->farm_id !== (int) $farm->id) {
                throw ValidationException::withMessages(['inputs' => "Lot {$lot->code} is on another farm."]);
            }
            if ($lot->status !== 'available') {
                throw ValidationException::withMessages(['inputs' => "Lot {$lot->code} is {$lot->status} and cannot be used."]);
            }
            if ((float) $i['quantity'] <= 0 || (float) $i['quantity'] > (float) $lot->remaining + 0.0005) {
                throw ValidationException::withMessages(['inputs' => "Lot {$lot->code} has {$lot->remaining} {$lot->unit} left."]);
            }
        }
        foreach ($outputs as $o) {
            if ((float) $o['quantity'] <= 0) {
                throw ValidationException::withMessages(['outputs' => 'Each lot made needs a quantity.']);
            }
            if ($standard && ! empty($o['grade']) && ! in_array($o['grade'], $standard->gradeCodes(), true)) {
                throw ValidationException::withMessages(['outputs' => "Grade {$o['grade']} is not in the {$standard->name} standard."]);
            }
        }
        // Stocked produce in means stocked produce out: the cost has to land somewhere.
        $stocked = collect($inputs)->every(fn ($i) => $i['lot']->isStocked());
        if (collect($inputs)->contains(fn ($i) => $i['lot']->isStocked()) && ! $stocked) {
            throw ValidationException::withMessages(['inputs' => 'Use lots that are all in a store, or none that are.']);
        }
        if ($stocked && collect($outputs)->contains(fn ($o) => empty($o['stock_item_id']) || empty($o['store_id']))) {
            throw ValidationException::withMessages(['outputs' => 'The produce going in is in a store, so say which stock item and store each lot made goes into.']);
        }
        // Quality checks against the standard's limits.
        $checks = collect($data['quality_checks'] ?? [])->map(function ($c) use ($standard) {
            $rule = collect($standard?->checks ?? [])->firstWhere('check', $c['check']);
            $ok = array_key_exists('ok', $c) ? (bool) $c['ok'] : true;
            if ($rule && isset($c['value']) && is_numeric($c['value'])) {
                $ok = (! isset($rule['min']) || (float) $c['value'] >= (float) $rule['min']) && (! isset($rule['max']) || (float) $c['value'] <= (float) $rule['max']);
            }

            return ['check' => $c['check'], 'value' => $c['value'] ?? null, 'ok' => $ok, 'note' => $c['note'] ?? null];
        })->values();
        $inQty = collect($inputs)->sum(fn ($i) => (float) $i['quantity']);
        $outQty = collect($outputs)->sum(fn ($o) => (float) $o['quantity']);
        $loss = isset($data['loss_quantity']) ? (float) $data['loss_quantity'] : max(0, round($inQty - $outQty, 3));

        try {
            return DB::transaction(function () use ($farm, $kind, $inputs, $outputs, $packaging, $data, $actor, $standard, $checks, $inQty, $outQty, $loss, $stocked) {
                $n = PackhouseOperation::withoutGlobalScopes()->where('company_id', $farm->company_id)->count() + 1;
                $op = PackhouseOperation::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'reference' => sprintf('PH-%s-%05d', now()->format('y'), $n),
                    'kind' => $kind, 'standard_id' => $standard?->id, 'performed_on' => $data['performed_on'] ?? today()->toDateString(),
                    'input_quantity' => $inQty, 'output_quantity' => $outQty, 'loss_quantity' => $loss, 'loss_reason' => $data['loss_reason'] ?? null,
                    'crew_id' => $data['crew_id'] ?? null, 'workers' => $data['workers'] ?? null, 'labour_minutes' => $data['labour_minutes'] ?? null,
                    'labour_cost' => $data['labour_cost'] ?? null, 'machine_id' => $data['machine_id'] ?? null, 'machine_hours' => $data['machine_hours'] ?? null,
                    'quality_checks' => $checks->all() ?: null, 'quality_passed' => $checks->every(fn ($c) => $c['ok']), 'notes' => $data['notes'] ?? null, 'recorded_by' => $actor->id]);
                $date = $op->performed_on->toDateString();
                $first = $inputs[0]['lot'];
                $codes = collect($outputs)->map(fn ($o, $k) => $o['code'] ?? sprintf('%s-%s%d', $first->code, strtoupper(substr($kind, 0, 1)), $op->id * 10 + $k + 1))->all();

                $run = $stocked ? $this->run($farm, $op, $kind, $inputs, $outputs, $packaging, $codes, $checks->all(), $actor, $data['bom_id'] ?? null) : null;
                if (! $stocked) {
                    // Produce never put in a store has no stock to move; its packaging is a packhouse cost.
                    $used = [];
                    foreach ($packaging as $k => $p) {
                        $item = FinStockItem::withoutGlobalScopes()->findOrFail($p['stock_item_id']);
                        $m = $this->stock->issueTo($item, (float) $p['quantity'], $this->stores->storeFor($farm), ['source_type' => 'agri_packhouse', 'source_id' => $op->id,
                            'date' => $date, 'reference' => "{$op->reference} {$kind}", 'idempotency_key' => "agri-ph-{$op->uuid}-pk{$k}",
                            'expense_account' => config('agri.accounts.inputs'), 'dimensions' => AgriLedger::dimensions($farm, null, null, 'packaging')]);
                        $used[] = ['stock_item_id' => $item->id, 'item' => $item->name, 'qty' => (float) $p['quantity'], 'movement_id' => $m->id, 'value' => round(abs((float) $m->value), 2)];
                    }
                    $op->update(['packaging' => $used ?: null]);
                    foreach ($inputs as $i) {
                        $left = round((float) $i['lot']->remaining - (float) $i['quantity'], 3);
                        $i['lot']->update(['remaining' => max(0, $left), 'status' => $left <= 0.0005 ? 'consumed' : $i['lot']->status]);
                    }
                }

                // The lots made: the farm's provenance of each, linked to every input in proportion.
                $made = $run ? $run->outputs()->orderBy('id')->get()->values() : collect();
                foreach (array_values($outputs) as $k => $o) {
                    $store = ! empty($o['store_id']) ? FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->findOrFail($o['store_id']) : null;
                    $share = $outQty > 0 ? (float) $o['quantity'] / $outQty : 0;
                    $out = $made[$k] ?? null;
                    $lot = ProduceLot::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'code' => $codes[$k],
                        'kind' => match ($kind) { 'packing', 'repacking' => 'packed', 'processing' => 'processed', default => 'graded' },
                        'product' => $o['product'], 'crop_type_id' => $first->crop_type_id, 'crop_plan_id' => collect($inputs)->pluck('lot.crop_plan_id')->unique()->count() === 1 ? $first->crop_plan_id : null,
                        'field_id' => collect($inputs)->pluck('lot.field_id')->unique()->count() === 1 ? $first->field_id : null,
                        'grade' => $o['grade'] ?? null, 'quantity' => $o['quantity'], 'remaining' => $o['quantity'], 'unit' => $o['unit'],
                        'stock_item_id' => $stocked ? $o['stock_item_id'] : null, 'store_id' => $store?->id, 'warehouse_id' => $stocked ? $store->warehouse_id : null,
                        'stock_lot_id' => $out?->lot_id, 'location' => $o['location'] ?? null, 'pallet_ref' => $o['pallet_ref'] ?? null, 'unit_cost' => $out?->unit_cost,
                        'status' => $op->quality_passed ? 'available' : 'quarantined', 'produced_on' => $date, 'best_before' => $o['best_before'] ?? null, 'created_by' => $actor->id]);
                    foreach ($inputs as $i) {
                        DB::table('produce_lot_links')->insert(['company_id' => $farm->company_id, 'parent_lot_id' => $i['lot']->id, 'child_lot_id' => $lot->id,
                            'operation_id' => $op->id, 'quantity' => round((float) $i['quantity'] * $share, 3), 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
                if ($run) {
                    $components = $run->components()->get();
                    $op->update(['production_order_id' => $run->id,
                        'packaging' => $components->where('line_kind', 'PACKAGING')->map(fn ($c) => ['stock_item_id' => $c->stock_item_id,
                            'item' => FinStockItem::withoutGlobalScopes()->whereKey($c->stock_item_id)->value('name'), 'qty' => (float) $c->issued_qty, 'value' => (float) $c->issued_value])->values()->all() ?: null,
                        'input_value' => round((float) $components->where('line_kind', 'COMPONENT')->sum('issued_value'), 2), 'output_value' => (float) $run->act_material_cost]);
                    foreach ($inputs as $i) {
                        $this->syncFromStock($i['lot']->fresh());
                    }
                }

                return $op->fresh();
            });
        } catch (PostingException|\DomainException $e) {
            throw ValidationException::withMessages(['inputs' => $e->getMessage()]);
        }
    }

    /** The production run that grades or packs stocked produce, at the farm's packhouse. */
    private function run(Farm $farm, PackhouseOperation $op, string $kind, array $inputs, array $outputs, array $packaging, array $codes, array $checks, User $actor, ?int $bomId = null): ProductionOrder
    {
        $wh = fn (?int $id) => FinWarehouse::withoutGlobalScopes()->findOrFail($id);
        $item = fn (int $id) => FinStockItem::withoutGlobalScopes()->findOrFail($id);
        $stores = FarmStore::withoutGlobalScopes()->where('farm_id', $farm->id)->pluck('warehouse_id', 'id');
        $first = $inputs[0]['lot'];

        return app(ProductionRuns::class)->record([
            'factory' => $this->packhouse($farm), 'warehouse' => $wh($first->warehouse_id), 'performed_on' => $op->performed_on->toDateString(),
            'description' => ucfirst($kind)." at {$farm->name} ({$op->reference})", 'demand_type' => 'AGRI_PACKHOUSE', 'demand_id' => $op->id, 'demand_reference' => $op->reference,
            'loss_qty' => (float) $op->loss_quantity, 'loss_reason' => $op->loss_reason, 'labour_cost' => $op->labour_cost, 'labour_minutes' => $op->labour_minutes,
            'quality_passed' => $op->quality_passed, 'quality_checks' => $checks ?: null, 'key' => "agri-ph-{$op->uuid}", 'bom_id' => $bomId,
            'dimensions' => AgriLedger::dimensions($farm, null, null, 'packhouse'),
            'inputs' => collect($inputs)->map(fn ($i) => ['item' => $item((int) $i['lot']->stock_item_id), 'qty' => (float) $i['quantity'],
                'lot_id' => $i['lot']->stock_lot_id, 'warehouse' => $wh($i['lot']->warehouse_id)])->all(),
            'packaging' => collect($packaging)->map(fn ($p) => ['item' => $item((int) $p['stock_item_id']), 'qty' => (float) $p['quantity'], 'warehouse' => $this->stores->storeFor($farm)])->all(),
            'outputs' => collect(array_values($outputs))->map(fn ($o, $k) => ['item' => $item((int) $o['stock_item_id']), 'qty' => (float) $o['quantity'], 'lot_number' => $codes[$k],
                'grade' => $o['grade'] ?? null, 'expires_on' => $o['best_before'] ?? null, 'warehouse' => $wh($stores[$o['store_id']] ?? null)])->all(),
        ], $actor);
    }

    /** The farm's packhouse as a Manufacturing factory, opened the first time produce is graded or packed. */
    public function packhouse(Farm $farm): Factory
    {
        return Factory::withoutGlobalScopes()->firstOrCreate(['company_id' => $farm->company_id, 'code' => \Illuminate\Support\Str::limit('PH-'.strtoupper($farm->code), 32, '')],
            ['name' => "{$farm->name} packhouse", 'address' => $farm->location, 'timezone' => $farm->timezone ?: 'Africa/Johannesburg',
                'default_warehouse_id' => $this->stores->storeFor($farm)->id, 'is_active' => true]);
    }

    /**
     * A produce lot in a store follows its stock lot: what is on hand, and whether it is usable,
     * held or gone — whichever module moved it (the warehouse picking it, a recall holding it).
     */
    public function syncFromStock(ProduceLot $lot): void
    {
        if (! $lot->stock_lot_id || in_array($lot->status, ['written_off'], true)) {
            return;
        }
        $positions = FinStockPosition::withoutGlobalScopes()->where('lot_id', $lot->stock_lot_id)->get();
        $byStatus = $positions->groupBy('status')->map(fn ($g) => (float) $g->sum('qty'));
        $onHand = round((float) $byStatus->sum(), 3);
        if ($onHand > 0.0005) {
            $status = match (true) {
                ($byStatus[FinStockPosition::AVAILABLE] ?? 0) > 0.0005 => 'available',
                ($byStatus[FinStockPosition::ON_HOLD] ?? 0) > 0.0005 => 'recalled',
                default => 'quarantined',
            };
        } else {
            $last = FinStockMovement::withoutGlobalScopes()->where('lot_id', $lot->stock_lot_id)->latest('id')->value('type');
            $status = match ($last) { 'dispatch', 'sale' => 'dispatched', 'scrap' => 'written_off', default => 'consumed' };
        }
        $lot->forceFill(['remaining' => max(0, $onHand), 'status' => $status])->saveQuietly();
    }

    /** Move a lot to another place in the store, or to another store (the stock moves with it). */
    public function move(ProduceLot $lot, ?FarmStore $to, ?string $location, User $actor): ProduceLot
    {
        if (! in_array($lot->status, ProduceLot::LIVE, true)) {
            throw ValidationException::withMessages(['lot' => "Lot {$lot->code} is {$lot->status}."]);
        }
        if ($to && (int) $to->id !== (int) $lot->store_id && $lot->isStocked()) {
            $item = FinStockItem::withoutGlobalScopes()->findOrFail($lot->stock_item_id);
            $this->stock->transfer($item, FinWarehouse::withoutGlobalScopes()->findOrFail($lot->warehouse_id), FinWarehouse::withoutGlobalScopes()->findOrFail($to->warehouse_id),
                (float) $lot->remaining, ['reference' => "Lot {$lot->code} to {$to->name}", 'idempotency_key' => "agri-lot-move-{$lot->uuid}-".now()->format('YmdHis'),
                    'lot_id' => $lot->stock_lot_id, 'status' => $lot->status === 'available' ? FinStockPosition::AVAILABLE : FinStockPosition::QUARANTINE]);
        }
        $lot->update(['store_id' => $to?->id ?? $lot->store_id, 'warehouse_id' => $to && $lot->isStocked() ? $to->warehouse_id : $lot->warehouse_id, 'location' => $location]);

        return $lot;
    }

    // ── §8.3 storage conditions ──────────────────────────────────────────────────────────────

    public function reading(FarmStore $store, array $data, ?User $actor, string $source = 'manual'): StorageReading
    {
        if (! empty($data['client_id']) && ($done = StorageReading::withoutGlobalScopes()->where('store_id', $store->id)->where('client_id', $data['client_id'])->first())) {
            return $done;   // a sensor or phone sending the same reading again
        }
        $t = isset($data['temperature']) ? (float) $data['temperature'] : null;
        $h = isset($data['humidity']) ? (float) $data['humidity'] : null;
        $off = [];
        if ($t !== null && $store->temperature_min !== null && $t < (float) $store->temperature_min) {
            $off[] = "temperature {$t}°C below {$store->temperature_min}°C";
        }
        if ($t !== null && $store->temperature_max !== null && $t > (float) $store->temperature_max) {
            $off[] = "temperature {$t}°C above {$store->temperature_max}°C";
        }
        if ($h !== null && $store->humidity_min !== null && $h < (float) $store->humidity_min) {
            $off[] = "humidity {$h}% below {$store->humidity_min}%";
        }
        if ($h !== null && $store->humidity_max !== null && $h > (float) $store->humidity_max) {
            $off[] = "humidity {$h}% above {$store->humidity_max}%";
        }
        $r = StorageReading::create(['company_id' => $store->company_id, 'farm_id' => $store->farm_id, 'store_id' => $store->id,
            'recorded_at' => $data['recorded_at'] ?? now(), 'temperature' => $t, 'humidity' => $h, 'other' => $data['other'] ?? null, 'source' => $source,
            'out_of_range' => $off !== [], 'deviation' => $off ? ucfirst(implode('; ', $off)) : null, 'recorded_by' => $actor?->id, 'client_id' => $data['client_id'] ?? null]);
        if ($off) {
            app(AgriNotifier::class)->storageOutOfRange($store, $r);
        }

        return $r;
    }

    public function correct(StorageReading $r, string $action, User $actor): StorageReading
    {
        if (! $r->out_of_range) {
            throw ValidationException::withMessages(['reading' => 'That reading was within range.']);
        }
        if ($r->corrective_action) {
            throw ValidationException::withMessages(['reading' => 'A corrective action is already recorded.']);
        }
        $r->update(['corrective_action' => $action, 'acted_by' => $actor->id, 'acted_at' => now()]);

        return $r;
    }

    // ── §8.4 traceability ────────────────────────────────────────────────────────────────────

    /**
     * Backward: every lot this one was made from, back to the harvest — with the field, the crop,
     * the inputs applied to the crop before it was harvested, and what was done to the produce on
     * the way (operations, storage readings while it was held).
     */
    public function backward(ProduceLot $lot): array
    {
        $seen = [];
        $lots = [];
        $walk = function (int $id, int $depth) use (&$walk, &$seen, &$lots) {
            if (isset($seen[$id]) || $depth > 25) {
                return;
            }
            $seen[$id] = true;
            $l = ProduceLot::withoutGlobalScopes()->find($id);
            if (! $l) {
                return;
            }
            $lots[] = $l;
            foreach (DB::table('produce_lot_links')->where('child_lot_id', $id)->pluck('parent_lot_id') as $p) {
                $walk((int) $p, $depth + 1);
            }
        };
        $walk($lot->id, 0);
        $collection = collect($lots);
        $harvests = $collection->where('kind', 'harvest');

        $sources = $harvests->map(function (ProduceLot $h) {
            $rec = HarvestRecord::withoutGlobalScopes()->find($h->harvest_record_id);
            $plan = $h->crop_plan_id ? CropPlan::withoutGlobalScopes()->with(['field', 'cropType'])->find($h->crop_plan_id) : null;
            $inputs = $plan ? ActivityInput::withoutGlobalScopes()->where('status', ActivityInput::ISSUED)
                ->whereHas('activity', fn ($q) => $q->withoutGlobalScopes()->where('crop_plan_id', $plan->id)->whereDate('completed_at', '<=', $h->produced_on))
                ->with(['activity' => fn ($q) => $q->withoutGlobalScopes()])->get()
                ->map(fn ($i) => ['item' => FinStockItem::withoutGlobalScopes()->whereKey($i->stock_item_id)->value('name'), 'quantity' => (float) $i->quantity, 'uom' => $i->uom,
                    'applied_on' => $i->activity?->completed_at?->toDateString(), 'activity' => $i->activity?->title, 'withholding_days' => $i->withholding_days])->values()->all() : [];

            return ['lot' => $h->code, 'harvested_on' => $h->produced_on->toDateString(), 'field' => $plan?->field?->code, 'crop_plan' => $plan?->code,
                'crop' => $plan?->cropType?->name, 'crew_id' => $rec?->crew_id, 'containers' => $rec?->container_ref, 'inspection' => $rec?->inspection_outcome, 'inputs' => $inputs];
        })->values()->all();

        $ops = PackhouseOperation::withoutGlobalScopes()->whereIn('id', DB::table('produce_lot_links')->whereIn('child_lot_id', $collection->pluck('id'))->pluck('operation_id')->filter())
            ->orderBy('performed_on')->get()->map(fn ($o) => ['reference' => $o->reference, 'kind' => $o->kind, 'on' => $o->performed_on->toDateString(),
                'production_order' => $o->production_order_id ? ProductionOrder::withoutGlobalScopes()->whereKey($o->production_order_id)->first(['uuid', 'number'])?->only(['uuid', 'number']) : null,
                'loss' => $o->loss_quantity, 'quality_passed' => $o->quality_passed, 'checks' => $o->quality_checks])->values()->all();
        $storage = StorageReading::withoutGlobalScopes()->whereIn('store_id', $collection->pluck('store_id')->filter()->unique())->where('out_of_range', true)
            ->where('recorded_at', '>=', $collection->min('produced_on'))->orderBy('recorded_at')->limit(50)->get()
            ->map(fn ($r) => ['store_id' => $r->store_id, 'at' => $r->recorded_at?->format('Y-m-d H:i'), 'deviation' => $r->deviation, 'action' => $r->corrective_action])->values()->all();

        // §16D: produce bought in from outgrowers traces to the grower, the contract and the last inspection.
        $growers = $collection->where('kind', 'collected')->map(function (ProduceLot $l) {
            $c = \App\Modules\Agri\Models\GrowerCollection::query()->where('lot_id', $l->id)->first();
            $g = $c ? \App\Modules\Agri\Models\Grower::query()->with('party')->find($c->grower_id) : null;
            $insp = $c ? \App\Modules\Agri\Models\GrowerInspection::query()->where('contract_id', $c->contract_id)->latest('inspected_on')->first() : null;

            return ['lot' => $l->code, 'grower' => $g?->code, 'name' => $g?->party?->name, 'location' => $g?->location, 'collected_on' => $c?->collected_on?->toDateString(),
                'contract' => $c ? \App\Modules\Agri\Models\GrowerContract::query()->whereKey($c->contract_id)->value('reference') : null,
                'last_inspection' => $insp ? ['on' => $insp->inspected_on->toDateString(), 'outcome' => $insp->outcome] : null];
        })->values()->all();

        return ['lot' => $this->lotRow($lot), 'lots' => $collection->map(fn ($l) => $this->lotRow($l))->values()->all(), 'harvests' => $sources, 'growers' => $growers,
            'operations' => $ops, 'storage_events' => $storage];
    }

    /** Forward: every lot made from this one, where each is now, and every shipment any of them went out on (the warehouse's). */
    public function forward(ProduceLot $lot): array
    {
        $ids = $this->descendants($lot->id);
        $lots = ProduceLot::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->get();
        $stores = FarmStore::withoutGlobalScopes()->whereIn('id', $lots->pluck('store_id')->filter())->pluck('name', 'id');
        $codeOf = $lots->whereNotNull('stock_lot_id')->pluck('code', 'stock_lot_id');
        $deliveries = $codeOf->isEmpty() ? [] : DB::table('wms_package_contents as c')
            ->join('wms_shipment_packages as sp', 'sp.package_id', '=', 'c.package_id')->join('wms_shipments as s', 's.id', '=', 'sp.shipment_id')
            ->join('wms_orders as o', 'o.id', '=', 's.order_id')->leftJoin('fin_sales_orders as so', fn ($j) => $j->on('so.id', '=', 'o.source_id')->where('o.source', 'SALES_ORDER'))
            ->whereIn('c.lot_id', $codeOf->keys())
            ->get(['s.id', 's.uuid', 's.number', 's.status', 's.dispatched_at', 's.delivered_at', 'o.customer_name', 'o.ship_to', 'so.number as order', 'c.lot_id', 'c.qty'])
            ->groupBy('id')->map(fn ($rows) => ['dispatch' => $rows[0]->number, 'shipment' => $rows[0]->uuid, 'order' => $rows[0]->order, 'buyer' => $rows[0]->customer_name,
                'address' => collect(json_decode((string) $rows[0]->ship_to, true) ?: [])->except('name')->implode(', ') ?: null,
                'status' => strtolower($rows[0]->status), 'dispatched_at' => $rows[0]->dispatched_at ? Carbon::parse($rows[0]->dispatched_at)->format('Y-m-d H:i') : null,
                'delivered_at' => $rows[0]->delivered_at ? Carbon::parse($rows[0]->delivered_at)->format('Y-m-d H:i') : null,
                'lots' => $rows->map(fn ($r) => ['lot' => $codeOf[$r->lot_id] ?? null, 'quantity' => (float) $r->qty])->values()->all()])->values()->all();

        return ['lot' => $this->lotRow($lot), 'lots' => $lots->map(fn ($l) => $this->lotRow($l) + ['store' => $stores[$l->store_id] ?? null])->values()->all(),
            'deliveries' => $deliveries];
    }

    /** @return list<int> the lot and everything made from it */
    public function descendants(int $id): array
    {
        $all = [$id];
        $frontier = [$id];
        for ($depth = 0; $frontier && $depth < 25; $depth++) {
            $next = DB::table('produce_lot_links')->whereIn('parent_lot_id', $frontier)->pluck('child_lot_id')->map(fn ($x) => (int) $x)->diff($all)->values()->all();
            $all = array_merge($all, $next);
            $frontier = $next;
        }

        return $all;
    }

    // ── §8.4 recalls ─────────────────────────────────────────────────────────────────────────

    public const RECALL_CLASS = ['high' => 'CLASS_I', 'medium' => 'CLASS_II', 'low' => 'CLASS_III'];

    /**
     * Open a Manufacturing recall from a farm lot: it traces every lot the stock lot reached, holds
     * whatever is still in a store, and lists every customer shipment. Releasing, disposing and
     * closing are done on the recall in Manufacturing.
     */
    public function recall(ProduceLot $lot, string $reason, string $severity, User $actor): Recall
    {
        $stockLot = $lot->stock_lot_id ? FinStockLot::withoutGlobalScopes()->find($lot->stock_lot_id) : null;
        if (! $stockLot) {
            throw ValidationException::withMessages(['lot' => "Lot {$lot->code} never went into a store, so there is no stock to hold. Its forward trace lists who received it."]);
        }

        try {
            return DB::transaction(function () use ($lot, $stockLot, $reason, $severity, $actor) {
                $recalls = app(Recalls::class);
                $recall = $recalls->open($stockLot, "{$lot->product} lot {$lot->code}", $reason, self::RECALL_CLASS[$severity] ?? 'CLASS_I', $actor);
                $recalls->holdAll($recall, $actor);

                return $recall;
            });
        } catch (\DomainException|PostingException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }
    }

    /** The recalls that reach this farm's produce. */
    public function recallsFor(Farm $farm)
    {
        $lotIds = ProduceLot::withoutGlobalScopes()->where('farm_id', $farm->id)->whereNotNull('stock_lot_id')->pluck('stock_lot_id');

        return Recall::query()->whereHas('lots', fn ($q) => $q->whereIn('lot_id', $lotIds))->withCount('lots')->orderByDesc('id');
    }

    /** Write off produce that failed its checks: out of quarantine as scrap. Recalled stock is disposed of on its recall. */
    public function writeOff(ProduceLot $lot, string $reason, User $actor): ProduceLot
    {
        if ($lot->status === 'recalled') {
            throw ValidationException::withMessages(['lot' => "Lot {$lot->code} is held under a recall: dispose of it on the recall in Manufacturing."]);
        }
        if ($lot->status !== 'quarantined' || $lot->remaining <= 0) {
            throw ValidationException::withMessages(['lot' => 'Only quarantined produce on hand is written off.']);
        }
        if ($lot->isStocked()) {
            $this->stock->scrap(FinStockItem::withoutGlobalScopes()->findOrFail($lot->stock_item_id), (float) $lot->remaining,
                FinWarehouse::withoutGlobalScopes()->findOrFail($lot->warehouse_id), ['source_type' => 'agri_lot', 'source_id' => $lot->id, 'lot_id' => $lot->stock_lot_id,
                    'idempotency_key' => "agri-lot-scrap-{$lot->uuid}", 'reference' => "Write-off {$lot->code}: {$reason}", 'status' => FinStockPosition::QUARANTINE]);
        }
        $lot->update(['status' => 'written_off', 'remaining' => 0]);

        return $lot;
    }

    public function lotRow(ProduceLot $l): array
    {
        return ['id' => $l->id, 'uuid' => $l->uuid, 'code' => $l->code, 'kind' => $l->kind, 'product' => $l->product, 'grade' => $l->grade,
            'quantity' => $l->quantity, 'remaining' => $l->remaining, 'unit' => $l->unit, 'status' => $l->status, 'location' => $l->location,
            'pallet_ref' => $l->pallet_ref, 'produced_on' => $l->produced_on?->toDateString(), 'best_before' => $l->best_before?->toDateString(), 'unit_cost' => $l->unit_cost,
            'stock_lot_id' => $l->stock_lot_id];
    }
}
