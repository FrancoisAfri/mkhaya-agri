<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturnLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Quality control where the warehouse needs it (WMS-082), on Manufacturing's inspections: the
 * plans, checks, samples, defect codes, results and dispositions are the same records; what is
 * added is stock checked in its bin, returns, and orders checked before they leave.
 *
 * Warehouse inspections carry no held status of their own -- the warehouse holds the stock in the
 * bin it is in -- so when one is decided, this moves the stock (`decided`).
 */
class WarehouseQuality
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly QualityControl $quality,
        private readonly PutawayTasks $putaway,
    ) {}

    /** Suspect stock on the shelf: put it on hold where it is and open its inspection. */
    public function hold(FinWarehouse $warehouse, FinStockItem $item, ?FinStockBin $bin, ?int $lotId, float $qty, string $reason, User $by): Inspection
    {
        if (blank($reason)) {
            throw new RuntimeException('Say why the stock is being held for inspection.');
        }
        if ($qty <= 0) {
            throw new RuntimeException('Hold a quantity greater than zero.');
        }

        return DB::transaction(function () use ($warehouse, $item, $bin, $lotId, $qty, $reason, $by) {
            $movement = $this->ledger->changeStatus($item, $warehouse, $qty, FinStockPosition::AVAILABLE, FinStockPosition::ON_HOLD, [
                'bin_id' => $bin?->id, 'lot_id' => $lotId, 'reason' => "Held for inspection: {$reason}", 'reason_code' => 'QUALITY',
                'source_type' => 'WMS_STORAGE',
            ]);

            return $this->quality->openInWarehouse([
                'stage' => InspectionPlan::STORAGE, 'stock_item' => $item, 'warehouse' => $warehouse, 'qty' => $qty, 'lot_id' => $lotId,
                'bin_id' => $bin?->id, 'source_type' => 'WMS_STORAGE', 'source_id' => $movement->id, 'notes' => $reason,
            ], $by);
        });
    }

    /**
     * Open the before-dispatch inspections an order needs: one per item that has an active
     * dispatch plan. Already-open ones are not duplicated.
     *
     * @return list<Inspection>
     */
    public function inspectOrder(FulfilmentOrder $order, User $by): array
    {
        $opened = [];
        foreach ($order->lines()->with('item')->get()->groupBy('item_id') as $itemId => $lines) {
            $item = $lines->first()->item;
            if (! $this->dispatchPlanFor($item)) {
                continue;
            }
            $exists = Inspection::query()->where('source_type', 'WMS_ORDER')->where('source_id', $order->id)
                ->where('stock_item_id', $itemId)->where(fn ($q) => $q->whereNull('disposition')->orWhereIn('disposition', ['ACCEPT', 'CONDITIONAL_ACCEPT']))->exists();
            if ($exists) {
                continue;
            }
            $opened[] = $this->quality->openInWarehouse([
                'stage' => InspectionPlan::DISPATCH, 'stock_item' => $item, 'warehouse' => $order->warehouse,
                'qty' => (float) $lines->sum(fn ($l) => (float) $l->qty_picked ?: (float) $l->qty), 'source_type' => 'WMS_ORDER',
                'source_id' => $order->id, 'reference' => $order->number,
            ], $by);
        }

        return $opened;
    }

    /** Why the order may not leave yet, or null: every item with a dispatch plan needs an accepted inspection. */
    public function dispatchRefusal(FulfilmentOrder $order): ?string
    {
        $missing = [];
        foreach ($order->lines()->with('item')->get()->unique('item_id') as $line) {
            if (! $this->dispatchPlanFor($line->item)) {
                continue;
            }
            $passed = Inspection::query()->where('source_type', 'WMS_ORDER')->where('source_id', $order->id)
                ->where('stock_item_id', $line->item_id)->whereIn('disposition', ['ACCEPT', 'CONDITIONAL_ACCEPT'])->exists();
            if (! $passed) {
                $missing[] = $line->item->code;
            }
        }

        return $missing ? 'These items must pass their dispatch inspection first: '.implode(', ', $missing).'.' : null;
    }

    /** An inspection the warehouse opened has been decided: carry the decision out on the stock. */
    public function decided(Inspection $inspection): void
    {
        match ($inspection->source_type) {
            'WMS_RETURN' => ($line = CustomerReturnLine::query()->find($inspection->source_id))
                && (int) $line->inspection_id === (int) $inspection->id
                && app(CustomerReturns::class)->applyDecision($line, $inspection->disposition, $inspection->disposition_reason),
            'WMS_STORAGE' => $this->applyStorageDecision($inspection),
            default => null, // a dispatch inspection moves nothing; Dispatching reads its outcome
        };
    }

    private function applyStorageDecision(Inspection $inspection): void
    {
        $item = FinStockItem::query()->findOrFail($inspection->stock_item_id);
        $warehouse = FinWarehouse::query()->findOrFail($inspection->warehouse_id);
        $qty = (float) $inspection->qty;
        $opts = ['bin_id' => $inspection->bin_id, 'lot_id' => $inspection->lot_id, 'source_type' => 'WMS_STORAGE', 'source_id' => $inspection->source_id,
            'reason' => trim("{$inspection->number} {$inspection->disposition}".($inspection->disposition_reason ? ": {$inspection->disposition_reason}" : '')),
            'idempotency_key' => "wms-inspection-{$inspection->id}-{$inspection->disposition}"];

        match ($inspection->disposition) {
            'ACCEPT', 'CONDITIONAL_ACCEPT' => $this->ledger->changeStatus($item, $warehouse, $qty, FinStockPosition::ON_HOLD, FinStockPosition::AVAILABLE, $opts),
            'REJECT', 'RETURN_TO_SUPPLIER' => $this->ledger->changeStatus($item, $warehouse, $qty, FinStockPosition::ON_HOLD, FinStockPosition::REJECTED, $opts),
            'SCRAP' => $this->ledger->scrap($item, $qty, $warehouse, $opts + ['status' => FinStockPosition::ON_HOLD, 'reference' => $inspection->number]),
            default => null,
        };
    }

    private function dispatchPlanFor(FinStockItem $item): ?InspectionPlan
    {
        return InspectionPlan::query()->where('stage', InspectionPlan::DISPATCH)->where('is_active', true)
            ->where(fn ($q) => $q->where('stock_item_id', $item->id)->orWhereNull('stock_item_id'))->first();
    }
}
