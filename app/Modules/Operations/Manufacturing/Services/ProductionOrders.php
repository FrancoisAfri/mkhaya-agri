<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderEvent;
use App\Modules\Operations\Manufacturing\Models\Routing;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creating a production order and working out what it will cost (MFG-006).
 *
 * The order is pinned at creation to the BOM and routing revisions in force on its planned start
 * date -- not to whatever is default -- and its components and operations are copied from them. An
 * engineering change made later cannot reach it; that is what "historical production orders must
 * retain the BOM version used" means in practice.
 */
class ProductionOrders
{
    public function __construct(
        private readonly BomResolver $boms,
        private readonly RoutingResolver $routings,
        private readonly BomExploder $exploder,
        private readonly RoutingCalculator $calculator,
        private readonly NumberSequences $numbers,
    ) {}

    /**
     * @param  array{item: Item, quantity: float, due_date: string, factory: Factory, warehouse: FinWarehouse,
     *               component_warehouse?: ?FinWarehouse, priority?: int, supervisor_id?: ?int, start?: ?string,
     *               demand_type?: ?string, demand_id?: ?int, demand_reference?: ?string, notes?: ?string,
     *               allow_below_min_batch?: bool}  $data
     */
    public function create(array $data, ?User $actor): ProductionOrder
    {
        $item = $data['item'];
        $qty = (float) $data['quantity'];
        $factory = $data['factory'];
        $start = isset($data['start']) && $data['start'] ? Carbon::parse($data['start']) : now();

        if ($qty <= 0) {
            throw new DomainException('A production order must be for a quantity greater than zero.');
        }
        if (! $item->isManufactured() || ! $item->is_active) {
            throw new DomainException('Only an active finished product, intermediate or subassembly can be produced.');
        }

        // MFG-024: a product restricted to some factories is made only there.
        if (! app(MultiFactory::class)->allowedAt($item, $factory)) {
            throw new DomainException("This product is not made at {$factory->code}; its factory policy lists where it may be.");
        }

        $bom = $this->boms->applicable($item->id, $start);
        if (! $bom) {
            throw new DomainException('This product has no approved bill of materials in force on '.$start->toDateString().'.');
        }

        $policy = FactoryPolicy::for($factory->id, $factory->company_id);
        $routing = $this->routings->applicable($item->id, $start);

        if (! $routing && $policy->require_routing) {
            throw new DomainException('This product has no approved routing, and this factory requires one before production.');
        }

        if ($routing && $routing->min_batch_size !== null && $qty < (float) $routing->min_batch_size
            && empty($data['allow_below_min_batch'])) {
            throw new DomainException(sprintf('%s is below the routing\'s minimum batch of %s.',
                $this->num($qty), $this->num((float) $routing->min_batch_size)));
        }

        return DB::transaction(function () use ($data, $item, $qty, $factory, $start, $bom, $routing, $actor) {
            $order = ProductionOrder::create([
                'company_id' => $factory->company_id,
                'number' => $this->numbers->next($factory->company_id, 'PRODUCTION_ORDER', $factory->id),
                'factory_id' => $factory->id,
                'warehouse_id' => $data['warehouse']->id,
                'component_warehouse_id' => ($data['component_warehouse'] ?? null)?->id ?? $data['warehouse']->id,
                'item_id' => $item->id,
                'bom_id' => $bom->id,
                'routing_id' => $routing?->id,
                'planned_qty' => $qty,
                'due_date' => $data['due_date'],
                'priority' => $data['priority'] ?? 3,
                'status' => ProductionOrder::DRAFT,
                'supervisor_id' => $data['supervisor_id'] ?? null,
                'demand_type' => $data['demand_type'] ?? 'MANUAL',
                'demand_id' => $data['demand_id'] ?? null,
                'demand_reference' => $data['demand_reference'] ?? null,
                'planned_start' => $start,
                // The approved standard in force when the order was raised: what its variances are measured against.
                'standard_cost_id' => app(StandardCosting::class)->current($item->id)?->id,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->copyComponents($order, $bom, $qty, $start);
            $this->copyOperations($order, $routing, $qty);
            $this->estimate($order);

            $this->event($order, 'CREATED', null, ProductionOrder::DRAFT, $actor, null, [
                'bom_revision' => $bom->revision, 'routing_revision' => $routing?->revision,
            ]);

            return $order->refresh();
        });
    }

    /**
     * Rebuild a draft's components, operations and estimates for a new quantity. Only a draft may be
     * re-scaled; anything later has reservations and history pointing at its lines.
     */
    public function rescale(ProductionOrder $order, float $qty, ?User $actor): ProductionOrder
    {
        if (! $order->isEditable()) {
            throw new DomainException("Order {$order->number} is {$order->status}; only a draft can be changed.");
        }

        return DB::transaction(function () use ($order, $qty, $actor) {
            $was = (float) $order->planned_qty;
            $order->update(['planned_qty' => $qty]);
            $order->components()->delete();
            $order->operations()->delete();

            $bom = $order->bom()->first();
            $this->copyComponents($order, $bom, $qty, $order->planned_start ?? now());
            $this->copyOperations($order, $order->routing()->first(), $qty);
            $this->estimate($order);

            $this->event($order, 'RESCALED', $order->status, $order->status, $actor, null, ['from' => $was, 'to' => $qty]);

            return $order->refresh();
        });
    }

    /**
     * Estimated material, labour, machine, overhead and subcontract cost (MFG-006).
     *
     * Material at each component's current cost; machine and labour from each operation's planned
     * minutes at its work centre's rates; overhead as the factory's configured share of labour and
     * machine. A rate nobody has set is not costed at zero silently -- it is reported, because an
     * order that looks free is worse than one that says it cannot be priced.
     *
     * @return list<string> what could not be priced
     */
    public function estimate(ProductionOrder $order): array
    {
        $unpriced = [];
        $material = 0.0;

        foreach ($order->components()->with('stockItem')->get() as $c) {
            if (! $c->isConsumed()) {
                continue;
            }
            $unit = $this->unitCost($c->stockItem);
            if ($unit <= 0) {
                $unpriced[] = "material {$c->stockItem?->code}";
            }
            $c->update(['est_unit_cost' => $unit]);
            $material += $unit * (float) $c->required_qty;
        }

        $labour = 0.0;
        $machine = 0.0;
        $subcontract = 0.0;

        foreach ($order->operations()->with('workCentre')->get() as $op) {
            $wc = $op->workCentre;
            if ($op->is_subcontracted) {
                continue;
            }
            if ($wc && $wc->cost_per_hour !== null) {
                $machine += (float) $op->planned_minutes / 60 * (float) $wc->cost_per_hour;
            } elseif ((float) $op->planned_minutes > 0) {
                $unpriced[] = "machine time at {$op->name}";
            }
            if ($wc && $wc->labour_rate_per_hour !== null) {
                $labour += (float) $op->planned_labour_minutes / 60 * (float) $wc->labour_rate_per_hour;
            } elseif ((float) $op->planned_labour_minutes > 0) {
                $unpriced[] = "labour at {$op->name}";
            }
        }

        if ($order->routing_id) {
            foreach ($order->routing()->first()->operations()->where('is_subcontracted', true)->get() as $rop) {
                $subcontract += (float) $rop->subcontract_cost_per_unit * (float) $order->planned_qty;
            }
        }

        $policy = FactoryPolicy::for($order->factory_id, $order->company_id);
        $overhead = $policy->overhead_percent !== null ? ($labour + $machine) * (float) $policy->overhead_percent / 100 : 0.0;

        $order->update([
            'est_material_cost' => round($material, 2),
            'est_labour_cost' => round($labour, 2),
            'est_machine_cost' => round($machine, 2),
            'est_overhead_cost' => round($overhead, 2),
            'est_subcontract_cost' => round($subcontract, 2),
        ]);

        return array_values(array_unique($unpriced));
    }

    /** Standard material cost of one good unit -- what finished goods are received into stock at. */
    public function standardMaterialUnitCost(ProductionOrder $order): float
    {
        $qty = (float) $order->planned_qty;

        return $qty > 0 ? round((float) $order->est_material_cost / $qty, 4) : 0.0;
    }

    private function copyComponents(ProductionOrder $order, $bom, float $qty, $asOf): void
    {
        $exploded = $this->exploder->explode($bom->load(['lines.uom', 'lines.component', 'yieldUom']), $qty, true, $asOf);

        foreach ([[$exploded['requirements'], false], [$exploded['by_products'], true]] as [$rows, $byProduct]) {
            foreach ($rows as $r) {
                $order->components()->create([
                    'company_id' => $order->company_id,
                    'stock_item_id' => $r['stock_item_id'],
                    'line_kind' => $byProduct ? 'BY_PRODUCT' : ($r['line_kind'] ?? 'COMPONENT'),
                    'required_qty' => round($r['quantity'], 6),
                    'uom' => $r['uom'],
                ]);
            }
        }
    }

    private function copyOperations(ProductionOrder $order, ?Routing $routing, float $qty): void
    {
        if (! $routing) {
            return;
        }

        $estimate = $this->calculator->estimate($routing, $qty);
        $byUuid = collect($estimate['operations'])->keyBy('operation_id');

        foreach ($routing->operations()->get() as $rop) {
            $row = $byUuid[$rop->uuid] ?? null;

            $order->operations()->create([
                'company_id' => $order->company_id,
                'routing_operation_id' => $rop->id,
                'sequence' => $rop->sequence,
                'name' => $rop->name,
                'work_centre_id' => $rop->work_centre_id,
                'machine_type' => $rop->machine_type,
                'parallel_group' => $rop->parallel_group,
                'is_mandatory' => $rop->is_mandatory,
                'is_inspection_point' => $rop->is_inspection_point,
                'is_approval_gate' => $rop->is_approval_gate,
                'gate_permission' => $rop->gate_permission,
                'is_subcontracted' => $rop->is_subcontracted,
                'planned_minutes' => $row['minutes'] ?? 0,
                'planned_labour_minutes' => $row['labour_minutes'] ?? 0,
                'status' => 'PENDING',
            ]);
        }
    }

    private function unitCost(?FinStockItem $item): float
    {
        if (! $item) {
            return 0.0;
        }

        /*
         * Compared as numbers. avg_cost is cast decimal:4, so an item never received reads back as
         * the STRING "0.0000" -- which PHP treats as true -- and `avg_cost ?: standard_cost` never
         * fell back: every material not yet in stock was priced at nothing.
         */
        $average = (float) $item->avg_cost;

        return $item->cost_method === 'standard' || $average <= 0 ? (float) $item->standard_cost : $average;
    }

    public function event(ProductionOrder $order, string $action, ?string $from, ?string $to, ?User $actor, ?string $reason = null, array $detail = [], bool $exception = false): ProductionOrderEvent
    {
        return ProductionOrderEvent::create([
            'company_id' => $order->company_id, 'order_id' => $order->id, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'is_exception' => $exception,
            'reason' => $reason, 'detail' => $detail ?: null, 'actor_id' => $actor?->id, 'acted_at' => now(),
        ]);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
