<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinSalesOrderLine;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\CustomerCommitment as Commitment;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer orders on the factory floor (MFG-017).
 *
 * The sales order is Finance's record and is only ever read here: manufacturing never changes it,
 * never delivers against it and never invoices it. What manufacturing adds is its commitment to
 * one line -- finished stock held for it by a reservation, production made for it by an order
 * whose demand is the line, and a promised date worked out from material and capacity.
 *
 * Make-to-stock lines are served from stock and replenished by MRP; make-to-order and
 * configure-to-order lines get a production order of their own for whatever stock cannot cover.
 */
class CustomerOrders
{
    /** A sales order still waiting for goods. */
    public const OPEN_SALES = ['open', 'part_delivered'];

    private const OPEN_PRODUCTION = [PO::DRAFT, PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::ON_HOLD];

    public function __construct(
        private readonly StockLedger $stock,
        private readonly ProductionOrders $orders,
        private readonly BomResolver $boms,
        private readonly BomExploder $exploder,
        private readonly RoutingResolver $routings,
        private readonly RoutingCalculator $calculator,
    ) {}

    // ── What can be made for a customer ─────────────────────────────────────────────────────

    /** Open sales order lines for products this factory makes, with what already covers them. */
    public function eligible(): Collection
    {
        $lines = DB::table('fin_sales_order_lines as l')->join('fin_sales_orders as o', 'o.id', '=', 'l.order_id')
            ->leftJoin('fin_parties as p', 'p.id', '=', 'o.party_id')
            ->whereIn('o.status', self::OPEN_SALES)->whereNotNull('l.item_id')
            ->whereRaw('l.quantity - l.qty_delivered > 0')
            ->orderBy('o.order_date')->orderBy('o.id')->orderBy('l.id')
            ->get(['l.id', 'l.item_id', 'l.description', 'l.quantity', 'l.qty_delivered', 'o.id as order_id', 'o.uuid as order_uuid',
                'o.number', 'o.order_date', 'o.customer_ref', 'o.company_id', 'p.name as customer']);

        $inventory = app(InventoryService::class);
        $committed = Commitment::query()->whereIn('sales_order_line_id', $lines->pluck('id'))->get()->keyBy('sales_order_line_id');

        return $lines->map(function ($l) use ($inventory, $committed) {
            $stock = $inventory->stockItemForSaleItem((int) $l->item_id, (int) $l->company_id);
            $item = $stock ? Item::query()->where('stock_item_id', $stock->id)->first() : null;
            if (! $item || ! $item->isManufactured() || ! $item->is_active) {
                return null;
            }
            $c = $committed->get($l->id);

            return [
                'line_id' => $l->id, 'sales_order' => $l->number, 'sales_order_id' => $l->order_uuid, 'customer' => $l->customer,
                'customer_ref' => $l->customer_ref, 'order_date' => $l->order_date, 'description' => $l->description,
                'item' => $stock->code, 'item_name' => $stock->name, 'strategy' => $item->production_strategy ?? 'MTS',
                'ordered' => (float) $l->quantity, 'delivered' => (float) $l->qty_delivered,
                'outstanding' => round((float) $l->quantity - (float) $l->qty_delivered, 4),
                'free_stock' => round($this->stock->available($stock), 4),
                'commitment' => $c?->uuid, 'committed_status' => $c?->status,
            ];
        })->filter()->values();
    }

