<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Allocation;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocation (WMS-061): holding specific stock, at a specific bin and lot, for an order line.
 *
 * Every allocation is a ledger reservation, so the stock it holds disappears from what anybody
 * else can promise the moment it is made -- and the ledger's own lock and re-check mean two
 * orders allocated at the same instant cannot both get the last unit (WMS-023). A production
 * order's material is already reserved by Manufacturing; for those the allocation only plans the
 * bin and lot, rather than reserving the same stock a second time.
 *
 * What may be allocated: available stock, in a lot that has not expired, in a bin that is not
 * blocked or restricted and is somewhere goods are picked from, of an item whose status lets it
 * leave. In the order the warehouse rotates by -- first-expiring, first-in or last-in.
 */
class Allocator
{
    /** Bin kinds stock is picked from. Received-but-not-put-away stock is a last resort. */
    private const PICKABLE_KINDS = ['STORAGE' => 0, 'STAGING' => 1, 'RECEIVING' => 2];

    public function __construct(private readonly StockLedger $ledger) {}

    /** Allocate every live order in a warehouse: highest priority first, then the earliest deadline. */
    public function allocateQueue(FinWarehouse $warehouse): int
    {
        $orders = FulfilmentOrder::query()->where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['RELEASED', 'PARTIALLY_ALLOCATED', 'BACKORDERED'])
            ->orderByDesc('priority')->orderByRaw('required_by NULLS LAST')->orderBy('released_at')->get();
        foreach ($orders as $order) {
            $this->allocate($order);
        }

