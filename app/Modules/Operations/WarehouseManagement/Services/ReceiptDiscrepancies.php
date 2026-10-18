<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Models\ReceiptDiscrepancy;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * WMS-033: what arrived against what was ordered, judged against the warehouse's tolerances.
 *
 * Within tolerance a difference is recorded and accepted on the spot -- a supplier who ships 101 of
 * 100 is not an incident. Outside it, the difference stays open until somebody allowed to decide
 * receiving exceptions accepts or rejects it, and the buyer who raised the order is told.
 * Damage and rejection are never "within tolerance": they are facts somebody has to act on.
 */
class ReceiptDiscrepancies
{
    /** @param array<int,int> $shortShelfLife GRN item id => days of shelf life left */
    public function detect(GoodsReceivedNote $grn, array $shortShelfLife = [], array $extraKinds = []): void
    {
        $warehouse = $grn->warehouse;
        $overPct = (float) ($warehouse?->policies['receipt_over_tolerance_pct'] ?? 0);
        $found = [];

        if ($grn->source === 'NON_PO') {
            $found[] = [null, 'UNEXPECTED', null, null, false, 'Received without a purchase order: '.($grn->non_po_reason ?? '')];
        }
        foreach ($extraKinds as [$kind, $detail]) {
            $found[] = [null, $kind, null, null, false, $detail];
        }

        foreach ($grn->items as $line) {
            $po = $line->purchaseOrderItem;
            if ($po) {
                $ordered = (float) $po->quantity;
                $before = max(0.0, $ordered - ((float) $po->received_qty - (float) $line->accepted_qty));
                $excess = round((float) $line->accepted_qty - $before, 4);
                if ($excess > 1e-6) {
                    $within = $excess <= $ordered * $overPct / 100 + 1e-6;
                    $found[] = [$line->id, 'OVER', $before, (float) $line->accepted_qty, $within,
                        "{$excess} more than was still outstanding on the order".($within ? " (within the {$overPct}% tolerance)" : '')];
                }
            }
            if ((float) $line->damaged_qty > 0) {
                $found[] = [$line->id, 'DAMAGED', 0, (float) $line->damaged_qty, false, "{$line->damaged_qty} arrived damaged"];
            }
            $rejectedNotDamaged = (float) $line->rejected_qty - (float) $line->damaged_qty;
            if ($rejectedNotDamaged > 1e-6) {
                $found[] = [$line->id, 'REJECTED', 0, $rejectedNotDamaged, false, "{$rejectedNotDamaged} rejected at receipt"];
            }
            if ((float) $line->missing_qty > 0) {
                $found[] = [$line->id, 'MISSING', (float) $line->received_qty + (float) $line->missing_qty, (float) $line->received_qty, false,
                    "{$line->missing_qty} on the delivery note did not arrive"];
            }
            if (isset($shortShelfLife[$line->id])) {
                $found[] = [$line->id, 'SHORT_SHELF_LIFE', null, $shortShelfLife[$line->id], false,
                    "only {$shortShelfLife[$line->id]} days of shelf life left; held in quarantine"];
            }
        }

        if ($found === []) {
            $grn->update(['discrepancy_status' => 'NONE']);

            return;
        }

        DB::transaction(function () use ($grn, $found) {
            foreach ($found as [$itemId, $kind, $expected, $actual, $within, $detail]) {
                ReceiptDiscrepancy::create([
                    'company_id' => $grn->company_id, 'grn_id' => $grn->id, 'grn_item_id' => $itemId, 'kind' => $kind,
                    'expected' => $expected, 'actual' => $actual, 'within_tolerance' => $within, 'detail' => $detail,
                    'status' => $within ? 'ACCEPTED' : 'OPEN', 'resolution' => $within ? 'Within tolerance' : null,
                    'resolved_at' => $within ? now() : null,
                ]);
            }
            $this->refreshStatus($grn);
        });

        $open = collect($found)->filter(fn ($f) => ! $f[4]);
        if ($open->isNotEmpty()) {
            $this->notify($grn, $open->map(fn ($f) => "{$f[1]}: {$f[5]}")->values()->all());
        }
    }

    public function resolve(ReceiptDiscrepancy $d, User $user, string $decision, string $resolution): ReceiptDiscrepancy
    {
        if (! $user->can(RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS)) {
            throw new RuntimeException('Only somebody allowed to decide receiving exceptions can settle this.');
        }
        if ($d->status !== 'OPEN') {
            throw new RuntimeException('That discrepancy is already settled.');
        }
        if (trim($resolution) === '') {
            throw new RuntimeException('Record how it was settled: credit requested, replacement due, return raised, accepted as is.');
        }

        $d->update([
            'status' => $decision === 'REJECT' ? 'REJECTED' : 'RESOLVED', 'resolution' => $resolution,
            'resolved_by' => $user->id, 'resolved_at' => now(),
        ]);
        $this->refreshStatus($d->grn);

        return $d;
    }

    private function refreshStatus(GoodsReceivedNote $grn): void
    {
        $open = ReceiptDiscrepancy::query()->where('grn_id', $grn->id)->where('status', 'OPEN')->exists();
        $any = ReceiptDiscrepancy::query()->where('grn_id', $grn->id)->exists();
        $grn->update(['discrepancy_status' => $open ? 'OPEN' : ($any ? 'RESOLVED' : 'NONE')]);
    }

    private function notify(GoodsReceivedNote $grn, array $lines): void
    {
        $po = $grn->purchaseOrder;
        $supplier = DB::table('suppliers')->where('id', $po?->supplier_id ?? $grn->supplier_id)->value('company_name') ?? 'Unknown supplier';
        // Through the notifier: the receiving-exceptions approvers by default, and whoever raised the order (WMS-190).
        app(WarehouseNotifier::class)->raise((int) $grn->company_id, 'DISCREPANCY', [['key' => "GRN:{$grn->id}", 'warehouse_id' => $grn->warehouse_id ? (int) $grn->warehouse_id : null,
            'responsible' => array_filter([$po?->created_by, $grn->received_by]), 'title' => "Receiving discrepancy on {$grn->grn_number}",
            'message' => $supplier.($po?->po_number ? " · PO {$po->po_number}" : '').': '.implode('; ', $lines), 'url' => '/warehouse/inbound/receipts?discrepancy=1']]);
        ReceiptDiscrepancy::query()->where('grn_id', $grn->id)->where('status', 'OPEN')->update(['notified_at' => now()]);
    }
}