    /**
     * How long it takes to have $qty ready: material that is short arrives after its lead time,
     * then the routing runs on the factory's calendar (including any days at a subcontractor).
     *
     * @return array{days: int, material_days: int, production_days: int, finish: string, shortages: list<array<string, mixed>>, warnings: list<string>}
     */
    public function leadTime(Item $item, float $qty, ?Factory $factory = null, ?Carbon $from = null): array
    {
        $from ??= today();
        $warnings = [];
        $shortages = [];
        $materialDays = 0;

        if ($qty > 0 && ($bom = $this->boms->applicable($item->id, $from))) {
            foreach ($this->exploder->explode($bom, $qty, true, $from)['requirements'] as $r) {
                $stock = FinStockItem::query()->find($r['stock_item_id']);
                if (! $stock) {
                    continue;
                }
                $free = $this->stock->available($stock);
                if ($free + 0.0001 >= (float) $r['quantity']) {
                    continue;
                }
                $lead = $this->purchaseLead($stock->id);
                if ($lead === null) {
                    $warnings[] = "{$stock->code} is short and has no lead time set; it is counted as available today.";
                }
                $materialDays = max($materialDays, (int) ($lead ?? 0));
                $shortages[] = ['item' => $stock->code, 'needed' => round((float) $r['quantity'], 4), 'available' => round($free, 4),
                    'short' => round((float) $r['quantity'] - $free, 4), 'lead_days' => $lead];
            }
        }

        $start = $from->copy()->addDays($materialDays);
        $finish = $start->copy();
        if ($qty > 0 && ($routing = $this->routings->applicable($item->id, $start))) {
            $estimate = $this->calculator->estimate($routing, $qty, $factory, $start);
            $warnings = [...$warnings, ...$estimate['warnings']];
            $finish = $estimate['estimated_finish']
                ? Carbon::parse($estimate['estimated_finish'])->startOfDay()
                // No factory calendar to place it on: eight-hour days, plus days at the supplier.
                : $start->copy()->addDays((int) ceil($estimate['lead_minutes'] / 480) + (int) $estimate['subcontract_days']);
        }

        return [
            'days' => (int) $from->diffInDays($finish->max($from)), 'material_days' => $materialDays,
            'production_days' => (int) $start->diffInDays($finish->max($start)), 'finish' => $finish->max($from)->toDateString(),
            'shortages' => $shortages, 'warnings' => array_values(array_unique($warnings)),
        ];
    }

    // ── Committing ──────────────────────────────────────────────────────────────────────────

    /**
     * Commit to a sales order line: hold what finished stock there is, and for a made-to-order or
     * configured product raise a draft production order for the rest. Doing it again tops up --
     * it never makes a second order for quantity already in production.
     *
     * @param  array{required_date?: ?string, factory: Factory, warehouse: FinWarehouse, configuration?: ?string, make?: bool}  $data
     */
    public function commit(FinSalesOrderLine $line, array $data, ?User $actor): Commitment
    {
        $order = FinSalesOrder::query()->findOrFail($line->order_id);
        if (! in_array($order->status, self::OPEN_SALES, true)) {
            throw new DomainException("Sales order {$order->number} is {$order->status}; there is nothing to make for it.");
        }
        $outstanding = $this->outstanding($line, $order);
        if ($outstanding <= 0) {
            throw new DomainException("That line of {$order->number} has been delivered in full.");
        }
        $stock = $line->item_id ? app(InventoryService::class)->stockItemForSaleItem((int) $line->item_id, $order->company_id) : null;
        $item = $stock ? Item::query()->where('stock_item_id', $stock->id)->first() : null;
        if (! $item || ! $item->isManufactured()) {
            throw new DomainException('That line is not for a product this factory makes.');
        }

        $strategy = $item->production_strategy ?? 'MTS';
        $configuration = trim((string) ($data['configuration'] ?? '')) ?: null;
        if ($strategy === 'CTO') {
            $configuration ??= trim((string) $line->description) ?: null;
            if ($configuration === null) {
                throw new DomainException("{$stock->code} is configured to order: say how this customer's one is to be configured.");
            }
        }

        return DB::transaction(function () use ($line, $order, $data, $actor, $stock, $item, $strategy, $configuration, $outstanding) {
            $commitment = Commitment::query()->firstOrNew(['sales_order_line_id' => $line->id]);
            $commitment->fill([
                'company_id' => $order->company_id, 'sales_order_id' => $order->id, 'stock_item_id' => $stock->id, 'item_id' => $item->id,
                'warehouse_id' => $data['warehouse']->id, 'factory_id' => $data['factory']->id, 'strategy' => $strategy,
                'required_date' => $data['required_date'] ?? $commitment->required_date ?? $order->order_date,
                'configuration' => $configuration, 'status' => Commitment::OPEN,
                'created_by' => $commitment->created_by ?? $actor?->id,
            ])->save();

            $reserved = $this->hold($commitment);
            $shortfall = round($outstanding - $reserved - $this->inProduction($line->id), 4);

            if ($shortfall > 0 && ($strategy !== 'MTS' || ! empty($data['make']))) {
                $lead = $this->leadTime($item, $shortfall, $data['factory']);
                $this->orders->create([
                    'item' => $item, 'quantity' => $shortfall, 'factory' => $data['factory'], 'warehouse' => $data['warehouse'],
                    'due_date' => Carbon::parse($commitment->required_date)->max(Carbon::parse($lead['finish']))->toDateString(),
                    'demand_type' => Commitment::DEMAND_TYPE, 'demand_id' => $line->id, 'demand_reference' => $order->number,
                    'notes' => $configuration ? "Configuration: {$configuration}" : null,
                ], $actor);
            }

            $toMake = max(0.0, round($outstanding - $reserved, 4));
            $lead = $this->leadTime($item, $toMake, $data['factory']);
            $commitment->update(['lead_days' => $lead['days'], 'promised_date' => $toMake > 0 ? $lead['finish'] : today()->toDateString()]);

            return $commitment->refresh();
        });
    }

