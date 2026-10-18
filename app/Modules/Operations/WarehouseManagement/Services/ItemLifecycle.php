<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * An item's lifecycle status (WMS-013). The rules live on the item itself and are applied by the
 * ledger to every movement; this records the decision, why, and -- for a recall or quarantine --
 * optionally moves every usable unit into quarantine in the same transaction, so there is no
 * moment in which the status says "recalled" and a picker can still find available stock.
 *
 * History is never touched: a discontinued item keeps every past movement, and an order line that
 * named it still names it.
 */
class ItemLifecycle
{
    public function __construct(private readonly ItemCatalogue $catalogue, private readonly StockLedger $ledger) {}

    /** @return int units moved into quarantine */
    public function change(FinStockItem $item, string $status, string $reason, bool $quarantineStock = false): int
    {
        $status = strtoupper($status);
        if (! in_array($status, FinStockItem::STATUSES, true)) {
            throw new RuntimeException("Unknown item status {$status}.");
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why the status is changing.');
        }
        if ($status === $item->status) {
            throw new RuntimeException("{$item->code} is already ".strtolower($status).'.');
        }

        return DB::transaction(function () use ($item, $status, $reason, $quarantineStock) {
            $moved = 0.0;
            if ($quarantineStock && in_array($status, [FinStockItem::RECALLED, FinStockItem::QUARANTINED], true)) {
                $positions = FinStockPosition::query()->where('item_id', $item->id)
                    ->where('status', FinStockPosition::AVAILABLE)->where('qty', '>', 0)->lockForUpdate()->get();
                foreach ($positions as $p) {
                    $this->ledger->changeStatus($item->fresh(), FinWarehouse::query()->findOrFail($p->warehouse_id), (float) $p->qty,
                        FinStockPosition::AVAILABLE, FinStockPosition::QUARANTINE, [
                            'bin_id' => $p->bin_id, 'lot_id' => $p->lot_id, 'reason' => "Item {$status}: {$reason}",
                            'reason_code' => 'ITEM_'.$status, 'source_type' => 'wms_item_status', 'source_id' => $item->id,
                        ]);
                    $moved += (float) $p->qty;
                }
            }

            $before = $item->status;
            $item->forceFill(['status' => $status, 'status_reason' => $reason, 'status_changed_at' => now()])->save();
            $this->catalogue->event($item, 'STATUS', ['status' => [$before, $status]] + ($moved > 0 ? ['quarantined_qty' => [0, $moved]] : []), $reason);

            return (int) round($moved);
        });
    }
}
