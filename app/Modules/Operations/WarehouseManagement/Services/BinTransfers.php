<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use InvalidArgumentException;
use RuntimeException;

/**
 * WMS-050: moving stock from one bin to another in the same warehouse.
 *
 * The move is the ledger's transfer, carrying the lot -- and a serial number is a lot of one -- so
 * traceability survives every move. The destination answers to the same rules as a put-away;
 * scanning is required when the warehouse says so.
 */
class BinTransfers
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly PutawayAdvisor $advisor,
        private readonly BarcodeRegistry $barcodes,
        private readonly WarehouseDirectory $directory,
    ) {}

    /** @param array{from_scan?:?string, item_scan?:?string, to_scan?:?string} $scans */
    /**
     * $intoPickFace: a replenishment into a configured pick face. A pick face is fed from several
     * lots by design -- picking takes the first-expiring one -- so the storage rule that keeps lots
     * apart does not apply to it; every other rule does.
     */
    public function move(FinWarehouse $warehouse, FinStockItem $item, ?FinStockBin $from, FinStockBin $to, float $qty, ?int $lotId, string $status, User $user, array $scans = [], ?string $reason = null, bool $intoPickFace = false): FinStockMovement
    {
        if ($qty <= 0) {
            throw new RuntimeException('Move a quantity above zero.');
        }
        if ($from?->id === $to->id) {
            throw new RuntimeException('Choose a different destination.');
        }
        if ($to->warehouse_id !== $warehouse->id || ($from && $from->warehouse_id !== $warehouse->id)) {
            throw new RuntimeException('Both bins must be in this warehouse; use a transfer order between warehouses.');
        }
        if (! $this->directory->permits($warehouse, $user->id, 'PUTAWAY')) {
            throw new RuntimeException('You are not assigned to move stock in this warehouse.');
        }

        $there = (float) FinStockPosition::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->where('status', $status)
            ->when($from, fn ($q) => $q->where('bin_id', $from->id), fn ($q) => $q->whereNull('bin_id'))
            ->when($lotId, fn ($q) => $q->where('lot_id', $lotId), fn ($q) => $q->whereNull('lot_id'))->value('qty');
        if ($qty > $there + 1e-6) {
            throw new RuntimeException("Only {$there} {$item->uom} of {$item->code} is in ".($from?->code ?? 'no bin').' in that state.');
        }

        $this->verifyScans($warehouse, $item, $from, $to, $scans);
        if (($why = $this->advisor->refusalFor($to, $item, $qty, $lotId, $status, ! $intoPickFace)) !== null) {
            throw new RuntimeException("{$to->code} cannot take this: {$why}.");
        }

        return $this->ledger->transfer($item, $warehouse, $warehouse, $qty, [
            'bin_id' => $from?->id, 'to_bin_id' => $to->id, 'lot_id' => $lotId, 'status' => $status,
            'source_type' => 'BIN_MOVE', 'source_id' => $user->id, 'reference' => $reason ?: 'Bin move',
        ]);
    }

    private function verifyScans(FinWarehouse $warehouse, FinStockItem $item, ?FinStockBin $from, FinStockBin $to, array $scans): void
    {
        $required = (bool) ($warehouse->policies['scan_to_confirm'] ?? true);
        if (! $required && array_filter($scans) === []) {
            return;
        }
        if ($required && (empty($scans['item_scan']) || empty($scans['to_scan']))) {
            throw new RuntimeException('This warehouse confirms moves by scan: scan the item and the destination bin.');
        }
        if (! empty($scans['item_scan'])) {
            try {
                $r = $this->barcodes->resolve((string) $scans['item_scan']);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException('Item scan: '.$e->getMessage());
            }
            if ($r['item']->id !== $item->id) {
                throw new RuntimeException("The scanned item is {$r['item']->code}, not {$item->code}.");
            }
        }
        foreach ([['from_scan', $from], ['to_scan', $to]] as [$key, $bin]) {
            if (! empty($scans[$key]) && $bin && ! in_array(trim((string) $scans[$key]), array_filter([$bin->barcode, $bin->code]), true)) {
                throw new RuntimeException("The scanned bin is not {$bin->code}.");
            }
        }
    }
}