    /** Stop holding stock for a line manufacturing no longer serves. Its production orders stay; a planner decides. */
    public function cancel(Commitment $commitment, ?User $actor): Commitment
    {
        return DB::transaction(function () use ($commitment) {
            $this->stock->releaseFor(Commitment::RESERVATION_SOURCE, $commitment->sales_order_line_id, 'Commitment cancelled');
            $commitment->update(['status' => Commitment::CANCELLED]);

            return $commitment;
        });
    }

    // ── Keeping step with the sales order ───────────────────────────────────────────────────

    /** Finished output of a customer's production order is held for that customer as it becomes usable. */
    public function allocateOutput(PO $order): void
    {
        if ($order->demand_type !== Commitment::DEMAND_TYPE || ! $order->demand_id) {
            return;
        }
        $commitment = Commitment::query()->where('sales_order_line_id', $order->demand_id)->where('status', Commitment::OPEN)->first();
        if ($commitment) {
            $this->hold($commitment);
        }
    }

    /**
     * After a sales order changes -- delivered, invoiced, cancelled -- hold no more than is still
     * owed. Stock leaves through Finance (or the warehouse); this only lets go of the hold.
     */
    public function reconcile(int $salesOrderId): void
    {
        $order = FinSalesOrder::withoutGlobalScopes()->find($salesOrderId);
        if (! $order) {
            return;
        }
        foreach (Commitment::withoutGlobalScopes()->where('sales_order_id', $order->id)->where('status', Commitment::OPEN)->get() as $c) {
            $line = FinSalesOrderLine::withoutGlobalScopes()->find($c->sales_order_line_id);
            $owed = $line ? $this->outstanding($line, $order) : 0.0;
            $held = $this->reserved($c->sales_order_line_id);

            if ($owed <= 0.0001) {
                $this->stock->releaseFor(Commitment::RESERVATION_SOURCE, $c->sales_order_line_id, "Sales order {$order->number} {$order->status}");
            } elseif ($held > $owed + 0.0001) {
                $r = $this->openReservation($c);
                $r && $this->stock->reserve(FinStockItem::withoutGlobalScopes()->findOrFail($c->stock_item_id),
                    FinWarehouse::withoutGlobalScopes()->findOrFail($c->warehouse_id), round($owed, 4), Commitment::RESERVATION_SOURCE, $c->sales_order_line_id);
            }
            if ($order->status === 'cancelled') {
                $c->update(['status' => Commitment::CANCELLED]);
            }
        }
    }

    /** Warehouse Management has taken the line over for picking: its allocation is now the hold. */
    public function handOver(int $salesLineId): void
    {
        $this->stock->releaseFor(Commitment::RESERVATION_SOURCE, $salesLineId, 'Handed to warehouse fulfilment');
    }

    // ── Progress ────────────────────────────────────────────────────────────────────────────

