<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\PutawayTask;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Put-away tasks (WMS-040/041): created when stock lands somewhere it does not live, completed by
 * a scan-verified move through the ledger.
 *
 * Completion is the ledger's bin-to-bin transfer, so a put-away is a movement with a source, never
 * a field changed on a position. A destination the advisor would have rejected is refused here too,
 * whoever chose it; a directed warehouse also refuses any bin but the suggested one unless the
 * person may override.
 */
class PutawayTasks
{
    public function __construct(
        private readonly PutawayAdvisor $advisor,
        private readonly StockLedger $ledger,
        private readonly BarcodeRegistry $barcodes,
        private readonly WarehouseDirectory $directory,
    ) {}

    public function create(FinWarehouse $warehouse, FinStockItem $item, float $qty, array $attrs = []): PutawayTask
    {
        $status = $attrs['stock_status'] ?? FinStockPosition::AVAILABLE;
        $suggestion = $this->advisor->suggest($warehouse, $item, $qty, $attrs['lot_id'] ?? null, $status, $attrs['from_bin_id'] ?? null);

        return PutawayTask::create([
            'company_id' => $item->company_id, 'number' => $this->nextNumber((int) $item->company_id),
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'lot_id' => $attrs['lot_id'] ?? null,
            'stock_status' => $status, 'from_bin_id' => $attrs['from_bin_id'] ?? null,
            'suggested_bin_id' => $suggestion['bin']?->id, 'qty' => $qty, 'status' => PutawayTask::OPEN,
            'priority' => $attrs['priority'] ?? 50, 'source_type' => $attrs['source_type'] ?? null, 'source_id' => $attrs['source_id'] ?? null,
            'suggestion_reasons' => ['chosen' => $suggestion['reasons'], 'rejected' => $suggestion['rejected']],
        ]);
    }

    public function start(PutawayTask $task, User $user): PutawayTask
    {
        $this->assertOpen($task);
        $this->assertPermitted($task, $user);
        $task->update(['status' => PutawayTask::IN_PROGRESS, 'assigned_to' => $user->id, 'started_at' => $task->started_at ?? now()]);

        return $task;
    }

    /**
     * Put away all or part of the task's quantity into $bin.
     *
     * @param  array{item_scan?:?string, bin_scan?:?string}  $scans
     */
    public function complete(PutawayTask $task, FinStockBin $bin, float $qty, User $user, array $scans = []): PutawayTask
    {
        $this->assertOpen($task);
        $this->assertPermitted($task, $user);
        $remaining = round((float) $task->qty - (float) $task->done_qty, 4);
        if ($qty <= 0 || $qty > $remaining + 1e-6) {
            throw new RuntimeException("Put away between 0 and {$remaining}.");
        }
        if ($bin->warehouse_id !== $task->warehouse_id) {
            throw new RuntimeException('That bin is in another warehouse.');
        }

        $warehouse = $task->warehouse;
        $item = FinStockItem::query()->findOrFail($task->item_id);
        $this->verifyScans($warehouse, $item, $bin, $scans);

        $policy = $warehouse->policies['putaway'] ?? 'SUGGESTED';
        if ($policy === 'DIRECTED' && $task->suggested_bin_id && $bin->id !== $task->suggested_bin_id && ! $user->can(RoleCatalog::OVERRIDE_PUTAWAY)) {
            throw new RuntimeException("This warehouse directs put-away: use {$task->suggestedBin?->code}, or ask a supervisor to override.");
        }
        if (($why = $this->advisor->refusalFor($bin, $item, $qty, $task->lot_id, $task->stock_status)) !== null) {
            throw new RuntimeException("{$bin->code} cannot take this: {$why}.");
        }

        return DB::transaction(function () use ($task, $bin, $qty, $user, $warehouse, $item, $remaining) {
            $this->ledger->transfer($item, $warehouse, $warehouse, $qty, [
                'bin_id' => $task->from_bin_id, 'to_bin_id' => $bin->id, 'lot_id' => $task->lot_id, 'status' => $task->stock_status,
                'source_type' => 'PUTAWAY', 'source_id' => $task->id, 'reference' => $task->number,
                'idempotency_key' => "putaway:{$task->id}:".round((float) $task->done_qty + $qty, 4),
            ]);
            $done = round((float) $task->done_qty + $qty, 4);
            $task->update([
                'done_qty' => $done, 'to_bin_id' => $bin->id, 'completed_by' => $user->id,
                'status' => $done >= (float) $task->qty - 1e-6 ? PutawayTask::DONE : PutawayTask::PARTIAL,
                'completed_at' => $done >= (float) $task->qty - 1e-6 ? now() : null,
            ]);

            return $task->fresh();
        });
    }

