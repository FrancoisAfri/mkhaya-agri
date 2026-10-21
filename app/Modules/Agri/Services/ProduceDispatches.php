<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\HarvestCommitment;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinDeliveryNote;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinSalesOrderLine;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\SalesOrderService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\CustomerReturns;
use App\Modules\Operations\WarehouseManagement\Services\Dispatching;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\Packing;
use App\Modules\Operations\WarehouseManagement\Services\Picking;
use App\Modules\Shared\Party\PartyDirectory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Phase 2 §8.5: from a customer's order to what the customer accepted — through the warehouse.
 *
 * The order is Accounting's sales order (with the farm's dimensions). A load is the warehouse's
 * fulfilment order for it: the lots the farm picks are allocated and picked onto a pallet, counted
 * onto the vehicle by somebody other than the picker (which closes the pallet under its seal), and
 * shipped — the shipment takes the stock out and writes Accounting's delivery note. On arrival the
 * proof of delivery is the shipment's; what the customer accepted goes on the delivery note and is
 * invoiced, and rejected produce that comes back is a warehouse customer return.
 *
 * This is the farm's way through those steps, in the farm's words; the warehouse's own outbound
 * screens show the same orders, picks, pallets and shipments.
 */
class ProduceDispatches
{
    public function __construct(private readonly StockLedger $stock, private readonly SalesOrderService $orders) {}

    /** The farm an order's income lands on. */
    public static function farmIdOf(FinSalesOrder $order): ?int
    {
        return isset($order->dimensions['agri_farm']) ? (int) $order->dimensions['agri_farm'] : null;
    }

    /** The farm's open orders (Accounting sales orders carrying the farm's dimension). */
    public static function ordersFor(Farm $farm)
    {
        return FinSalesOrder::query()->whereRaw("dimensions->>'agri_farm' = ?", [(string) $farm->id]);
    }

    /** The warehouse loads (fulfilment orders) for a farm's sales orders. */
    public static function loadsFor(Farm $farm)
    {
        return FulfilmentOrder::query()->where('source', 'SALES_ORDER')->whereIn('source_id', self::ordersFor($farm)->select('id'));
    }

    /** What has left on loads for each line: [order_line_id => quantity]. */
    public function dispatchedQty(FinSalesOrder $order): array
    {
        return $order->lines()->get()->mapWithKeys(fn ($l) => [$l->id => round((float) $l->qty_delivered, 3)])->filter()->all();
    }