    /** Every commitment, how far production has got, and what puts the promise at risk. */
    public function progress(?string $status = null, ?int $salesLineId = null): Collection
    {
        $lifecycle = app(ProductionOrderLifecycle::class);

        return Commitment::query()->with(['salesOrder', 'salesLine', 'stockItem:id,code,name'])
            ->when($status === 'open', fn ($q) => $q->where('status', Commitment::OPEN))
            ->when($salesLineId, fn ($q) => $q->where('sales_order_line_id', $salesLineId))
            ->orderBy('required_date')->get()
            ->map(function (Commitment $c) use ($lifecycle) {
                $so = $c->salesOrder;
                $line = $c->salesLine;
                $owed = $line ? $this->outstanding($line, $so) : 0.0;
                $orders = PO::query()->where('demand_type', Commitment::DEMAND_TYPE)->where('demand_id', $c->sales_order_line_id)->orderBy('id')->get();
                $open = $orders->whereIn('status', self::OPEN_PRODUCTION);
                $ready = $this->reserved($c->sales_order_line_id);
                $projected = $open->map(fn (PO $o) => $o->sched_finish ?? $o->planned_finish ?? $o->due_date)->filter()
                    ->map(fn ($d) => Carbon::parse($d)->startOfDay())->max();

                $risks = [];
                if ($so?->status === 'cancelled' && $open->isNotEmpty()) {
                    $risks[] = 'Sales order cancelled while production for it is still open.';
                }
                if ($projected && $c->required_date && $projected->gt($c->required_date)) {
                    $risks[] = "Production finishes {$projected->toDateString()}, after the {$c->required_date->toDateString()} the customer needs.";
                }
                foreach ($open->whereIn('status', [PO::DRAFT, PO::PLANNED, PO::APPROVED, PO::RELEASED]) as $o) {
                    if ($short = $lifecycle->shortages($o)) {
                        $risks[] = "{$o->number} is short of ".collect($short)->pluck('item')->implode(', ').'.';
                    }
                }
                foreach ($open->where('status', PO::ON_HOLD) as $o) {
                    $risks[] = "{$o->number} is on hold.";
                }
                $uncovered = round($owed - $ready - $open->sum(fn (PO $o) => max(0, (float) $o->planned_qty - (float) $o->qty_completed)), 4);
                if ($c->status === Commitment::OPEN && $uncovered > 0.0001) {
                    $risks[] = "{$this->num($uncovered)} is neither in stock for this customer nor in production.";
                }

                $flag = match (true) {
                    $c->status === Commitment::CANCELLED => 'CANCELLED',
                    $owed <= 0.0001 => 'DELIVERED',
                    $ready + 0.0001 >= $owed => 'READY',
                    $c->required_date && $c->required_date->lt(today()) => 'LATE',
                    $risks !== [] => 'AT_RISK',
                    default => 'ON_TRACK',
                };

                return [
                    'id' => $c->uuid, 'sales_order' => $so?->number, 'sales_order_status' => $so?->status,
                    'customer' => $so?->party_id ? DB::table('fin_parties')->where('id', $so->party_id)->value('name') : null,
                    'customer_ref' => $so?->customer_ref, 'item' => $c->stockItem?->code, 'item_name' => $c->stockItem?->name,
                    'strategy' => $c->strategy, 'configuration' => $c->configuration,
                    'ordered' => (float) ($line?->quantity ?? 0), 'delivered' => (float) ($line?->qty_delivered ?? 0), 'outstanding' => $owed,
                    'ready' => round($ready, 4), 'in_production' => round($open->sum(fn (PO $o) => max(0, (float) $o->planned_qty - (float) $o->qty_completed)), 4),
                    'produced' => round((float) $orders->sum('qty_completed'), 4),
                    'required_date' => $c->required_date?->toDateString(), 'promised_date' => $c->promised_date?->toDateString(),
                    'projected_finish' => $projected?->toDateString(), 'flag' => $flag, 'risks' => $risks,
                    'production_orders' => $orders->map(fn (PO $o) => ['id' => $o->uuid, 'number' => $o->number, 'status' => $o->status,
                        'qty' => (float) $o->planned_qty, 'completed' => (float) $o->qty_completed])->values(),
                    'references' => $this->references($c),
                ];
            });
    }

