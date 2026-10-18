<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\DockAppointment;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\Procurement\Services\PurchaseOrderService;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The warehouse's way into Procurement's receiving (WMS-030 to WMS-033).
 *
 * Receiving against an order goes through Procurement's createGRN, so the order's received
 * quantities, its budget consumption and its three-way match keep working; this adds what the
 * dock needs on top -- the delivery note, the vehicle, the appointment, duplicate detection, and
 * receipts that arrive with no order at all, which wait for approval before anything is posted.
 */
class Receiving
{
    /** Order states in which goods are still expected. */
    public const RECEIVABLE = ['approved', 'sent', 'released', 'po_created', 'open', 'issued'];

    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly GrnStockPoster $poster,
        private readonly StockLedger $ledger,
    ) {}

    /** Open order lines that buy stock: outstanding, due, overdue (WMS-030). */
    public function expected(?int $warehouseId = null, bool $onlyOverdue = false): array
    {
        return DB::table('purchase_order_items as i')
            ->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'o.supplier_id')
            ->leftJoin('fin_stock_items as si', 'si.id', '=', 'i.stock_item_id')
            ->leftJoin('fin_warehouses as w', 'w.id', '=', 'o.warehouse_id')
            ->leftJoin('mfg_uoms as u', 'u.id', '=', 'i.uom_id')
            ->whereNull('o.deleted_at')->whereIn('o.status', self::RECEIVABLE)
            ->whereRaw('i.quantity > COALESCE(i.received_qty, 0)')
            ->when($warehouseId, fn ($q) => $q->where(fn ($w) => $w->where('o.warehouse_id', $warehouseId)->orWhereNull('o.warehouse_id')))
            ->when($onlyOverdue, fn ($q) => $q->whereDate('o.expected_delivery', '<', now()->toDateString()))
            ->orderByRaw('o.expected_delivery NULLS LAST')->orderBy('o.po_number')
            ->get(['o.uuid as po_id', 'o.po_number', 'o.expected_delivery', 'o.status as po_status', 's.company_name as supplier',
                'w.code as warehouse', 'i.uuid as line_id', 'i.name', 'i.quantity', 'i.received_qty', 'si.uuid as stock_item_id',
                'si.code as stock_code', 'u.code as uom'])
            ->map(fn ($r) => (array) $r + [
                'outstanding' => round((float) $r->quantity - (float) $r->received_qty, 4),
                'overdue' => $r->expected_delivery !== null && $r->expected_delivery < now()->toDateString(),
                'linked' => $r->stock_item_id !== null,
            ])->all();
    }

    /** Say which stock item, in which unit, an order line buys. Only before anything is received on it. */
    public function linkLine(PurchaseOrderItem $line, FinStockItem $item, ?int $uomId): PurchaseOrderItem
    {
        if ((float) $line->received_qty > 0 && $line->stock_item_id !== $item->id) {
            throw new RuntimeException('Goods have already been received on this line; its stock item cannot change.');
        }
        $line->update(['stock_item_id' => $item->id, 'uom_id' => $uomId]);

        return $line;
    }

    /**
     * Receive against a purchase order.
     *
     * @param  list<array{item_id:int, accepted_qty:float, rejected_qty?:float, damaged_qty?:float, missing_qty?:float,
     *               lot_number?:string, expires_on?:string, manufactured_on?:string, serial_numbers?:list<string>,
     *               bin_id?:int, keep_rejected?:bool, note?:string, condition?:string}>  $lines
     * @param  array{warehouse_id?:int, delivery_note_ref?:string, vehicle_reg?:string, appointment_id?:int,
     *               idempotency_key?:string, allow_duplicate?:bool, notes?:string}  $header
     */
    public function receiveOrder(PurchaseOrder $po, array $lines, array $header, User $user): GoodsReceivedNote
    {
        if (! in_array($po->status, self::RECEIVABLE, true)) {
            throw new RuntimeException("Purchase order {$po->po_number} is {$po->status}; goods can only be received against an approved order.");
        }

        $ref = trim((string) ($header['delivery_note_ref'] ?? ''));

        return DB::transaction(function () use ($po, $lines, $header, $user, $ref) {
            // Two people receiving against the same order wait for each other here, so the second sees
            // the first's receipt -- the same delivery cannot be posted twice by a race (QA-002).
            PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->first();
            $duplicate = null;
            if ($ref !== '') {
                $duplicate = GoodsReceivedNote::query()->where('delivery_note_ref', $ref)
                    ->whereHas('purchaseOrder', fn ($q) => $q->where('supplier_id', $po->supplier_id))->first();
                if ($duplicate && empty($header['allow_duplicate'])) {
                    throw new RuntimeException("Delivery note {$ref} from this supplier was already received on {$duplicate->grn_number}. Receive it again only if it is genuinely a second delivery.");
                }
                if ($duplicate && ! $user->can(RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS)) {
                    throw new RuntimeException('Receiving the same delivery note twice needs somebody allowed to decide receiving exceptions.');
                }
            }
            $grn = $this->orders->createGRN($po, $lines, $user->id, $header['notes'] ?? null, array_filter([
                'warehouse_id' => $header['warehouse_id'] ?? $po->warehouse_id, 'delivery_note_ref' => $ref ?: null,
                'vehicle_reg' => $header['vehicle_reg'] ?? null, 'appointment_id' => $header['appointment_id'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
            ], fn ($v) => $v !== null));

            if ($duplicate) {
                app(ReceiptDiscrepancies::class)->detect($grn->fresh(['items']), [], [['DUPLICATE', "Delivery note {$ref} was also received on {$duplicate->grn_number}"]]);
            }
            if (! empty($header['appointment_id'])) {
                DockAppointment::query()->whereKey($header['appointment_id'])->whereIn('status', ['ARRIVED', 'UNLOADING'])
                    ->update(['status' => 'COMPLETED', 'departed_at' => now()]);
            }

            return $grn;
        });
    }

    /**
     * Goods that arrived with no order (WMS-030): recorded now, posted only once somebody else
     * approves, because stock that appears without an order is exactly what a fraud looks like.
     *
     * @param  list<array{stock_item_id:int, qty:float, uom_id?:int, unit_cost?:float, lot_number?:string, expires_on?:string, bin_id?:int}>  $lines
     */
    public function receiveWithoutOrder(FinWarehouse $warehouse, ?int $supplierId, array $lines, string $reason, User $user, array $header = []): GoodsReceivedNote
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why goods are being received without a purchase order.');
        }
        if ($lines === []) {
            throw new RuntimeException('Record at least one line.');
        }

        return DB::transaction(function () use ($warehouse, $supplierId, $lines, $reason, $user, $header) {
            $grn = GoodsReceivedNote::create([
                'company_id' => $warehouse->company_id, 'purchase_order_id' => null, 'status' => 'complete',
                'received_by' => $user->id, 'received_at' => now(), 'notes' => $header['notes'] ?? null,
                'warehouse_id' => $warehouse->id, 'supplier_id' => $supplierId, 'source' => 'NON_PO', 'non_po_reason' => $reason,
                'approval_status' => 'PENDING', 'delivery_note_ref' => $header['delivery_note_ref'] ?? null,
                'vehicle_reg' => $header['vehicle_reg'] ?? null,
            ]);

            foreach ($lines as $l) {
                $item = FinStockItem::query()->findOrFail($l['stock_item_id']);
                $lotId = ! empty($l['lot_number']) ? $this->ledger->lot($item, $l['lot_number'], array_filter([
                    'origin' => 'RECEIPT', 'expires_on' => $l['expires_on'] ?? null, 'supplier_id' => $supplierId,
                ]))->id : null;
                $grn->items()->create([
                    'company_id' => $warehouse->company_id, 'stock_item_id' => $item->id, 'description' => $item->name,
                    'received_qty' => $l['qty'], 'accepted_qty' => $l['qty'], 'rejected_qty' => 0, 'condition' => 'good',
                    'txn_uom_id' => $l['uom_id'] ?? null, 'unit_cost' => $l['unit_cost'] ?? null, 'lot_id' => $lotId,
                    'expires_on' => $l['expires_on'] ?? null, 'bin_id' => $l['bin_id'] ?? null,
                ]);
            }

            return $grn->fresh('items');
        });
    }

    public function decideWithoutOrder(GoodsReceivedNote $grn, User $approver, bool $approve, ?string $note = null): GoodsReceivedNote
    {
        if ($grn->source !== 'NON_PO' || $grn->approval_status !== 'PENDING') {
            throw new RuntimeException("{$grn->grn_number} is not waiting for approval.");
        }
        if (! $approver->can(RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS)) {
            throw new RuntimeException('Only somebody allowed to decide receiving exceptions can approve a receipt without an order.');
        }
        if ((int) $grn->received_by === $approver->id) {
            throw new RuntimeException('A receipt without an order must be approved by somebody other than the person who received it.');
        }

        return DB::transaction(function () use ($grn, $approver, $approve, $note) {
            $grn->update(['approval_status' => $approve ? 'APPROVED' : 'REJECTED', 'approved_by' => $approver->id, 'approved_at' => now(),
                'notes' => trim(($grn->notes ? $grn->notes."\n" : '').($note ? ($approve ? 'Approved: ' : 'Rejected: ').$note : ''))]);
            if ($approve) {
                $this->poster->post($grn->fresh('items'));
            }

            return $grn->fresh();
        });
    }

    public function attach(GoodsReceivedNote $grn, UploadedFile $file, string $kind, User $user): \App\Modules\Operations\WarehouseManagement\Models\ReceiptAttachment
    {
        // Every upload passes the platform's malware scanner (structural checks; ClamAV where configured) -- WMS-221.
        if (($why = app(\App\Core\Storage\MalwareScanner::class)->scan($file)) !== null) {
            throw new RuntimeException("The file was refused: {$why}");
        }
        $path = 'wms/receipts/'.$grn->uuid.'/'.Str::uuid().'-'.Str::limit(preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()), 80, '');
        Storage::disk('documents')->put($path, $file->get());

        return \App\Modules\Operations\WarehouseManagement\Models\ReceiptAttachment::create([
            'company_id' => $grn->company_id, 'grn_id' => $grn->id, 'kind' => $kind, 'path' => $path,
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
    }
}
