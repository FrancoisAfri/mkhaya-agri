<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetCommitment;
use App\Modules\Budget\Models\BudgetReservation;
use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\DeliveryNote;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Procurement\Models\ProcurementAssetLink;
use App\Modules\Procurement\Models\ProcurementInventoryLink;
use App\Modules\Procurement\Models\ProcurementRequisition;
use App\Modules\Procurement\Models\PurchaseOrderAmendment;
use App\Modules\Procurement\Models\PurchaseOrderItem;
use App\Modules\SupplierManagement\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Order lifecycle — the part of the P2P flow that locks and spends funds.
 *
 *   generateFromRequisition → commitBudget (reservation → commitment)
 *   approve / send          → issue the PO to the supplier
 *   captureDeliveryNote     → record a supplier delivery
 *   createGRN (receipt)     → consumeBudget (commitment → actual) + asset/inventory links
 *   amend / cancel          → change-control & release
 */
class PurchaseOrderService
{
    public function __construct(private readonly BudgetService $budgets) {}

    /**
     * Create a PO from an AWARDED requisition, copying its items and converting the
     * carried budget reservation into a commitment.
     */
    public function generateFromRequisition(ProcurementRequisition $req, ?int $userId = null): PurchaseOrder
    {
        if ($req->status !== 'awarded') {
            throw new \RuntimeException('A purchase order can only be generated from an awarded requisition.');
        }
        if (! $req->supplier_id) {
            throw new \RuntimeException('Select a supplier on the requisition before generating a purchase order.');
        }
        if (PurchaseOrder::where('procurement_requisition_id', $req->id)->exists()) {
            throw new \RuntimeException('A purchase order already exists for this requisition.');
        }

        return DB::transaction(function () use ($req, $userId) {
            $req->loadMissing('items');

            $total = (float) ($req->estimated_total ?: $req->items->sum(fn ($i) => (float) $i->line_total));

            $po = PurchaseOrder::create([
                'supplier_id'                => $req->supplier_id,
                'title'                      => $req->title,
                'amount'                     => $total,
                'currency'                   => $req->currency ?: 'ZAR',
                'status'                     => 'draft',
                'source'                     => 'requisition',
                'procurement_requisition_id' => $req->id,
                'budget_id'                  => $req->budget_id,
                'order_date'                 => now()->toDateString(),
                'received_status'            => 'pending',
                'created_by'                 => $userId,
                // Delivered where the request asked for it (a farm store, say), else the default.
                'warehouse_id'               => $req->purchaseRequest?->warehouse_id,
            ]);

            foreach ($req->items as $item) {
                $po->items()->create([
                    'name'               => $item->name,
                    'description'        => $item->description,
                    'budget_category_id' => $item->budget_category_id,
                    'quantity'           => $item->quantity,
                    'unit_price'         => $item->unit_price,
                    'stock_item_id'      => $item->stock_item_id ?? null,
                    'is_asset'           => $this->looksLikeAsset($item->name),
                ]);
            }

            // Commit the funds: convert the requisition's reservation into a commitment.
            if ($req->budget_id && ($budget = Budget::find($req->budget_id))) {
                $reservation = $req->budget_reservation_id
                    ? BudgetReservation::where('id', $req->budget_reservation_id)->where('status', 'active')->first()
                    : null;

                $commitment = $this->budgets->commitBudget($budget, $total, 'purchase_order', $po->id, $reservation);
                $po->update(['budget_commitment_id' => $commitment->id]);
            }

            $req->update(['status' => 'po_created']);

            return $po->refresh();
        });
    }