    /**
     * Where the goods went and how they were billed -- read from Finance, never written: the
     * delivery notes for this line and the sales order's invoice.
     *
     * @return array{deliveries: list<array<string, mixed>>, invoice: ?array<string, mixed>}
     */
    public function references(Commitment $c): array
    {
        $deliveries = DB::table('fin_delivery_note_lines as l')->join('fin_delivery_notes as n', 'n.id', '=', 'l.delivery_note_id')
            ->where('l.order_line_id', $c->sales_order_line_id)->orderBy('n.delivery_date')
            ->get(['n.number', 'n.delivery_date', 'l.quantity'])
            ->map(fn ($d) => ['number' => $d->number, 'date' => $d->delivery_date, 'qty' => (float) $d->quantity])->all();
        $invoiceId = DB::table('fin_sales_orders')->where('id', $c->sales_order_id)->value('invoice_id');
        $invoice = $invoiceId ? DB::table('fin_invoices')->where('id', $invoiceId)->first(['number', 'status', 'invoice_date']) : null;

        return ['deliveries' => $deliveries, 'invoice' => $invoice ? ['number' => $invoice->number, 'status' => $invoice->status, 'date' => $invoice->invoice_date] : null];
    }

    /** What a production order is for, when it is for a customer: shown on the order. */
    public function forOrder(PO $order): ?array
    {
        if ($order->demand_type !== Commitment::DEMAND_TYPE || ! $order->demand_id) {
            return null;
        }
        return $this->progress(null, (int) $order->demand_id)->first();
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────

    /** Hold as much free finished stock as the line still needs; returns what is held. */
    private function hold(Commitment $c): float
    {
        $line = FinSalesOrderLine::withoutGlobalScopes()->findOrFail($c->sales_order_line_id);
        $owed = $this->outstanding($line, FinSalesOrder::withoutGlobalScopes()->findOrFail($c->sales_order_id));
        $held = $this->reserved($c->sales_order_line_id);
        $item = FinStockItem::withoutGlobalScopes()->findOrFail($c->stock_item_id);
        $warehouse = FinWarehouse::withoutGlobalScopes()->findOrFail($c->warehouse_id);

        $add = min(max(0.0, $owed - $held), $this->stock->available($item, $warehouse->id));
        if ($add > 0.0001) {
            $this->stock->reserve($item, $warehouse, round($held + $add, 4), Commitment::RESERVATION_SOURCE, $c->sales_order_line_id);
            $held += $add;
        }

        return round($held, 4);
    }

    private function outstanding(FinSalesOrderLine $line, ?FinSalesOrder $order): float
    {
        if (! $order || ! in_array($order->status, self::OPEN_SALES, true)) {
            return 0.0;
        }

        return max(0.0, round((float) $line->quantity - (float) $line->qty_delivered, 4));
    }

    private function reserved(int $lineId): float
    {
        return (float) FinStockReservation::withoutGlobalScopes()->where('source_type', Commitment::RESERVATION_SOURCE)
            ->where('source_id', $lineId)->where('status', FinStockReservation::OPEN)
            ->selectRaw('COALESCE(SUM(qty - qty_consumed), 0) AS r')->value('r');
    }

    private function openReservation(Commitment $c): ?FinStockReservation
    {
        return FinStockReservation::withoutGlobalScopes()->where('source_type', Commitment::RESERVATION_SOURCE)
            ->where('source_id', $c->sales_order_line_id)->where('status', FinStockReservation::OPEN)->first();
    }

    private function inProduction(int $lineId): float
    {
        return (float) PO::query()->where('demand_type', Commitment::DEMAND_TYPE)->where('demand_id', $lineId)
            ->whereIn('status', self::OPEN_PRODUCTION)->get()
            ->sum(fn (PO $o) => max(0, (float) $o->planned_qty - (float) $o->qty_completed));
    }

    private function purchaseLead(int $stockItemId): ?int
    {
        $own = PlanningParam::query()->where('stock_item_id', $stockItemId)->value('lead_time_days');
        if ($own !== null) {
            return (int) $own;
        }
        $wms = Schema::hasTable('wms_item_profiles') ? DB::table('wms_item_profiles')->where('stock_item_id', $stockItemId)->value('lead_time_days') : null;

        return $wms !== null ? (int) $wms : null;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
