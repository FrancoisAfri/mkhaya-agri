<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\TransferLine;
use App\Modules\Operations\WarehouseManagement\Models\TransferOrder;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Warehouse-to-warehouse transfers (WMS-051):
 * DRAFT → APPROVED → PICKED → DISPATCHED → PARTIALLY_RECEIVED / RECEIVED → RECONCILED.
 *
 * At dispatch the stock moves, in the ledger, into the destination's in-transit location: it has
 * left the source, it is on the destination's books, and nobody at either end can promise or pick
 * it. Receipt moves it out of transit into a bin; a second confirmation of the same line is refused
 * because a line cannot receive more than was dispatched (and the schema says so too). What never
 * arrives is reconciled by a reasoned write-off, not by editing the numbers.
 *
 * Before dispatch a transfer can simply be cancelled. After dispatch, cancelling it brings
 * whatever is still in transit back to the source -- a controlled reversal, with a reason.
 */
class TransferOrders
{
    public function __construct(private readonly StockLedger $ledger, private readonly StockAdjustments $adjustments) {}

    /** @param list<array{item_id:int, qty:float, lot_id?:?int, from_bin_id?:?int}> $lines */
    public function draft(FinWarehouse $from, FinWarehouse $to, array $lines, User $user, array $attrs = []): TransferOrder
    {
        if ($from->id === $to->id) {
            throw new RuntimeException('A transfer goes between two different warehouses; move stock between bins instead.');
        }
        if ($lines === []) {
            throw new RuntimeException('Add at least one line.');
        }

        return DB::transaction(function () use ($from, $to, $lines, $user, $attrs) {
            $order = TransferOrder::create([
                'company_id' => $from->company_id, 'number' => $this->nextNumber((int) $from->company_id),
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'status' => 'DRAFT', 'created_by' => $user->id,
            ] + array_intersect_key($attrs, array_flip(['reason', 'priority', 'needed_by', 'source'])));
            foreach ($lines as $l) {
                if ((float) $l['qty'] <= 0) {
                    throw new RuntimeException('Every line needs a quantity above zero.');
                }
                $order->lines()->create(['company_id' => $from->company_id, 'item_id' => $l['item_id'], 'qty' => $l['qty'],
                    'lot_id' => $l['lot_id'] ?? null, 'from_bin_id' => $l['from_bin_id'] ?? null]);
            }

            return $order->fresh('lines');
        });
    }