        return $orders->count();
    }

    public function allocate(FulfilmentOrder $order): FulfilmentOrder
    {
        if (! in_array($order->status, ['RELEASED', 'PARTIALLY_ALLOCATED', 'BACKORDERED', 'ALLOCATED', 'PICKING'], true)) {
            return $order;
        }

        return DB::transaction(function () use ($order) {
            $warehouse = $order->warehouse;
            $made = [];
            foreach ($order->lines()->get() as $line) {
                $made = array_merge($made, $this->allocateLine($order, $warehouse, $line));
            }

            // All or nothing: an order that may not ship partly holds nothing until it can hold everything.
            $short = $order->lines()->get()->sum(fn (FulfilmentLine $l) => $l->unallocated());
            if (! $order->allow_partial && $short > 1e-6 && $made !== []) {
                foreach ($made as $a) {
                    $this->release($a, 'Order may not ship partly and cannot be completed yet');
                }
            }
            if ($order->allow_substitution && $short > 1e-6) {
                $this->substitute($order, $warehouse);
            }

            return $this->refreshStatus($order);
        });
    }

    /**
     * Re-check what is held (WMS-061 "recalculate when stock becomes unavailable"): an allocation
     * whose bin was blocked, whose lot expired or whose stock is no longer there is let go, and the
     * order allocated again from what is usable now.
     *
     * @return int allocations released
     */
    public function reallocate(FulfilmentOrder $order): int
    {
        $released = 0;
        DB::transaction(function () use ($order, &$released) {
            foreach ($order->lines()->get() as $line) {
                foreach ($line->allocations()->where('status', 'ACTIVE')->with(['bin', 'lot'])->get() as $a) {
                    $why = $this->unusable($a, $line);
                    if ($why !== null) {
                        $this->release($a, $why);
                        $released++;
                    }
                }
            }
        });
        if ($released > 0) {
            $this->allocate($order->fresh());
        }

        return $released;
    }

    /**
     * Allocate from a place the rotation would not have chosen -- an older lot a customer asked
     * for, a bin being emptied -- with the reason and the person recorded on the allocation
     * (WMS-092). Expired, blocked, held and reserved stock stay refused: an override changes the
     * order stock leaves in, never whether unusable stock may leave.
     */
    public function override(FulfilmentLine $line, ?int $binId, ?int $lotId, float $qty, string $reason, User $by): Allocation
    {
        if (blank($reason)) {
            throw new RuntimeException('Say why the rotation is being overridden.');
        }
        $order = $line->order;
        if (! in_array($order->status, ['RELEASED', 'PARTIALLY_ALLOCATED', 'BACKORDERED', 'ALLOCATED'], true)) {
            throw new RuntimeException("{$order->number} is not waiting for allocation.");
        }
        if ($qty <= 0 || $qty > $line->unallocated() + 1e-6) {
            throw new RuntimeException('Override at most what the line still needs ('.$line->unallocated().').');
        }
        $item = FinStockItem::query()->findOrFail($line->item_id);
        if (($why = $item->refusalFor('dispatch')) !== null) {
            throw new RuntimeException($why);
        }
        $lot = $lotId ? \App\Modules\Finance\Accounting\Models\FinStockLot::query()->where('item_id', $item->id)->findOrFail($lotId) : null;
        if ($lot?->isExpiredOn()) {
            throw new RuntimeException("Lot {$lot->lot_number} has expired; no override sends expired stock.");
        }
        $bin = $binId ? FinStockBin::query()->where('warehouse_id', $order->warehouse_id)->findOrFail($binId) : null;
        if ($bin && ($why = $bin->refusalForIncoming()) !== null) {
            throw new RuntimeException($why);
        }
        $there = (float) FinStockPosition::query()->where('item_id', $item->id)->where('warehouse_id', $order->warehouse_id)
            ->where('status', FinStockPosition::AVAILABLE)
            ->when($binId, fn ($q, $v) => $q->where('bin_id', $v), fn ($q) => $q->whereNull('bin_id'))
            ->when($lotId, fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'))->value('qty');
        $held = (float) Allocation::query()->where('status', 'ACTIVE')->where('bin_id', $binId)->where('lot_id', $lotId)
            ->whereHas('line', fn ($q) => $q->where('item_id', $item->id))->sum(DB::raw('qty - qty_picked'));
        if ($qty > $there - $held + 1e-6) {
            throw new RuntimeException('Only '.round($there - $held, 4).' is free there.');
        }

        return DB::transaction(function () use ($order, $line, $binId, $lotId, $qty, $reason, $by, $item) {
            $a = Allocation::create(['company_id' => $order->company_id, 'order_line_id' => $line->id, 'bin_id' => $binId, 'lot_id' => $lotId,
                'qty' => $qty, 'status' => 'ACTIVE', 'override_reason' => $reason, 'override_by' => $by->id]);
            $r = $this->ledger->reserve($item, $order->warehouse, $qty, 'WMS_ALLOCATION', $a->id, $lotId);
            $a->update(['reservation_id' => $r->id]);
            $line->update(['qty_allocated' => round((float) $line->qty_allocated + $qty, 4)]);
            $this->refreshStatus($order->fresh());

            return $a;
        });
    }

    public function release(Allocation $a, string $reason): void
    {
        $remaining = round((float) $a->qty - (float) $a->qty_picked, 4);
        if ($a->reservation_id) {
            $this->ledger->releaseFor('WMS_ALLOCATION', $a->id, $reason);
        }
        $a->update(['status' => 'RELEASED', 'release_reason' => $reason]);
        $line = $a->line;
        $line->update(['qty_allocated' => round(max(0.0, (float) $line->qty_allocated - $remaining), 4)]);
    }

    /** @return list<Allocation> */
    private function allocateLine(FulfilmentOrder $order, FinWarehouse $warehouse, FulfilmentLine $line): array
    {
        $need = $line->unallocated();
        if ($need <= 1e-6) {
            return [];
        }
        $item = FinStockItem::query()->findOrFail($line->item_id);
        if ($item->refusalFor('dispatch') !== null) {
            return [];
        }

        $made = [];
        foreach ($this->candidates($warehouse, $item, $line) as $c) {
            if ($need <= 1e-6) {
                break;
            }
            $take = min($need, $c['free']);
            if ($take <= 1e-6) {
                continue;
            }

            $a = Allocation::create(['company_id' => $order->company_id, 'order_line_id' => $line->id, 'bin_id' => $c['bin_id'],
                'lot_id' => $c['lot_id'], 'qty' => $take, 'status' => 'ACTIVE']);
            try {
                if ($order->source === 'MANUFACTURING') {
                    // Manufacturing already holds this material for the order; plan the pick only.
                    $free = $this->ledger->available($item, $warehouse->id, $c['lot_id'], null, null, ['PRODUCTION_ORDER', (int) $order->source_id]);
                    if ($free + 1e-6 < $take) {
                        throw new PostingException('not free');
                    }
                } else {
                    // Savepoint: a refused reservation must not abort the whole allocation run.
                    $r = DB::transaction(fn () => $this->ledger->reserve($item, $warehouse, $take, 'WMS_ALLOCATION', $a->id, $c['lot_id']));
                    $a->update(['reservation_id' => $r->id]);
                }
            } catch (PostingException) {
                $a->delete();

                continue;
            }

            $line->update(['qty_allocated' => round((float) $line->qty_allocated + $take, 4)]);
            $need = round($need - $take, 4);
            $made[] = $a;
        }

        return $made;
    }

    /** @return list<array{bin_id:?int, lot_id:?int, free:float}> in the order stock should leave */
    private function candidates(FinWarehouse $warehouse, FinStockItem $item, FulfilmentLine $line): array
    {
        // Rotation is the product's own rule where it has one (WMS-092), else the warehouse's.
        $profile = DB::table('wms_item_profiles')->where('stock_item_id', $item->id)->first(['rotation', 'min_remaining_shelf_life_days']);
        $rotation = $profile->rotation ?? $warehouse->policies['allocation'] ?? $warehouse->policies['rotation'] ?? 'FEFO';
        $today = now()->toDateString();
        // What leaves must still have the shelf life the customer is owed, not merely be in date.
        $usableUntil = now()->addDays((int) ($profile->min_remaining_shelf_life_days ?? 0))->toDateString();

        $rows = DB::table('fin_stock_positions as p')
            ->leftJoin('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->where('p.item_id', $item->id)->where('p.warehouse_id', $warehouse->id)
            ->where('p.status', FinStockPosition::AVAILABLE)->where('p.qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('l.expires_on')->orWhere('l.expires_on', '>=', $usableUntil))
            ->where(fn ($q) => $q->whereNull('p.bin_id')->orWhereIn('b.kind', array_keys(self::PICKABLE_KINDS)))
            ->when($line->lot_id, fn ($q) => $q->where('p.lot_id', $line->lot_id))
            ->get(['p.bin_id', 'p.lot_id', 'p.qty', 'b.kind', 'b.pick_sequence', 'b.code', 'l.expires_on', 'l.created_at as lot_created']);

        $held = DB::table('wms_allocations')->where('status', 'ACTIVE')
            ->whereIn('order_line_id', DB::table('wms_order_lines')->where('item_id', $item->id)->select('id'))
            ->groupBy('bin_id', 'lot_id')->selectRaw('bin_id, lot_id, SUM(qty - qty_picked) AS held')->get()
            ->keyBy(fn ($r) => ($r->bin_id ?? 0).':'.($r->lot_id ?? 0));

        $bins = FinStockBin::query()->whereIn('id', $rows->pluck('bin_id')->filter())->get()->keyBy('id');

        return $rows->filter(fn ($r) => $r->bin_id === null || $bins[$r->bin_id]?->refusalForIncoming() === null)
            ->map(fn ($r) => (array) $r + ['free' => round((float) $r->qty - (float) ($held[($r->bin_id ?? 0).':'.($r->lot_id ?? 0)]->held ?? 0), 4)])
            ->filter(fn ($r) => $r['free'] > 1e-6)
            ->sort(function ($a, $b) use ($rotation) {
                $kind = (self::PICKABLE_KINDS[$a['kind'] ?? 'STORAGE'] ?? 3) <=> (self::PICKABLE_KINDS[$b['kind'] ?? 'STORAGE'] ?? 3);
                if ($kind !== 0) {
                    return $kind;
                }
                $age = match ($rotation) {
                    'FIFO' => strcmp((string) $a['lot_created'], (string) $b['lot_created']),
                    'LIFO' => strcmp((string) $b['lot_created'], (string) $a['lot_created']),
                    'NONE' => 0,
                    default => ($a['expires_on'] === null) <=> ($b['expires_on'] === null) ?: strcmp((string) $a['expires_on'], (string) $b['expires_on']),
                };

                return $age ?: (($a['pick_sequence'] ?? PHP_INT_MAX) <=> ($b['pick_sequence'] ?? PHP_INT_MAX)) ?: strcmp((string) $a['code'], (string) $b['code']);
            })
            ->map(fn ($r) => ['bin_id' => $r['bin_id'], 'lot_id' => $r['lot_id'], 'free' => $r['free']])
            ->values()->all();
    }

    /** Approved substitutes for whatever is still short (WMS-061/063), each on its own line. */
    private function substitute(FulfilmentOrder $order, FinWarehouse $warehouse): void
    {
        foreach ($order->lines()->whereNull('substitute_of_line_id')->get() as $line) {
            $short = $line->unallocated();
            if ($short <= 1e-6) {
                continue;
            }
            $subs = DB::table('mfg_item_substitutes as s')->join('mfg_items as m', 'm.id', '=', 's.item_id')
                ->where('m.stock_item_id', $line->item_id)->where('s.is_active', true)
                ->where(fn ($q) => $q->where('s.requires_approval', false)->orWhereNotNull('s.approved_at'))
                ->get(['s.substitute_stock_item_id', 's.ratio']);
            foreach ($subs as $s) {
                if ($short <= 1e-6) {
                    break;
                }
                $sub = $order->lines()->create([
                    'company_id' => $order->company_id, 'line_no' => (int) $order->lines()->max('line_no') + 1,
                    'item_id' => $s->substitute_stock_item_id, 'qty' => round($short * (float) $s->ratio, 4),
                    'substitute_of_line_id' => $line->id,
                ]);
                $made = $this->allocateLine($order, $warehouse, $sub);
                $covered = round(collect($made)->sum(fn ($a) => (float) $a->qty) / max((float) $s->ratio, 1e-9), 4);
                if ($covered <= 1e-6) {
                    $sub->delete();

                    continue;
                }
                // The original line keeps its history: what the substitute covers is cancelled on it, not erased.
                $sub->update(['qty' => round($covered * (float) $s->ratio, 4)]);
                $line->update(['qty_cancelled' => round((float) $line->qty_cancelled + $covered, 4)]);
                $short = round($short - $covered, 4);
            }
        }
    }

    private function unusable(Allocation $a, FulfilmentLine $line): ?string
    {
        if ($a->bin && ($why = $a->bin->refusalForIncoming()) !== null) {
            return $why;
        }
        if ($a->lot?->isExpiredOn()) {
            return "Lot {$a->lot->lot_number} has expired";
        }
        if (($why = $line->item->refusalFor('dispatch')) !== null) {
            return $why;
        }
        $there = (float) FinStockPosition::query()->where('item_id', $line->item_id)->where('warehouse_id', $line->order->warehouse_id)
            ->where('status', FinStockPosition::AVAILABLE)
            ->when($a->bin_id, fn ($q, $v) => $q->where('bin_id', $v), fn ($q) => $q->whereNull('bin_id'))
            ->when($a->lot_id, fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'))->value('qty');
        if ($there + 1e-6 < (float) $a->qty - (float) $a->qty_picked) {
            return 'The stock is no longer in that bin and state';
        }

        return null;
    }

    private function refreshStatus(FulfilmentOrder $order): FulfilmentOrder
    {
        if (! in_array($order->status, ['RELEASED', 'PARTIALLY_ALLOCATED', 'BACKORDERED', 'ALLOCATED'], true)) {
            return $order;
        }
        $lines = $order->lines()->get();
        $wanted = $lines->sum(fn ($l) => (float) $l->qty - (float) $l->qty_cancelled);
        $held = $lines->sum(fn ($l) => (float) $l->qty_allocated);
        $status = match (true) {
            $wanted <= 1e-6 => $order->status,
            $held >= $wanted - 1e-6 => 'ALLOCATED',
            $held > 1e-6 => 'PARTIALLY_ALLOCATED',
            default => 'BACKORDERED',
        };
        $order->update(['status' => $status]);

        return $order;
    }
}
