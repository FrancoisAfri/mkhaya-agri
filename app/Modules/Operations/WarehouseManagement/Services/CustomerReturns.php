<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturn;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturnLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customer returns (WMS-080): authorised against the order, invoice or a reference, received into
 * the returns area, inspected where policy says so, and sent wherever the decision says --
 * back to stock, to a supplier, for repair or for disposal.
 *
 * The stock side posts once, when the goods arrive: Dr Inventory / Cr Cost of sales at the cost
 * they left at. The money side is Finance's credit note, raised from here at most once per return.
 * Neither touches the other's accounts, so a return can never be credited twice in the books.
 */
class CustomerReturns
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly QualityControl $quality,
        private readonly PutawayTasks $putaway,
        private readonly ArApService $arap,
    ) {}

    /**
     * @param array{warehouse_id:int, order_id?:?int, invoice_id?:?int, reference?:?string, customer_name?:?string, reason?:?string,
     *              lines: list<array{item_id:int, qty:float, order_line_id?:?int, lot_id?:?int, serials?:?array, reason_code?:?string}>} $data
     */
    public function authorise(array $data, User $by): CustomerReturn
    {
        $warehouse = FinWarehouse::query()->findOrFail($data['warehouse_id']);
        $order = ! empty($data['order_id']) ? FulfilmentOrder::query()->with('lines')->findOrFail($data['order_id']) : null;
        $invoice = ! empty($data['invoice_id']) ? FinInvoice::query()->where('direction', 'receivable')->findOrFail($data['invoice_id']) : null;
        if (! $order && ! $invoice && blank($data['reference'] ?? null)) {
            throw new RuntimeException('A return is authorised against an order, an invoice or a reference.');
        }
        if (empty($data['lines'])) {
            throw new RuntimeException('Say what is coming back.');
        }

        foreach ($data['lines'] as $l) {
            if ((float) $l['qty'] <= 0) {
                throw new RuntimeException('Each returned quantity must be more than zero.');
            }
            // Against an order, nobody returns more than was sent.
            if ($order) {
                $sent = (float) $order->lines->where('item_id', $l['item_id'])->sum('qty_dispatched');
                $already = (float) CustomerReturnLine::query()->where('item_id', $l['item_id'])
                    ->whereHas('customerReturn', fn ($q) => $q->where('order_id', $order->id)->where('status', '!=', CustomerReturn::CANCELLED))
                    ->sum('qty_authorised');
                $asked = (float) collect($data['lines'])->where('item_id', $l['item_id'])->sum('qty');
                if ($asked + $already > $sent + 0.0001) {
                    $code = FinStockItem::query()->whereKey($l['item_id'])->value('code');
                    throw new RuntimeException("Order {$order->number} dispatched {$this->num($sent)} of {$code}; {$this->num($already)} is already being returned.");
                }
            }
        }

        return DB::transaction(function () use ($warehouse, $order, $invoice, $data, $by) {
            $return = CustomerReturn::create([
                'company_id' => $warehouse->company_id, 'number' => $this->nextNumber((int) $warehouse->company_id), 'warehouse_id' => $warehouse->id,
                'order_id' => $order?->id, 'invoice_id' => $invoice?->id, 'reference' => $data['reference'] ?? null,
                'customer_party_id' => $order?->customer_party_id ?? $invoice?->party_id,
                'customer_name' => $data['customer_name'] ?? $order?->customer_name ?? $invoice?->party?->name,
                'status' => CustomerReturn::AUTHORISED, 'reason' => $data['reason'] ?? null, 'created_by' => $by->id,
            ]);
            foreach ($data['lines'] as $l) {
                CustomerReturnLine::create([
                    'company_id' => $return->company_id, 'return_id' => $return->id, 'item_id' => $l['item_id'],
                    'order_line_id' => $l['order_line_id'] ?? null, 'lot_id' => $l['lot_id'] ?? null, 'serials' => $l['serials'] ?? null,
                    'qty_authorised' => $l['qty'], 'reason_code' => $l['reason_code'] ?? null,
                ]);
            }

            return $return;
        });
    }

    /**
     * The goods arrive. Each line is posted once, at the cost it left at, into the returns bin --
     * held in quarantine and put to inspection unless the warehouse lets unopened goods straight
     * back into stock.
     *
     * @param array<int, array{qty:float, condition:string, lot_id?:?int, serials?:?array, bin_id?:?int, notes?:?string}> $received keyed by line id
     */
    public function receive(CustomerReturn $return, array $received, User $by): CustomerReturn
    {
        if ($return->status !== CustomerReturn::AUTHORISED) {
            throw new RuntimeException("{$return->number} has already been received.");
        }

        return DB::transaction(function () use ($return, $received, $by) {
            $warehouse = $return->warehouse;
            $inspecting = false;
            foreach ($return->lines()->with('item')->get() as $line) {
                $r = $received[$line->id] ?? null;
                $qty = round((float) ($r['qty'] ?? 0), 4);
                if ($qty <= 0) {
                    $line->update(['qty_received' => 0, 'outcome' => 'REJECTED', 'notes' => $r['notes'] ?? 'Not received']);

                    continue;
                }
                if ($qty > (float) $line->qty_authorised + 0.0001) {
                    throw new RuntimeException("More {$line->item->code} arrived than was authorised; authorise the extra before receiving it.");
                }
                $condition = $r['condition'] ?? 'OPENED';
                if (! in_array($condition, CustomerReturnLine::CONDITIONS, true)) {
                    throw new RuntimeException("Unknown condition {$condition}.");
                }
                $lotId = $r['lot_id'] ?? $line->lot_id;
                if ($lotId && ! FinStockLot::query()->where('item_id', $line->item_id)->whereKey($lotId)->exists()) {
                    throw new RuntimeException("That lot is not a lot of {$line->item->code}.");
                }
                $bin = ! empty($r['bin_id'])
                    ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->findOrFail($r['bin_id'])
                    : $this->returnsBin($warehouse);
                $inspect = $this->needsInspection($warehouse, $line->item, $condition);
                $status = $inspect ? FinStockPosition::QUARANTINE : FinStockPosition::AVAILABLE;
                $cost = $this->costOut($return, $line, $lotId);

                $accounting = app(WarehouseAccounting::class);
                $accounting->assertAllowed((int) $return->company_id, 'CUSTOMER_RETURN');
                $movement = $this->ledger->receiveReturn($line->item, $qty, $cost, $warehouse, [
                    'contra' => $accounting->code('cogs'),
                    'bin_id' => $bin?->id, 'lot_id' => $lotId, 'status' => $status, 'reference' => $return->number,
                    'source_type' => 'WMS_RETURN', 'source_id' => $return->id, 'reason_code' => $line->reason_code,
                    'idempotency_key' => "wms-return-line-{$line->id}",
                ]);
                $line->update(['qty_received' => $qty, 'condition' => $condition, 'lot_id' => $lotId, 'serials' => $r['serials'] ?? $line->serials,
                    'bin_id' => $bin?->id, 'unit_cost' => $cost, 'movement_id' => $movement->id, 'notes' => $r['notes'] ?? null]);

                if ($inspect) {
                    $inspection = $this->quality->openInWarehouse([
                        'stage' => InspectionPlan::RETURN, 'stock_item' => $line->item, 'warehouse' => $warehouse, 'qty' => $qty,
                        'lot_id' => $lotId, 'bin_id' => $bin?->id, 'source_type' => 'WMS_RETURN', 'source_id' => $line->id,
                        'reference' => $return->number, 'notes' => "Returned {$condition}".($line->reason_code ? ", {$line->reason_code}" : ''),
                    ], $by);
                    $line->update(['inspection_id' => $inspection->id, 'outcome' => 'QUARANTINED']);
                    $inspecting = true;
                } else {
                    $line->update(['outcome' => 'RESTOCKED']);
                    $this->putAway($warehouse, $line->fresh(), $bin, FinStockPosition::AVAILABLE);
                }
            }

            $return->update(['status' => $inspecting ? CustomerReturn::INSPECTING : CustomerReturn::COMPLETED,
                'received_by' => $by->id, 'received_at' => now(), 'completed_at' => $inspecting ? null : now()]);

            return $return->fresh();
        });
    }

    /**
     * The inspection decided: act on the stock in its bin. Called when a returns inspection's
     * disposition is recorded (the inspection itself moves nothing -- see openInWarehouse).
     */
    public function applyDecision(CustomerReturnLine $line, string $decision, ?string $reason): void
    {
        $return = $line->customerReturn;
        $item = $line->item;
        $warehouse = $return->warehouse;
        $qty = (float) $line->qty_received;
        $opts = ['bin_id' => $line->bin_id, 'lot_id' => $line->lot_id, 'reason' => trim("{$return->number} {$decision}".($reason ? ": {$reason}" : '')),
            'reason_code' => 'RETURN', 'source_type' => 'WMS_RETURN', 'source_id' => $return->id, 'idempotency_key' => "wms-return-line-{$line->id}-{$decision}"];

        $outcome = match ($decision) {
            'ACCEPT', 'CONDITIONAL_ACCEPT' => 'RESTOCKED',
            'REJECT' => 'REJECTED',
            'RETURN_TO_SUPPLIER' => 'SUPPLIER_RETURN',
            'SCRAP' => 'DISPOSED',
            default => 'REPAIR',
        };
        match ($outcome) {
            'RESTOCKED' => $this->ledger->changeStatus($item, $warehouse, $qty, FinStockPosition::QUARANTINE, FinStockPosition::AVAILABLE, $opts),
            'REJECTED', 'SUPPLIER_RETURN' => $this->ledger->changeStatus($item, $warehouse, $qty, FinStockPosition::QUARANTINE, FinStockPosition::REJECTED, $opts),
            'DISPOSED' => $this->ledger->scrap($item, $qty, $warehouse, $opts + ['status' => FinStockPosition::QUARANTINE, 'reference' => $return->number]),
            default => null, // repair: it stays held until it is inspected again
        };
        $line->update(['outcome' => $outcome]);
        if ($outcome === 'RESTOCKED') {
            $this->putAway($warehouse, $line, $line->bin, FinStockPosition::AVAILABLE);
        }
        if ($outcome === 'REPAIR') {
            // Repaired goods come back to the same question: open the next inspection now.
            $next = $this->quality->openInWarehouse([
                'stage' => InspectionPlan::RETURN, 'stock_item' => $item, 'warehouse' => $warehouse, 'qty' => $qty, 'lot_id' => $line->lot_id,
                'bin_id' => $line->bin_id, 'source_type' => 'WMS_RETURN', 'source_id' => $line->id, 'reference' => $return->number,
                'notes' => 'Re-inspection after repair',
            ], null);
            $line->update(['inspection_id' => $next->id]);
        }

        $open = $return->lines()->whereNotIn('outcome', CustomerReturnLine::FINAL)->exists();
        if (! $open) {
            $return->update(['status' => CustomerReturn::COMPLETED, 'completed_at' => now()]);
        }
    }

    /**
     * The customer's credit, through Finance's own credit note. One per return: a second request
     * is refused rather than quietly crediting the customer twice.
     */
    public function credit(CustomerReturn $return, float $amount, User $by): FinInvoice
    {
        if ($return->credit_note_id) {
            throw new RuntimeException("{$return->number} has already been credited ({$return->creditNote?->number}).");
        }
        if (! $return->invoice_id) {
            throw new RuntimeException('Link the invoice the goods were sold on before crediting the return.');
        }
        if (! in_array($return->status, [CustomerReturn::RECEIVED, CustomerReturn::INSPECTING, CustomerReturn::COMPLETED], true)) {
            throw new RuntimeException('Credit a return once the goods have arrived.');
        }

        return DB::transaction(function () use ($return, $amount, $by) {
            $invoice = FinInvoice::query()->lockForUpdate()->findOrFail($return->invoice_id);
            $cn = $this->arap->createCreditNote($invoice, $amount, "Customer return {$return->number}");
            $return->update(['credit_note_id' => $cn->id, 'credit_amount' => $amount]);

            return $cn;
        });
    }

    public function cancel(CustomerReturn $return, string $why): CustomerReturn
    {
        if ($return->status !== CustomerReturn::AUTHORISED) {
            throw new RuntimeException('Goods already received cannot be un-received; decide each line instead.');
        }
        if (blank($why)) {
            throw new RuntimeException('Say why the return is cancelled.');
        }
        $return->update(['status' => CustomerReturn::CANCELLED, 'cancel_reason' => $why]);

        return $return;
    }

    /** A recalled, expired or opened item is always looked at; unopened goods only where the warehouse insists. */
    private function needsInspection(FinWarehouse $warehouse, FinStockItem $item, string $condition): bool
    {
        if ($condition !== 'UNOPENED' || ($item->status ?? FinStockItem::ACTIVE) !== FinStockItem::ACTIVE) {
            return true;
        }

        return (bool) ($warehouse->policies['returns_require_inspection'] ?? true);
    }

    /** The cost the goods left at: their dispatch on this order, else the item's current cost. */
    private function costOut(CustomerReturn $return, CustomerReturnLine $line, ?int $lotId): float
    {
        if ($return->order_id) {
            $cost = DB::table('fin_stock_movements as m')
                ->join('wms_allocations as a', fn ($j) => $j->on('a.id', '=', 'm.source_id')->where('m.source_type', 'WMS_ALLOCATION'))
                ->join('wms_order_lines as ol', 'ol.id', '=', 'a.order_line_id')
                ->where('ol.order_id', $return->order_id)->where('m.item_id', $line->item_id)->where('m.type', 'dispatch')
                ->when($lotId, fn ($q, $v) => $q->where('m.lot_id', $v))
                ->selectRaw('SUM(ABS(m.value)) / NULLIF(SUM(ABS(m.qty)), 0) AS c')->value('c');
            if ($cost !== null) {
                return round((float) $cost, 4);
            }
        }
        $item = $line->item;

        return $item->isValued() ? ((float) $item->avg_cost ?: (float) $item->standard_cost) : 0.0;
    }

    private function returnsBin(FinWarehouse $warehouse): ?FinStockBin
    {
        return FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('level', 'BIN')->where('is_active', true)
            ->whereIn('kind', ['RETURNS', 'QUARANTINE', 'RECEIVING'])
            ->orderByRaw("CASE kind WHEN 'RETURNS' THEN 0 WHEN 'QUARANTINE' THEN 1 ELSE 2 END")->orderBy('code')->first();
    }

    private function putAway(FinWarehouse $warehouse, CustomerReturnLine $line, ?FinStockBin $from, string $status): void
    {
        if (! $warehouse->is_wms_managed || ! $from) {
            return;
        }
        $this->putaway->create($warehouse, $line->item, (float) $line->qty_received, [
            'lot_id' => $line->lot_id, 'from_bin_id' => $from->id, 'stock_status' => $status,
            'source_type' => 'WMS_RETURN', 'source_id' => $line->return_id,
        ]);
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-return-number:'.$companyId)]);
        $prefix = 'RMA-'.now()->format('Y').'-';
        $last = CustomerReturn::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