    public function approve(TransferOrder $order, User $user): TransferOrder
    {
        $this->assertStatus($order, ['DRAFT']);
        if (! $user->can(RoleCatalog::APPROVE_TRANSFERS)) {
            throw new RuntimeException('Approving a transfer needs the transfer-approval permission.');
        }
        $order->update(['status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now()]);

        return $order;
    }

    /** @param array<int,float> $picked line id => qty actually picked */
    public function pick(TransferOrder $order, array $picked, User $user): TransferOrder
    {
        $this->assertStatus($order, ['APPROVED']);

        return DB::transaction(function () use ($order, $picked) {
            foreach ($order->lines as $line) {
                $qty = (float) ($picked[$line->id] ?? $line->qty);
                if ($qty < 0 || $qty > (float) $line->qty + 1e-6) {
                    throw new RuntimeException("Pick between 0 and {$line->qty} of line {$line->id}.");
                }
                $there = (float) FinStockPosition::query()->where('item_id', $line->item_id)->where('warehouse_id', $order->from_warehouse_id)
                    ->where('status', FinStockPosition::AVAILABLE)
                    ->when($line->from_bin_id, fn ($q, $v) => $q->where('bin_id', $v), fn ($q) => $q->whereNull('bin_id'))
                    ->when($line->lot_id, fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'))->value('qty');
                if ($qty > $there + 1e-6) {
                    throw new RuntimeException("Only {$there} of {$line->item->code} is available where line {$line->id} picks from.");
                }
                $line->update(['picked_qty' => $qty]);
            }
            $order->update(['status' => 'PICKED', 'picked_at' => now()]);

            return $order->fresh('lines');
        });
    }

    public function dispatch(TransferOrder $order, User $user, array $attrs = []): TransferOrder
    {
        $this->assertStatus($order, ['PICKED']);

        return DB::transaction(function () use ($order, $attrs) {
            $transit = $this->transitBin($order->to);
            foreach ($order->lines as $line) {
                $qty = (float) $line->picked_qty;
                if ($qty <= 0) {
                    continue;
                }
                $this->ledger->transfer(FinStockItem::query()->findOrFail($line->item_id), $order->from, $order->to, $qty, [
                    'bin_id' => $line->from_bin_id, 'to_bin_id' => $transit->id, 'lot_id' => $line->lot_id,
                    'source_type' => 'TRANSFER_ORDER', 'source_id' => $order->id, 'reference' => $order->number,
                    'idempotency_key' => "transfer:{$order->id}:line:{$line->id}:dispatch",
                ]);
                $line->update(['dispatched_qty' => $qty]);
            }
            $order->update(['status' => 'DISPATCHED', 'dispatched_at' => now()] + array_intersect_key($attrs, array_flip(['carrier', 'vehicle_reg', 'tracking_reference'])));

            return $order->fresh('lines');
        });
    }

    /** @param array<int,array{qty:float, to_bin_id?:?int}> $received line id => what arrived and where it went */
    public function receive(TransferOrder $order, array $received, User $user): TransferOrder
    {
        $this->assertStatus($order, ['DISPATCHED', 'PARTIALLY_RECEIVED']);

        return DB::transaction(function () use ($order, $received) {
            $transit = $this->transitBin($order->to);
            foreach ($order->lines()->lockForUpdate()->get() as $line) {
                $in = $received[$line->id] ?? null;
                if (! $in || (float) $in['qty'] <= 0) {
                    continue;
                }
                $qty = (float) $in['qty'];
                $open = (float) $line->dispatched_qty - (float) $line->received_qty;
                if ($qty > $open + 1e-6) {
                    throw new RuntimeException("Line {$line->id}: only {$open} is still in transit; it cannot be received twice.");
                }
                $bin = ! empty($in['to_bin_id']) ? FinStockBin::query()->where('warehouse_id', $order->to_warehouse_id)->findOrFail($in['to_bin_id']) : null;
                $this->ledger->transfer(FinStockItem::query()->findOrFail($line->item_id), $order->to, $order->to, $qty, [
                    'bin_id' => $transit->id, 'to_bin_id' => $bin?->id ?? $this->receivingBin($order->to)?->id, 'lot_id' => $line->lot_id,
                    'source_type' => 'TRANSFER_ORDER', 'source_id' => $order->id, 'reference' => $order->number,
                    'idempotency_key' => "transfer:{$order->id}:line:{$line->id}:received:".round((float) $line->received_qty + $qty, 4),
                ]);
                $line->update(['received_qty' => round((float) $line->received_qty + $qty, 4), 'to_bin_id' => $bin?->id ?? $line->to_bin_id]);
            }
            $all = $order->lines()->get()->every(fn (TransferLine $l) => (float) $l->received_qty >= (float) $l->dispatched_qty - 1e-6);
            $order->update(['status' => $all ? 'RECEIVED' : 'PARTIALLY_RECEIVED', 'received_at' => now()]);

            return $order->fresh('lines');
        });
    }

    /**
     * Close the transfer. What was dispatched and never arrived is written off as lost in transit
     * through a reasoned adjustment -- the person reconciling says why, and the adjustment's own
     * approval rules decide whether a second person must agree.
     */
    public function reconcile(TransferOrder $order, User $user, ?string $note = null): TransferOrder
    {
        $this->assertStatus($order, ['RECEIVED', 'PARTIALLY_RECEIVED']);

        return DB::transaction(function () use ($order, $user, $note) {
            $transit = $this->transitBin($order->to);
            $reason = $this->transitLossReason((int) $order->company_id);
            foreach ($order->lines as $line) {
                $variance = round((float) $line->dispatched_qty - (float) $line->received_qty, 4);
                if ($variance > 0) {
                    if (trim((string) $note) === '') {
                        throw new RuntimeException('Some stock never arrived. Say what happened to it before reconciling.');
                    }
                    $this->adjustments->request([
                        'warehouse_id' => $order->to_warehouse_id, 'item_id' => $line->item_id, 'bin_id' => $transit->id,
                        'lot_id' => $line->lot_id, 'qty' => -$variance, 'reason_code_id' => $reason->id,
                        'notes' => "{$order->number}: {$note}",
                    ], $user);
                }
                $line->update(['variance_qty' => $variance, 'variance_note' => $variance > 0 ? $note : null]);
            }
            $order->update(['status' => 'RECONCILED', 'reconciled_at' => now()]);

            return $order->fresh('lines');
        });
    }

    public function cancel(TransferOrder $order, User $user, string $reason): TransferOrder
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the transfer is being cancelled.');
        }
        $this->assertStatus($order, ['DRAFT', 'APPROVED', 'PICKED', 'DISPATCHED', 'PARTIALLY_RECEIVED']);

        return DB::transaction(function () use ($order, $user, $reason) {
            if (in_array($order->status, ['DISPATCHED', 'PARTIALLY_RECEIVED'], true)) {
                // Controlled reversal: only what is still on the truck comes back.
                if (! $user->can(RoleCatalog::APPROVE_TRANSFERS)) {
                    throw new RuntimeException('Recalling a dispatched transfer needs the transfer-approval permission.');
                }
                $transit = $this->transitBin($order->to);
                foreach ($order->lines as $line) {
                    $back = round((float) $line->dispatched_qty - (float) $line->received_qty, 4);
                    if ($back <= 0) {
                        continue;
                    }
                    $this->ledger->transfer(FinStockItem::query()->findOrFail($line->item_id), $order->to, $order->from, $back, [
                        'bin_id' => $transit->id, 'to_bin_id' => $line->from_bin_id, 'lot_id' => $line->lot_id,
                        'source_type' => 'TRANSFER_ORDER', 'source_id' => $order->id, 'reference' => "{$order->number} recalled",
                        'idempotency_key' => "transfer:{$order->id}:line:{$line->id}:recall",
                    ]);
                    $line->update(['dispatched_qty' => $line->received_qty]);
                }
            }
            $order->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);

            return $order;
        });
    }