    /**
     * An Accounting sales order for the farm's produce. The customer is named (party_id) or, for a
     * walk-in buyer, created by name.
     *
     * @param list<array{product: string, grade?: ?string, quantity: float, unit: string, price?: float, basis?: ?string}> $lines
     */
    public function order(Farm $farm, array $data, array $lines, User $actor): FinSalesOrder
    {
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'An order needs at least one line.']);
        }
        $party = ! empty($data['party_id']) ? FinParty::query()->findOrFail($data['party_id'])
            : (FinParty::query()->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['buyer'] ?? ''))])->whereIn('kind', ['customer', 'both'])->first()
                ?? app(PartyDirectory::class)->create(['company_id' => $farm->company_id, 'kind' => 'customer', 'name' => trim($data['buyer'] ?? '') ?: 'Walk-in buyer',
                    'category' => 'produce_buyer', 'credit_terms_days' => 0], 'agri'));
        $account = FarmSales::salesAccountId();

        try {
            return $this->orders->save(['party_id' => $party->id, 'deliver_on' => $data['deliver_on'], 'delivery_address' => $data['delivery_address'] ?? null,
                'customer_ref' => $data['customer_ref'] ?? null, 'description' => $data['description'] ?? "{$farm->name} produce",
                'dimensions' => AgriLedger::dimensions($farm, null, null, 'produce'),
                'source_type' => $data['source_type'] ?? (! empty($data['commitment_id']) ? 'agri_commitment' : null),
                'source_id' => $data['source_id'] ?? ($data['commitment_id'] ?? null),
                'lines' => collect($lines)->map(fn ($l) => ['account_id' => $account, 'description' => $l['product'], 'grade' => $l['grade'] ?? null, 'unit' => $l['unit'],
                    'quantity' => (float) $l['quantity'], 'unit_price' => (float) ($l['price'] ?? 0), 'price_basis' => $l['basis'] ?? null,
                    'tax_rate' => (float) config('agri.produce_vat_rate', 0)])->all()]);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
    }

    /** Lots in a store that could fill each line: same product and grade, usable, first to expire (then oldest) first. */
    public function pickList(FinSalesOrder $order): array
    {
        $taken = [];
        $sent = $this->dispatchedQty($order);
        $onLoads = $this->onOpenLoads($order);
        $farmId = self::farmIdOf($order);

        return $order->lines()->get()->map(function (FinSalesOrderLine $l) use ($farmId, $sent, $onLoads, &$taken) {
            $need = max(0, (float) $l->quantity - ($sent[$l->id] ?? 0) - ($onLoads[$l->id] ?? 0));
            $lots = ProduceLot::withoutGlobalScopes()->where('farm_id', $farmId)->where('status', 'available')->where('remaining', '>', 0)->whereNotNull('stock_lot_id')
                ->whereRaw('lower(product) = ?', [mb_strtolower($l->description)])->where('unit', $l->unit)
                ->when($l->grade, fn ($q) => $q->where('grade', $l->grade))
                ->orderByRaw('best_before IS NULL')->orderBy('best_before')->orderBy('produced_on')->orderBy('id')->get();
            $picks = [];
            foreach ($lots as $lot) {
                if ($need <= 0.0005) {
                    break;
                }
                $free = $this->free($lot) - ($taken[$lot->id] ?? 0);
                $q = min($free, $need);
                if ($q > 0) {
                    $picks[] = ['lot_id' => $lot->id, 'lot' => $lot->code, 'location' => $lot->location, 'best_before' => $lot->best_before?->toDateString(), 'quantity' => round($q, 3)];
                    $taken[$lot->id] = ($taken[$lot->id] ?? 0) + $q;
                    $need -= $q;
                }
            }

            return ['line_id' => $l->id, 'product' => $l->description, 'grade' => $l->grade, 'unit' => $l->unit, 'ordered' => (float) $l->quantity,
                'outstanding' => max(0, (float) $l->quantity - ($sent[$l->id] ?? 0)), 'on_loads' => $onLoads[$l->id] ?? 0, 'picks' => $picks, 'short' => round(max(0, $need), 3)];
        })->all();
    }

    /**
     * Pick lots onto a load: a warehouse fulfilment order for the sales order, its lines allocated
     * to the lots chosen, picked by the actor and put on a pallet.
     *
     * @param list<array{order_line_id: int, lot_id: int, quantity: float}> $items
     */
    public function pick(FinSalesOrder $order, array $items, array $data, User $actor): FulfilmentOrder
    {
        if (! in_array($order->status, ['open', 'part_delivered'], true)) {
            throw ValidationException::withMessages(['order' => "Order {$order->number} is {$order->status}."]);
        }
        $farmId = self::farmIdOf($order) ?? throw ValidationException::withMessages(['order' => "Order {$order->number} is not a farm order."]);
        if (! $items) {
            throw ValidationException::withMessages(['items' => 'Pick at least one lot.']);
        }
        $lines = $order->lines()->get()->keyBy('id');
        $lots = [];
        foreach (collect($items)->groupBy('lot_id') as $lotId => $rows) {
            $lot = ProduceLot::withoutGlobalScopes()->where('farm_id', $farmId)->findOrFail($lotId);
            if ($lot->status !== 'available') {
                throw ValidationException::withMessages(['items' => "Lot {$lot->code} is {$lot->status}."]);
            }
            if (! $lot->stock_lot_id || ! $lot->isStocked()) {
                throw ValidationException::withMessages(['items' => "Lot {$lot->code} is not in a store; only produce in a store goes out through the warehouse."]);
            }
            if ($rows->sum('quantity') > $this->free($lot) + 0.0005) {
                throw ValidationException::withMessages(['items' => "Lot {$lot->code} has ".round($this->free($lot), 3)." {$lot->unit} not already on a load."]);
            }
            foreach ($rows as $r) {
                $line = $lines[$r['order_line_id']] ?? throw ValidationException::withMessages(['items' => 'That line is not on this order.']);
                if (mb_strtolower($line->description) !== mb_strtolower($lot->product) || $line->unit !== $lot->unit || ($line->grade && $line->grade !== $lot->grade)) {
                    throw ValidationException::withMessages(['items' => "Lot {$lot->code} ({$lot->product} {$lot->grade}) does not match the line ({$line->description} {$line->grade})."]);
                }
            }
            $lots[$lotId] = $lot;
        }
        if (collect($lots)->pluck('warehouse_id')->unique()->count() > 1) {
            throw ValidationException::withMessages(['items' => 'A load leaves from one store: pick each store\'s lots as a load of its own.']);
        }
        $warehouse = FinWarehouse::withoutGlobalScopes()->findOrFail(collect($lots)->first()->warehouse_id);
        $party = $order->party_id ? FinParty::query()->find($order->party_id) : null;

        return $this->wms(function () use ($order, $items, $data, $actor, $lots, $warehouse, $party) {
            $n = FulfilmentOrder::query()->where('source', 'SALES_ORDER')->where('source_id', $order->id)->count() + 1;
            [$load] = app(FulfilmentOrders::class)->create($warehouse, 'SALES_ORDER', "{$order->number}/L{$n}",
                collect($items)->map(fn ($i) => ['item_id' => $lots[$i['lot_id']]->stock_item_id, 'qty' => (float) $i['quantity'],
                    'lot_id' => $lots[$i['lot_id']]->stock_lot_id, 'source_line_id' => $i['order_line_id']])->all(),
                ['source_id' => $order->id, 'customer_party_id' => $party?->id, 'customer_name' => $party?->name, 'required_by' => $order->deliver_on,
                    'ship_to' => array_filter(['name' => $party?->name, 'address' => $order->delivery_address ?? $party?->address]),
                    'carrier' => $data['vehicle'] ?? null, 'notes' => ! empty($data['driver']) ? "Driver: {$data['driver']}" : null], $actor);
            app(FulfilmentOrders::class)->release($load);
            foreach ($load->lines()->get() as $line) {
                app(Allocator::class)->override($line, null, $line->lot_id, (float) $line->qty, 'Picked by the farm from its lot', $actor);
            }
            app(Picking::class)->release($warehouse, collect([$load->fresh()]), 'DISCRETE', $actor);
            foreach (PickTask::query()->where('order_id', $load->id)->where('status', 'OPEN')->get() as $task) {
                app(Picking::class)->confirm($task, (float) $task->qty, $actor);
            }
            $load = $load->fresh();
            $pallet = app(Packing::class)->open($load, 'PALLET', $actor);
            foreach ($load->lines()->get() as $line) {
                app(Packing::class)->add($pallet, $line, (float) $line->qty, $line->lot_id);
            }

            return $load->fresh();
        });
    }

    /**
     * Counted onto the vehicle by someone other than the picker. A count that differs from the
     * pick is refused until the pick is corrected; the pallet closes under the seal.
     *
     * @param  array<int, float>  $counts  pallet content id => quantity counted
     */
    public function verifyLoad(FulfilmentOrder $load, array $counts, ?string $seal, User $actor): FulfilmentOrder
    {
        $pallets = $load->packages()->where('status', 'OPEN')->with('contents')->get();
        if ($pallets->isEmpty()) {
            throw ValidationException::withMessages(['dispatch' => "Load {$load->number} has no open pallet to count."]);
        }
        $pickers = PickTask::query()->where('order_id', $load->id)->pluck('picked_by')->filter()->unique();
        if ($pickers->contains($actor->id)) {
            throw ValidationException::withMessages(['dispatch' => 'Someone other than the picker counts the load.']);
        }
        $contents = $pallets->flatMap->contents;
        $wrong = $contents->filter(fn ($c) => ! array_key_exists($c->id, $counts) || abs((float) $counts[$c->id] - (float) $c->qty) > 0.0005);
        if ($wrong->isNotEmpty()) {
            $codes = ProduceLot::withoutGlobalScopes()->whereIn('stock_lot_id', $wrong->pluck('lot_id'))->pluck('code')->implode(', ');
            throw ValidationException::withMessages(['counts' => "The count does not match the pick for {$codes}. Correct the pick or recount."]);
        }

        return $this->wms(function () use ($load, $pallets, $seal) {
            foreach ($pallets as $p) {
                app(Packing::class)->close($p, array_filter(['tracking_number' => $seal]));
            }

            return $load->fresh();
        });
    }

    /** The load leaves: the warehouse ships it, which takes the stock out and writes the delivery note. */
    public function dispatch(FulfilmentOrder $load, User $actor): Shipment
    {
        $pallets = $load->packages()->where('status', 'CLOSED')->get();
        if ($pallets->isEmpty()) {
            throw ValidationException::withMessages(['dispatch' => 'Only a counted load is dispatched.']);
        }
        $driver = preg_match('/Driver: (.+)$/m', (string) $load->notes, $m) ? trim($m[1]) : null;

        return $this->wms(fn () => app(Dispatching::class)->dispatch($load, $pallets, [
            'vehicle_reg' => $load->carrier, 'driver_name' => $driver, 'handover_name' => $driver ?: ($load->carrier ?: 'Farm transport'),
            'tracking_reference' => $pallets->pluck('tracking_number')->filter()->implode(', ') ?: null,
        ], $actor));
    }

    /**
     * What the customer did with it, per pallet line: accepted, rejected (and whether the rejected
     * produce came back, was written off or kept by the buyer), or short. The proof of delivery is
     * the shipment's; the accepted quantity at the line's price is invoiced.
     *
     * @param  array<int, array{accepted: float, rejected?: float, rejection_reason?: ?string, rejected_to?: ?string}>  $results  pallet content id => outcome
     */
    public function deliver(Shipment $shipment, array $results, array $data, User $actor, ?UploadedFile $pod = null): Shipment
    {
        $contents = $shipment->packages()->with('contents')->get()->flatMap->contents;
        foreach ($contents as $c) {
            $r = $results[$c->id] ?? null;
            if (! $r) {
                throw ValidationException::withMessages(['results' => 'Record what happened to every lot on the load.']);
            }
            $acc = (float) $r['accepted'];
            $rej = (float) ($r['rejected'] ?? 0);
            if ($acc < 0 || $rej < 0 || $acc + $rej > (float) $c->qty + 0.0005) {
                throw ValidationException::withMessages(['results' => 'Accepted and rejected cannot add up to more than was sent.']);
            }
            if ($rej > 0 && (blank($r['rejection_reason'] ?? null) || ! in_array($r['rejected_to'] ?? null, ['returned', 'written_off', 'buyer_kept'], true))) {
                throw ValidationException::withMessages(['results' => 'Say why produce was rejected and whether it came back, was written off or the buyer kept it.']);
            }
        }
        $load = $shipment->order;
        $order = FinSalesOrder::query()->with('lines')->findOrFail($load->source_id);
        $lineOf = $load->lines()->pluck('source_line_id', 'id');

        return $this->wms(function () use ($shipment, $contents, $results, $data, $actor, $pod, $load, $order, $lineOf) {
            app(Dispatching::class)->recordDelivery($shipment, $data['received_by'] ?? ($load->customer_name ?: 'Customer'), $data['delivered_at'] ?? null, $pod, $data['note'] ?? null);

            $outcomes = [];
            $returns = [];
            foreach ($contents as $c) {
                $r = $results[$c->id];
                $soLine = $lineOf[$c->order_line_id] ?? null;
                $outcomes[$soLine]['accepted'] = ($outcomes[$soLine]['accepted'] ?? 0) + (float) $r['accepted'];
                $outcomes[$soLine]['rejected'] = ($outcomes[$soLine]['rejected'] ?? 0) + (float) ($r['rejected'] ?? 0);
                if ((float) ($r['rejected'] ?? 0) > 0 && $r['rejected_to'] === 'returned') {
                    $returns[] = ['item_id' => $c->item_id, 'qty' => (float) $r['rejected'], 'lot_id' => $c->lot_id, 'order_line_id' => $c->order_line_id,
                        'reason_code' => 'DEFECTIVE', 'note' => $r['rejection_reason']];
                }
            }

            // Rejected produce that came back is a customer return: into the warehouse's returns bin, held for inspection.
            if ($returns) {
                $return = app(CustomerReturns::class)->authorise(['warehouse_id' => $load->warehouse_id, 'order_id' => $load->id,
                    'reason' => collect($returns)->pluck('note')->unique()->implode('; '), 'lines' => $returns], $actor);
                $return->load('lines');
                app(CustomerReturns::class)->receive($return, $return->lines->mapWithKeys(fn ($l) => [$l->id => ['qty' => (float) $l->qty_authorised,
                    'condition' => 'DAMAGED', 'lot_id' => $l->lot_id]])->all(), $actor);
            }

            // §9.1: the delivery note the shipment wrote records what the customer accepted, and that is invoiced.
            $dn = FinDeliveryNote::query()->findOrFail($shipment->delivery_note_id);
            $this->orders->recordOutcomes($dn, array_filter($outcomes, fn ($k) => $k !== null && $k !== '', ARRAY_FILTER_USE_KEY));
            $this->orders->invoiceDelivery($dn->fresh(), ['invoice_date' => $data['delivered_on'] ?? today()->toDateString(),
                'description' => "{$order->number} / {$shipment->number}", 'relieve_stock' => false]);

            // A commitment the order fills is delivered too.
            if ($order->source_type === 'agri_commitment' && ($c = HarvestCommitment::withoutGlobalScopes()->find($order->source_id))) {
                $c->update(['delivered_quantity' => (float) $c->delivered_quantity + $contents->sum(fn ($x) => (float) $results[$x->id]['accepted'])]);
            }

            return $shipment->fresh();
        });
    }

    /** The farm's loads as the packhouse lists them. */
    public function loads(Farm $farm): Collection
    {
        return self::loadsFor($farm)->with(['packages.contents', 'shipments', 'lines'])->orderByDesc('id')->limit(100)->get()->map(function (FulfilmentOrder $o) {
            $lotCodes = ProduceLot::withoutGlobalScopes()->whereIn('stock_lot_id', $o->packages->flatMap->contents->pluck('lot_id'))->pluck('code', 'stock_lot_id');
            $shipment = $o->shipments->sortByDesc('id')->first();
            $soLines = FinSalesOrderLine::query()->whereIn('id', $o->lines->pluck('source_line_id'))->get()->keyBy('id');
            $lineOf = $o->lines->keyBy('id');

            return ['id' => $o->uuid, 'reference' => $o->number, 'order' => FinSalesOrder::query()->whereKey($o->source_id)->value('number'),
                'buyer' => $o->customer_name, 'status' => match (true) {
                    $o->status === 'DELIVERED' => 'delivered',
                    in_array($o->status, ['DISPATCHED', 'PARTIALLY_DISPATCHED'], true) => 'dispatched',
                    $o->packages->isNotEmpty() && $o->packages->every(fn ($p) => $p->status !== 'OPEN') => 'loaded',
                    $o->status === 'CANCELLED' => 'cancelled',
                    default => 'picking',
                }, 'vehicle' => $o->carrier, 'seal_no' => $o->packages->pluck('tracking_number')->filter()->implode(', ') ?: null,
                'shipment' => $shipment ? ['id' => $shipment->uuid, 'number' => $shipment->number, 'status' => strtolower($shipment->status),
                    'dispatched_at' => $shipment->dispatched_at?->format('Y-m-d H:i'), 'delivered_at' => $shipment->delivered_at?->format('Y-m-d H:i'),
                    'received_by' => $shipment->pod_received_by, 'has_pod' => (bool) $shipment->pod_path] : null,
                'items' => $o->packages->flatMap->contents->map(function ($c) use ($lotCodes, $soLines, $lineOf) {
                    $sl = $soLines[$lineOf[$c->order_line_id]?->source_line_id] ?? null;

                    return ['id' => $c->id, 'lot' => $lotCodes[$c->lot_id] ?? null, 'product' => $sl?->description, 'grade' => $sl?->grade, 'unit' => $sl?->unit, 'quantity' => (float) $c->qty];
                })->values(),
                'warehouse_url' => "/warehouse/outbound/orders/{$o->uuid}"];
        });
    }

    /** Shortages and rejections across deliveries, for following up with the customer and the packhouse. */
    public function exceptions(Farm $farm, ?string $from = null): array
    {
        $orders = self::ordersFor($farm)->pluck('number', 'id');

        return DB::table('fin_delivery_note_lines as l')->join('fin_delivery_notes as d', 'd.id', '=', 'l.delivery_note_id')
            ->leftJoin('fin_parties as p', 'p.id', '=', 'd.party_id')->whereIn('d.order_id', $orders->keys())
            ->when($from, fn ($q) => $q->where('d.delivery_date', '>=', $from))
            ->where(fn ($q) => $q->where('l.qty_rejected', '>', 0)->orWhereRaw('l.quantity - COALESCE(l.qty_accepted, l.quantity) - COALESCE(l.qty_rejected, 0) > 0.0005'))
            ->orderByDesc('l.id')->limit(200)
            ->get(['d.number', 'd.order_id', 'd.delivery_date', 'p.name as buyer', 'l.description', 'l.quantity', 'l.qty_accepted', 'l.qty_rejected'])
            ->map(fn ($r) => ['dispatch' => $r->number, 'order' => $orders[$r->order_id] ?? null, 'buyer' => $r->buyer, 'lot' => $r->description,
                'sent' => (float) $r->quantity, 'accepted' => (float) $r->qty_accepted, 'rejected' => (float) $r->qty_rejected,
                'short' => round(max(0, (float) $r->quantity - (float) $r->qty_accepted - (float) $r->qty_rejected), 3),
                'delivered_at' => substr((string) $r->delivery_date, 0, 10)])->all();
    }

    /** What of a lot is free to pick: usable and not held for another load. */
    private function free(ProduceLot $lot): float
    {
        return $lot->stock_lot_id && $lot->isStocked()
            ? $this->stock->available(\App\Modules\Finance\Accounting\Models\FinStockItem::withoutGlobalScopes()->findOrFail($lot->stock_item_id), (int) $lot->warehouse_id, (int) $lot->stock_lot_id)
            : 0.0;
    }

    /** @return array<int, float> sales order line id => quantity on loads not yet shipped */
    private function onOpenLoads(FinSalesOrder $order): array
    {
        return DB::table('wms_order_lines as l')->join('wms_orders as o', 'o.id', '=', 'l.order_id')->where('o.source', 'SALES_ORDER')->where('o.source_id', $order->id)
            ->whereNotIn('o.status', ['DISPATCHED', 'DELIVERED', 'CANCELLED'])->groupBy('l.source_line_id')
            ->selectRaw('l.source_line_id, SUM(l.qty - l.qty_dispatched - l.qty_cancelled) as q')->pluck('q', 'source_line_id')->map(fn ($q) => (float) $q)->all();
    }

    /** Run warehouse steps, turning their refusals into the farm form's errors. */
    private function wms(callable $steps): mixed
    {
        try {
            return DB::transaction($steps);
        } catch (RuntimeException|PostingException|\DomainException $e) {
            throw ValidationException::withMessages(['dispatch' => $e->getMessage()]);
        }
    }
}
