<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * WMS-025: a balance traced to the movements that make it, and each movement to the document,
 * the person, the approval and the journal behind it.
 *
 * The running balance is computed by the database over the same rows the balance was built from,
 * so the last figure in the list is the balance -- and `reconcile()` says so, or says by how much
 * it is not.
 */
class StockHistory
{
    /** source_type => [table, route name] for the documents a movement can point at. */
    private const SOURCES = [
        'WMS_ADJUSTMENT' => ['wms_stock_adjustments', 'warehouse.stock.adjustments.show'],
        'PRODUCTION_ORDER' => ['mfg_production_orders', 'manufacturing.production.orders.show'],
        'INSPECTION' => ['mfg_inspections', 'manufacturing.quality.inspections.show'],
        'GRN' => ['goods_received_notes', 'warehouse.inbound.receipts.show'],
    ];

    public function __construct(private readonly ClientVisibility $clients) {}

    /** @param array{item_id?:?int, warehouse_id?:?int, bin_id?:?int, lot_id?:?int, type?:?string, from?:?string, to?:?string, reason_code?:?string} $f */
    public function movements(array $f, int $perPage = 50): LengthAwarePaginator
    {
        $base = DB::table('fin_stock_movements as m')
            ->when($f['item_id'] ?? null, fn ($q, $v) => $q->where('m.item_id', $v))
            ->when($f['warehouse_id'] ?? null, fn ($q, $v) => $q->where('m.warehouse_id', $v))
            ->when($f['bin_id'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('m.bin_id', $v)->orWhere('m.to_bin_id', $v)))
            ->when($f['lot_id'] ?? null, fn ($q, $v) => $q->where('m.lot_id', $v))
            ->when($f['reason_code'] ?? null, fn ($q, $v) => $q->where('m.reason_code', $v));
        $this->clients->scope($base, 'm.item_id');

        // The running balance runs over the item and warehouse whatever the date filter, so a
        // filtered page still shows the true balance after each row.
        $ranked = (clone $base)->selectRaw('m.*, SUM(m.qty) OVER (PARTITION BY m.item_id, m.warehouse_id ORDER BY m.id) AS running_balance');

        $rows = DB::query()->fromSub($ranked, 'r')
            ->leftJoin('fin_stock_items as i', 'i.id', '=', 'r.item_id')
            ->leftJoin('fin_warehouses as w', 'w.id', '=', 'r.warehouse_id')
            ->leftJoin('fin_warehouses as tw', 'tw.id', '=', 'r.to_warehouse_id')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'r.bin_id')
            ->leftJoin('fin_stock_bins as tb', 'tb.id', '=', 'r.to_bin_id')
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'r.lot_id')
            ->leftJoin('mfg_uoms as u', 'u.id', '=', 'r.txn_uom_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'r.created_by')
            ->leftJoin('users as au', 'au.id', '=', 'r.approved_by')
            ->leftJoin('fin_stock_movements as rev', 'rev.reversal_of_id', '=', 'r.id')
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('r.type', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('r.movement_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('r.movement_date', '<=', $v))
            ->select('r.*', 'i.code as item_code', 'i.name as item_name', 'i.uuid as item_uuid', 'i.uom', 'w.code as warehouse',
                'tw.code as to_warehouse', 'b.code as bin', 'tb.code as to_bin', 'l.lot_number as lot', 'u.code as txn_uom',
                'cu.email as created_by_email', 'au.email as approved_by_email', 'rev.uuid as reversed_by')
            ->orderByDesc('r.id')
            ->paginate($perPage)->withQueryString();

        return $rows->through(fn ($r) => [
            'id' => $r->uuid, 'type' => $r->type, 'date' => $r->movement_date, 'recorded_at' => $r->created_at,
            'occurred_at' => $r->occurred_at, 'item' => $r->item_code, 'item_name' => $r->item_name, 'item_id' => $r->item_uuid,
            'warehouse' => $r->warehouse, 'to_warehouse' => $r->to_warehouse, 'bin' => $r->bin, 'to_bin' => $r->to_bin,
            'lot' => $r->lot, 'status' => $r->stock_status, 'to_status' => $r->to_stock_status,
            'qty' => (float) $r->qty, 'uom' => $r->uom, 'txn' => $r->txn_qty !== null ? ((float) $r->txn_qty).' '.$r->txn_uom : null,
            'value' => (float) $r->value, 'running_balance' => (float) $r->running_balance,
            'reason_code' => $r->reason_code, 'reference' => $r->reference,
            'source' => $this->sourceLink($r->source_type, $r->source_id),
            'journal_id' => $r->journal_id,
            'created_by' => $r->created_by_email, 'approved_by' => $r->approved_by_email, 'approval_reference' => $r->approval_reference,
            'reversal_of' => $r->reversal_of_id ? DB::table('fin_stock_movements')->where('id', $r->reversal_of_id)->value('uuid') : null,
            'reversed_by' => $r->reversed_by,
        ]);
    }

    /**
     * Does the ledger add up to the balance? Movements written before warehouses were tracked carry
     * no warehouse; they are reported as such rather than silently ignored.
     *
     * @return list<array{warehouse_id:int, warehouse:string, balance:float, movements:float, difference:float}>
     */
    public function reconcile(int $itemId): array
    {
        // Status changes and re-lots move stock between positions and change no balance.
        $moves = DB::table('fin_stock_movements')->where('item_id', $itemId)->whereNotIn('type', LedgerVerifier::POSITION_ONLY)
            ->groupBy('warehouse_id')->selectRaw('warehouse_id, SUM(qty) AS total')->pluck('total', 'warehouse_id');
        $balances = DB::table('fin_stock_balances as b')->join('fin_warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->where('b.item_id', $itemId)->get(['b.warehouse_id', 'b.qty', 'w.code']);

        $out = $balances->map(fn ($b) => [
            'warehouse_id' => (int) $b->warehouse_id, 'warehouse' => $b->code, 'balance' => (float) $b->qty,
            'movements' => (float) ($moves[$b->warehouse_id] ?? 0),
            'difference' => round((float) $b->qty - (float) ($moves[$b->warehouse_id] ?? 0), 4),
        ])->values()->all();

        if (isset($moves[''])) {
            $out[] = ['warehouse_id' => 0, 'warehouse' => null, 'balance' => 0.0, 'movements' => (float) $moves[''], 'difference' => -(float) $moves['']];
        }

        return $out;
    }

    private function sourceLink(?string $type, $id): ?array
    {
        if ($type === null) {
            return null;
        }
        [$table, $routeName] = self::SOURCES[$type] ?? [null, null];
        $label = $type.($id ? " #{$id}" : '');

        if ($table && $id) {
            $row = DB::table($table)->where('id', $id)->first();
            $label = $row->number ?? $row->order_number ?? $row->grn_number ?? $row->reference ?? $label;
            if ($row && isset($row->uuid) && Route::has($routeName)) {
                return ['label' => $label, 'url' => route($routeName, $row->uuid)];
            }
        }

        return ['label' => $label, 'url' => null];
    }
}