    /** The destination's in-transit location, created the first time a transfer needs it. */
    public function transitBin(FinWarehouse $warehouse): FinStockBin
    {
        return FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('kind', 'IN_TRANSIT')->where('is_active', true)->orderBy('id')->first()
            ?? FinStockBin::create([
                'company_id' => $warehouse->company_id, 'entity_id' => $warehouse->entity_id, 'warehouse_id' => $warehouse->id,
                'code' => 'TRANSIT', 'name' => 'Inbound transfers in transit', 'kind' => 'IN_TRANSIT', 'level' => 'BIN',
                'barcode' => $warehouse->code.'.TRANSIT', 'is_active' => true, 'status' => FinStockBin::OPEN,
            ]);
    }

    private function receivingBin(FinWarehouse $warehouse): ?FinStockBin
    {
        return FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('kind', 'RECEIVING')->where('level', 'BIN')
            ->where('is_active', true)->where('status', FinStockBin::OPEN)->orderBy('code')->first();
    }

    private function transitLossReason(int $companyId): ReasonCode
    {
        return ReasonCode::query()->firstOrCreate(['company_id' => $companyId, 'code' => 'TRANSIT_LOSS'], [
            'name' => 'Lost in transit', 'category' => 'LOSS', 'direction' => 'OUT', 'approval_value' => 2000, 'is_active' => true,
        ]);
    }

    private function assertStatus(TransferOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw new RuntimeException("{$order->number} is ".strtolower(str_replace('_', ' ', $order->status)).'.');
        }
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-transfer-number:'.$companyId)]);
        $prefix = 'TRF-'.now()->format('Y').'-';
        $last = TransferOrder::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
