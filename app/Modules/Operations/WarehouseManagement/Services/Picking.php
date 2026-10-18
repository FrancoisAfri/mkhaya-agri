<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Allocation;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\PickTask;
use App\Modules\Operations\WarehouseManagement\Models\PickWave;
use App\Modules\Operations\WarehouseManagement\Support\Gs1Parser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Picking (WMS-062/063): turning allocations into a walk through the warehouse, and each step of
 * the walk into a scan-checked move to the packing area.
 *
 * Strategies differ only in how the work is grouped and ordered:
 *  - DISCRETE: one order, one picker;
 *  - BATCH / WAVE: many orders, one pass through the aisles in bin order;
 *  - ZONE: the same work, split so each picker stays in one zone;
 *  - CLUSTER: one picker, several orders at once, each in its own tote slot.
 * The pick itself is the same in every case: the bin, the item and (for a lot- or serial-tracked
 * item) the lot are scanned and must match; stock is moved, never just ticked off; anything not
 * there is recorded as short, released, and looked for elsewhere.
 */
class Picking
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly BarcodeRegistry $barcodes,
        private readonly FulfilmentOrders $orders,
        private readonly Allocator $allocator,
        private readonly WarehouseDirectory $directory,
        private readonly Counting $counting,
    ) {}

    /** @param Collection<int,FulfilmentOrder> $orders @return list<PickWave> */
    public function release(FinWarehouse $warehouse, Collection $orders, string $strategy, ?User $assignee = null): array
    {
        if (! in_array($strategy, PickWave::STRATEGIES, true)) {
            throw new RuntimeException("Unknown picking strategy {$strategy}.");
        }
        $orders = $orders->filter(fn ($o) => in_array($o->status, ['ALLOCATED', 'PARTIALLY_ALLOCATED', 'PICKING'], true) && $o->warehouse_id === $warehouse->id)->values();
        if ($orders->isEmpty()) {
            throw new RuntimeException('None of these orders has allocated stock waiting to be picked.');
        }

        return DB::transaction(function () use ($warehouse, $orders, $strategy, $assignee) {
            $work = $this->openWork($orders);
            if ($work->isEmpty()) {
                throw new RuntimeException('Everything allocated to these orders is already on a pick list.');
            }

            $groups = match ($strategy) {
                'DISCRETE' => $work->groupBy(fn ($w) => 'order:'.$w['order']->id),
                'ZONE' => $work->groupBy(fn ($w) => 'zone:'.($w['zone'] ?? '-')),
                default => collect(['all' => $work]),
            };

            $waves = [];
            foreach ($groups as $key => $items) {
                $wave = PickWave::create([
                    'company_id' => $warehouse->company_id, 'number' => $this->nextNumber((int) $warehouse->company_id),
                    'warehouse_id' => $warehouse->id, 'strategy' => $strategy, 'status' => 'OPEN',
                    'zone' => $strategy === 'ZONE' ? substr((string) $key, 5) : null, 'assigned_to' => $assignee?->id,
                ]);
                $slots = $strategy === 'CLUSTER' ? $items->pluck('order.id')->unique()->values()->flip()->map(fn ($i) => $i + 1) : collect();

                $seq = 0;
                foreach ($items->sortBy(fn ($w) => sprintf('%s|%08d|%s', $w['zone'] ?? 'ZZZ', $w['bin']?->pick_sequence ?? 99999999, $w['bin']?->code ?? '~')) as $w) {
                    $a = $w['allocation'];
                    PickTask::create([
                        'company_id' => $warehouse->company_id, 'wave_id' => $wave->id, 'order_id' => $w['order']->id,
                        'order_line_id' => $a->order_line_id, 'allocation_id' => $a->id, 'item_id' => $w['line']->item_id,
                        'lot_id' => $a->lot_id, 'bin_id' => $a->bin_id, 'to_bin_id' => $this->orders->packingBin($warehouse)->id,
                        'qty' => $w['qty'], 'sequence' => ++$seq, 'zone' => $w['zone'],
                        'cluster_slot' => $slots[$w['order']->id] ?? null, 'status' => 'OPEN',
                    ]);
                }
                $waves[] = $wave;
            }

            return $waves;
        });
    }

    /**
     * Confirm a pick.
     *
     * @param  array{bin_scan?:?string, item_scan?:?string, lot_scan?:?string}  $scans
     * @param  bool  $short  the picker found less than the task asked for and the rest is not there
     */
    public function confirm(PickTask $task, float $qty, User $user, array $scans = [], bool $short = false, ?string $note = null): PickTask
    {
        if ($task->status !== 'OPEN') {
            throw new RuntimeException('That pick is already '.strtolower($task->status).'.');
        }
        $remaining = round((float) $task->qty - (float) $task->picked_qty - (float) $task->short_qty, 4);
        if ($qty < 0 || $qty > $remaining + 1e-6) {
            throw new RuntimeException("Pick between 0 and {$remaining}.");
        }
        if ($qty <= 1e-6 && ! $short) {
            throw new RuntimeException('Nothing picked: report it as short if the stock is not there.');
        }
        $warehouse = $task->order->warehouse;
        if (! $this->directory->permits($warehouse, $user->id, 'PICK')) {
            throw new RuntimeException('You are not assigned to pick in this warehouse.');
        }
        $item = FinStockItem::query()->findOrFail($task->item_id);

        try {
            $this->verifyScans($warehouse, $task, $item, $scans, $qty);
        } catch (RuntimeException $e) {
            $task->increment('scan_errors');

            throw $e;
        }

        return DB::transaction(function () use ($task, $qty, $user, $short, $note, $warehouse, $item, $remaining) {
            $order = $task->order;
            if ($qty > 0) {
                $this->ledger->transfer($item, $warehouse, $warehouse, $qty, [
                    'bin_id' => $task->bin_id, 'to_bin_id' => $task->to_bin_id, 'lot_id' => $task->lot_id,
                    'status' => FinStockPosition::AVAILABLE, 'source_type' => 'WMS_PICK', 'source_id' => $task->id,
                    'reference' => $order->number, 'idempotency_key' => "pick:{$task->id}:".round((float) $task->picked_qty + $qty, 4),
                ]);
            }
            $missing = $short ? round($remaining - $qty, 4) : 0.0;
            $picked = round((float) $task->picked_qty + $qty, 4);
            $task->update([
                'picked_qty' => $picked, 'short_qty' => round((float) $task->short_qty + $missing, 4),
                'status' => $picked + (float) $task->short_qty + $missing >= (float) $task->qty - 1e-6 ? ($missing > 0 ? 'SHORT' : 'PICKED') : 'OPEN',
                'picked_by' => $user->id, 'started_at' => $task->started_at ?? now(), 'completed_at' => now(),
                'note' => $note ?? $task->note,
            ]);

            $a = $task->allocation;
            $a->update(['qty_picked' => round((float) $a->qty_picked + $qty, 4)]);
            $line = $task->line;
            $line->update(['qty_picked' => round((float) $line->qty_picked + $qty, 4)]);

            if ($missing > 0) {
                $this->shortPick($a, $missing, $note);
            }
            if ($order->pick_started_at === null) {
                $order->update(['pick_started_at' => now(), 'status' => 'PICKING']);
            } elseif (in_array($order->status, ['ALLOCATED', 'PARTIALLY_ALLOCATED'], true)) {
                $order->update(['status' => 'PICKING']);
            }
            $this->refreshOrder($order->fresh());
            $this->refreshWave($task->wave);

            return $task->fresh();
        });
    }

    /** Stock the allocation counted on was not there: hold only what was found, and look elsewhere. */
    private function shortPick(Allocation $a, float $missing, ?string $note): void
    {
        $keep = round((float) $a->qty - $missing, 4);
        $line = $a->line;
        $order = $line->order;
        if ($a->reservation_id) {
            if ($keep - (float) $a->qty_picked <= 1e-6) {
                $this->ledger->releaseFor('WMS_ALLOCATION', $a->id, 'Short pick: '.($note ?: 'not in the bin'));
            } else {
                $this->ledger->reserve($line->item, $order->warehouse, round($keep - (float) $a->qty_picked, 4), 'WMS_ALLOCATION', $a->id, $a->lot_id);
            }
        }
        $a->update(['qty' => max($keep, (float) $a->qty_picked, 0.0001), 'status' => $keep <= (float) $a->qty_picked + 1e-6 ? 'PICKED' : 'ACTIVE']);

        // The books say the bin holds stock the picker could not find. Put that quantity on hold
        // pending a count, so it is neither promised again nor quietly forgotten.
        $there = (float) FinStockPosition::query()->where('item_id', $line->item_id)->where('warehouse_id', $order->warehouse_id)
            ->where('status', FinStockPosition::AVAILABLE)
            ->when($a->bin_id, fn ($q, $v) => $q->where('bin_id', $v), fn ($q) => $q->whereNull('bin_id'))
            ->when($a->lot_id, fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'))->value('qty');
        $hold = round(min($missing, $there), 4);
        if ($hold > 0) {
            $this->ledger->changeStatus($line->item, $order->warehouse, $hold, FinStockPosition::AVAILABLE, FinStockPosition::ON_HOLD, [
                'bin_id' => $a->bin_id, 'lot_id' => $a->lot_id, 'reason' => 'Short pick: count the bin. '.($note ?? ''),
                'reason_code' => 'SHORT_PICK', 'source_type' => 'WMS_PICK', 'source_id' => $a->id,
            ]);
        }
        if ($a->bin_id && ($bin = FinStockBin::query()->find($a->bin_id))) {
            $this->counting->countOnEvent($bin, $line->item, 'SHORT_PICK', "Short pick on order {$order->number}: ".rtrim(rtrim(number_format($missing, 4, '.', ''), '0'), '.').' not found');
        }
        $line->update([
            'qty_allocated' => round(max(0.0, (float) $line->qty_allocated - $missing), 4),
            'qty_short' => round((float) $line->qty_short + $missing, 4),
        ]);
        // The shortfall goes back into the queue and is allocated from wherever it can be found.
        if (! in_array($order->status, ['CANCELLED'], true)) {
            $order->update(['status' => 'PARTIALLY_ALLOCATED']);
            $this->allocator->allocate($order->fresh());
        }
    }

    private function verifyScans(FinWarehouse $warehouse, PickTask $task, FinStockItem $item, array $scans, float $qty): void
    {
        $required = (bool) ($warehouse->policies['scan_to_confirm'] ?? true);
        if (! $required && array_filter($scans) === []) {
            return;
        }
        if ($required && $qty > 0 && (empty($scans['bin_scan']) || empty($scans['item_scan']))) {
            throw new RuntimeException('This warehouse confirms every pick by scan: scan the bin and the item.');
        }
        if (! empty($scans['bin_scan']) && $task->bin_id) {
            $bin = FinStockBin::query()->find($task->bin_id);
            if (! in_array(trim((string) $scans['bin_scan']), array_filter([$bin?->barcode, $bin?->code]), true)) {
                throw new RuntimeException("Wrong bin: this pick is from {$bin?->code}.");
            }
        }
        $lotFromScan = null;
        if (! empty($scans['item_scan'])) {
            try {
                $r = $this->barcodes->resolve((string) $scans['item_scan']);
            } catch (InvalidArgumentException $e) {
                throw new RuntimeException('Item scan: '.$e->getMessage());
            }
            if ($r['item']->id !== $item->id) {
                throw new RuntimeException("Wrong item: scanned {$r['item']->code}, the pick is {$item->code}. Substitutes are allocated, never picked in place.");
            }
            $lotFromScan = $r['serial'] ?? $r['batch'];
        }
        if ($task->lot_id) {
            $lot = FinStockLot::query()->find($task->lot_id);
            $given = trim((string) ($scans['lot_scan'] ?? $lotFromScan ?? ''));
            if ($required && $given === '') {
                throw new RuntimeException("Scan or enter the lot ({$lot?->lot_number}) -- this item is traced by lot.");
            }
            if ($given !== '' && Gs1Parser::looksLikeGs1($given)) {
                $p = (new Gs1Parser)->parse($given);
                $given = (string) ($p['serial'] ?? $p['batch'] ?? '');
            }
            if ($given !== '' && $given !== $lot?->lot_number) {
                throw new RuntimeException("Wrong lot: scanned {$given}, the pick is lot {$lot?->lot_number}.");
            }
        }
    }

    /** @return Collection<int,array{order:FulfilmentOrder,line:mixed,allocation:Allocation,bin:?FinStockBin,zone:?string,qty:float}> */
    private function openWork(Collection $orders): Collection
    {
        $onList = PickTask::query()->whereIn('order_id', $orders->pluck('id'))->where('status', 'OPEN')
            ->groupBy('allocation_id')->selectRaw('allocation_id, SUM(qty - picked_qty - short_qty) AS q')->pluck('q', 'allocation_id');
        $zones = [];
        $out = collect();
        foreach ($orders as $order) {
            foreach ($order->lines()->with(['allocations' => fn ($q) => $q->where('status', 'ACTIVE')->with('bin')])->get() as $line) {
                foreach ($line->allocations as $a) {
                    $qty = round((float) $a->qty - (float) $a->qty_picked - (float) ($onList[$a->id] ?? 0), 4);
                    if ($qty <= 1e-6) {
                        continue;
                    }
                    $out->push(['order' => $order, 'line' => $line, 'allocation' => $a, 'bin' => $a->bin, 'qty' => $qty,
                        'zone' => $a->bin ? ($zones[$a->bin->id] ??= $this->zoneOf($a->bin)) : null]);
                }
            }
        }

        return $out;
    }

    /** The outermost ZONE above a bin, or its own code when the floor has no zones. */
    private function zoneOf(FinStockBin $bin): ?string
    {
        $zone = null;
        for ($at = $bin, $d = 0; $at && $d < 6; $at = $at->parent, $d++) {
            if ($at->level === 'ZONE') {
                $zone = $at->code;
            }
        }

        return $zone;
    }

    private function refreshOrder(FulfilmentOrder $order): void
    {
        $open = PickTask::query()->where('order_id', $order->id)->where('status', 'OPEN')->exists();
        $unpicked = $order->lines()->get()->sum(fn ($l) => max(0.0, (float) $l->qty_allocated - (float) $l->qty_picked));
        $wanted = $order->lines()->get()->sum(fn ($l) => max(0.0, (float) $l->qty - (float) $l->qty_cancelled - (float) $l->qty_picked));
        if (! $open && $unpicked <= 1e-6 && $wanted <= 1e-6) {
            $order->update(['status' => 'PICKED', 'picked_at' => now()]);
        }
    }

    private function refreshWave(?PickWave $wave): void
    {
        if (! $wave) {
            return;
        }
        $open = $wave->tasks()->where('status', 'OPEN')->exists();
        $wave->update(['status' => $open ? 'IN_PROGRESS' : 'DONE', 'started_at' => $wave->started_at ?? now(), 'completed_at' => $open ? null : now()]);
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-wave-number:'.$companyId)]);
        $prefix = 'WAVE-'.now()->format('Y').'-';
        $last = PickWave::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
    }
}