    /** Manually create a PO (off-requisition / direct buy). */
    public function createManual(array $data, array $items, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $items, $userId) {
            $po = PurchaseOrder::create(array_merge([
                'status'          => 'draft',
                'source'          => 'manual',
                'currency'        => 'ZAR',
                'order_date'      => now()->toDateString(),
                'received_status' => 'pending',
                'created_by'      => $userId,
            ], $data));

            $total = 0.0;
            foreach ($items as $item) {
                $line = $po->items()->create([
                    'name'               => $item['name'],
                    'description'        => $item['description'] ?? null,
                    'budget_category_id' => $item['budget_category_id'] ?? null,
                    'quantity'           => $item['quantity'] ?? 1,
                    'unit_price'         => $item['unit_price'] ?? 0,
                    'is_asset'           => $item['is_asset'] ?? $this->looksLikeAsset($item['name']),
                ]);
                $total += (float) $line->line_total;
            }
            $po->update(['amount' => $total]);

            // Optional budget commitment if a budget was attached.
            if (! empty($data['budget_id']) && ($budget = Budget::find($data['budget_id']))) {
                $commitment = $this->budgets->commitBudget($budget, $total, 'purchase_order', $po->id);
                $po->update(['budget_commitment_id' => $commitment->id]);
            }

            return $po->refresh();
        });
    }

    public function approve(PurchaseOrder $po, ?int $userId = null): PurchaseOrder
    {
        $po->update(['status' => 'approved']);

        return $po->refresh();
    }

    /** Issue the PO to the supplier. */
    public function send(PurchaseOrder $po, ?int $userId = null): PurchaseOrder
    {
        $po->update(['status' => 'sent']);

        return $po->refresh();
    }

    public function captureDeliveryNote(PurchaseOrder $po, array $data, ?int $userId = null): DeliveryNote
    {
        $note = $po->deliveryNotes()->create([
            'supplier_ref' => $data['supplier_ref'] ?? null,
            'delivered_at' => $data['delivered_at'] ?? now()->toDateString(),
            'notes'        => $data['notes'] ?? null,
            'captured_by'  => $userId,
        ]);

        if (! $po->delivered_at) {
            $po->update(['delivered_at' => now()]);
        }

        return $note;
    }

    /**
     * Receive goods (GRN). Each line: {item_id, accepted_qty, rejected_qty, condition, note}.
     * Updates received quantities, consumes the committed budget for accepted value,
     * and creates asset/inventory links.
     *
     * Lines whose order line names a stock item also post that stock (WHMI decision 2), through the
     * warehouse's GrnStockPoster -- from this screen and the warehouse's receiving screen alike. The
     * optional line keys (txn_uom_id, damaged_qty, missing_qty, lot_number, expires_on,
     * manufactured_on, serial_numbers, bin_id, keep_rejected) and $options (warehouse_id,
     * delivery_note_ref, vehicle_reg, appointment_id, idempotency_key) are the warehouse's; a caller
     * that passes none of them receives exactly as before.
     */
    public function createGRN(PurchaseOrder $po, array $lines, ?int $userId = null, ?string $notes = null, array $options = []): GoodsReceivedNote
    {
        if (! empty($options['idempotency_key'])) {
            $previous = GoodsReceivedNote::query()->where('idempotency_key', $options['idempotency_key'])->first();
            if ($previous) {
                return $previous;
            }
        }

        return DB::transaction(function () use ($po, $lines, $userId, $notes, $options) {
            $po->loadMissing('items');

            $grn = $po->goodsReceivedNotes()->create([
                'status'      => 'partial',
                'received_by' => $userId,
                'received_at' => $options['received_at'] ?? now(),
                'notes'       => $notes,
            ] + array_intersect_key($options, array_flip([
                'warehouse_id', 'delivery_note_ref', 'vehicle_reg', 'appointment_id', 'idempotency_key',
            ])));
            $stockExtras = [];

            $receivedValue = 0.0;

            foreach ($lines as $line) {
                $item = $po->items->firstWhere('id', (int) ($line['item_id'] ?? 0));
                if (! $item) {
                    continue;
                }

                $accepted  = max(0, (float) ($line['accepted_qty'] ?? 0));
                $rejected  = max(0, (float) ($line['rejected_qty'] ?? 0));
                $condition = ($line['condition'] ?? 'good') === 'damaged' ? 'damaged' : 'good';

                if ($accepted <= 0 && $rejected <= 0) {
                    continue;
                }

                $grnItem = $grn->items()->create([
                    'purchase_order_item_id' => $item->id,
                    'received_qty'           => $accepted + $rejected,
                    'accepted_qty'           => $accepted,
                    'rejected_qty'           => $rejected,
                    'condition'              => $condition,
                    'note'                   => $line['note'] ?? null,
                    'company_id'             => $po->company_id,
                    'stock_item_id'          => $item->stock_item_id,
                    'ordered_qty'            => $item->quantity,
                ] + array_intersect_key($line, array_flip([
                    'txn_uom_id', 'damaged_qty', 'missing_qty', 'bin_id', 'serial_numbers', 'expires_on', 'manufactured_on',
                ])));
                $stockExtras[$grnItem->id] = array_intersect_key($line, array_flip(['lot_number', 'supplier_lot_number', 'keep_rejected']));

                if ($accepted > 0) {
                    $item->increment('received_qty', $accepted);
                    $lineValue     = round($accepted * (float) $item->unit_price, 2);
                    $receivedValue += $lineValue;

                    $this->createStockLink($po, $item, $accepted, $lineValue);
                }
            }

            $grn->update(['received_value' => round($receivedValue, 2)]);

            // Consume the committed budget for the accepted value (commitment → actual).
            if ($receivedValue > 0 && $po->budget_id && ($budget = Budget::find($po->budget_id))) {
                $this->consumeAgainstCommitment($budget, $po, round($receivedValue, 2), $grn->grn_number);
            }

            $this->refreshReceivedStatus($po, $grn);

            // Stock lines post their stock now, inside this transaction: a receipt that cannot
            // post -- a discontinued item, a missing batch number -- is not received at all.
            app(\App\Modules\Operations\WarehouseManagement\Services\GrnStockPoster::class)->post($grn->fresh(), $stockExtras);

            return $grn->refresh();
        });
    }

    /** Record a change to the PO (value / quantity / other). */
    public function amend(PurchaseOrder $po, array $data, ?int $userId = null): PurchaseOrderAmendment
    {
        return DB::transaction(function () use ($po, $data, $userId) {
            $type = $data['type'] ?? 'other';
            $old  = (float) $po->amount;
            $new  = isset($data['new_amount']) ? (float) $data['new_amount'] : $old;

            $amendment = $po->amendments()->create([
                'type'       => $type,
                'old_amount' => $old,
                'new_amount' => $type === 'value' ? $new : null,
                'detail'     => $data['detail'] ?? null,
                'reason'     => $data['reason'] ?? null,
                'amended_by' => $userId,
            ]);

            // A value amendment adjusts the PO total and the outstanding commitment.
            if ($type === 'value' && abs($new - $old) > 0.005) {
                $po->update(['amount' => $new]);
                $this->adjustCommitment($po, round($new - $old, 2));
            }

            return $amendment;
        });
    }

    public function cancel(PurchaseOrder $po, ?int $userId = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po) {
            // Release any outstanding (un-consumed) commitment back to the budget.
            if ($po->budget_commitment_id) {
                $commitment = BudgetCommitment::where('id', $po->budget_commitment_id)->where('status', 'active')->first();
                $commitment?->update(['status' => 'released', 'amount' => 0]);
            }
            $po->update(['status' => 'cancelled']);

            return $po->refresh();
        });
    }

    // ── Internals ───────────────────────────────────────────────────────────────

    /**
     * Consume accepted value against the PO's commitment without double-counting:
     * the commitment amount is drawn down and a consumption is recorded. The
     * commitment is closed once fully drawn.
     */
    private function consumeAgainstCommitment(Budget $budget, PurchaseOrder $po, float $value, string $ref): void
    {
        $commitment = $po->budget_commitment_id
            ? BudgetCommitment::find($po->budget_commitment_id)
            : null;

        if ($commitment && $commitment->status === 'active') {
            $remaining = round((float) $commitment->amount - $value, 2);
            $commitment->update([
                'amount' => max(0, $remaining),
                'status' => $remaining <= 0.005 ? 'consumed' : 'active',
            ]);
        }

        // Record the actual spend (commitment draw-down handled above, so pass null).
        $this->budgets->consumeBudget($budget, $value, 'purchase_order', $po->id, null, "GRN {$ref}");
    }

    /** Apply a value-amendment delta to the outstanding commitment. */
    private function adjustCommitment(PurchaseOrder $po, float $delta): void
    {
        if (! $po->budget_commitment_id) {
            return;
        }
        $commitment = BudgetCommitment::where('id', $po->budget_commitment_id)->where('status', 'active')->first();
        $commitment?->update(['amount' => max(0, round((float) $commitment->amount + $delta, 2))]);
    }

    private function refreshReceivedStatus(PurchaseOrder $po, GoodsReceivedNote $grn): void
    {
        $po->load('items');
        $fully = $po->items->every(fn (PurchaseOrderItem $i) => (float) $i->received_qty >= (float) $i->quantity);
        $any   = $po->items->contains(fn (PurchaseOrderItem $i) => (float) $i->received_qty > 0);

        $status = $fully ? 'received' : ($any ? 'partial' : 'pending');
        $po->update(['received_status' => $status, 'delivered_at' => $po->delivered_at ?: now()]);
        $grn->update(['status' => $fully ? 'complete' : 'partial']);
    }

    private function createStockLink(PurchaseOrder $po, PurchaseOrderItem $item, float $qty, float $value): void
    {
        if ($item->is_asset) {
            ProcurementAssetLink::create([
                'purchase_order_id'      => $po->id,
                'purchase_order_item_id' => $item->id,
                'asset_ref'              => 'AST-'.$po->po_number.'-'.$item->id,
                'name'                   => $item->name,
                'value'                  => $value,
            ]);

            return;
        }

        // A line bought as a stock item is in the stock ledger now (WHMI); a name-only inventory link
        // beside it would be a second, uncosted record of the same goods.
        if ($item->stock_item_id) {
            return;
        }

        ProcurementInventoryLink::create([
            'purchase_order_id'      => $po->id,
            'purchase_order_item_id' => $item->id,
            'inventory_ref'          => 'INV-'.$po->po_number.'-'.$item->id,
            'name'                   => $item->name,
            'quantity'               => $qty,
        ]);
    }

    private function looksLikeAsset(?string $name): bool
    {
        $name = strtolower((string) $name);
        foreach (['laptop', 'computer', 'desktop', 'monitor', 'printer', 'server', 'vehicle', 'machine', 'equipment', 'furniture', 'phone', 'tablet'] as $kw) {
            if (str_contains($name, $kw)) {
                return true;
            }
        }

        return false;
    }
}