    /** Could not be done: the bin was full, the pallet damaged, the stock not where it should be. */
    public function fail(PutawayTask $task, User $user, string $reason): PutawayTask
    {
        $this->assertOpen($task);
        if (trim($reason) === '') {
            throw new RuntimeException('Say what went wrong, so the exception queue can fix it.');
        }
        $task->update(['status' => PutawayTask::FAILED, 'exception_note' => $reason, 'completed_by' => $user->id]);

        return $task;
    }

    public function cancel(PutawayTask $task, User $user, string $reason): PutawayTask
    {
        if (in_array($task->status, [PutawayTask::DONE, PutawayTask::CANCELLED], true)) {
            throw new RuntimeException("{$task->number} is already ".strtolower($task->status).'.');
        }
        $task->update(['status' => PutawayTask::CANCELLED, 'exception_note' => trim("Cancelled: {$reason}"), 'completed_by' => $user->id]);

        return $task;
    }

    /** Re-run the advisor -- after a bin is blocked, say. */
    public function resuggest(PutawayTask $task): PutawayTask
    {
        $s = $this->advisor->suggest($task->warehouse, $task->item, (float) $task->qty - (float) $task->done_qty, $task->lot_id, $task->stock_status, $task->from_bin_id);
        $task->update(['suggested_bin_id' => $s['bin']?->id, 'suggestion_reasons' => ['chosen' => $s['reasons'], 'rejected' => $s['rejected']]]);

        return $task;
    }

    private function verifyScans(FinWarehouse $warehouse, FinStockItem $item, FinStockBin $bin, array $scans): void
    {
        $required = (bool) ($warehouse->policies['scan_to_confirm'] ?? true);
        if (! $required && empty($scans['item_scan']) && empty($scans['bin_scan'])) {
            return;
        }
        if ($required && (empty($scans['item_scan']) || empty($scans['bin_scan']))) {
            throw new RuntimeException('This warehouse confirms every task by scan: scan the item and the destination bin.');
        }
        if (! empty($scans['item_scan'])) {
            try {
                $resolved = $this->barcodes->resolve((string) $scans['item_scan']);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException('Item scan: '.$e->getMessage());
            }
            if ($resolved['item']->id !== $item->id) {
                throw new RuntimeException("The scanned item is {$resolved['item']->code}, not {$item->code}.");
            }
        }
        if (! empty($scans['bin_scan']) && ! in_array(trim((string) $scans['bin_scan']), array_filter([$bin->barcode, $bin->code]), true)) {
            throw new RuntimeException("The scanned bin is not {$bin->code}.");
        }
    }

    private function assertOpen(PutawayTask $task): void
    {
        if (! in_array($task->status, [PutawayTask::OPEN, PutawayTask::IN_PROGRESS, PutawayTask::PARTIAL], true)) {
            throw new RuntimeException("{$task->number} is ".strtolower($task->status).'.');
        }
    }

    private function assertPermitted(PutawayTask $task, User $user): void
    {
        if (! $this->directory->permits($task->warehouse, $user->id, 'PUTAWAY')) {
            throw new RuntimeException('You are not assigned to put away stock in this warehouse.');
        }
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-putaway-number:'.$companyId)]);
        $prefix = 'PUT-'.now()->format('Y').'-';
        $last = PutawayTask::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
    }
}
