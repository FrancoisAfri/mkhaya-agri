<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\Count;
use App\Modules\Operations\WarehouseManagement\Models\CountEntry;
use App\Modules\Operations\WarehouseManagement\Models\CountLine;
use App\Modules\Operations\WarehouseManagement\Models\CountPlan;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stocktakes and cycle counts (WMS-070 to WMS-072), from the plan to the adjustment.
 *
 * A count is compared with what the ledger holds at the moment the entry is made, not with the
 * snapshot taken when the count opened: the warehouse may keep working, and a bin that received
 * ten units an hour after the snapshot is not ten units over. The snapshot and the movement since
 * are both kept on the entry, so the reviewer can see which was which.
 *
 * Nothing here changes stock. An accepted variance is handed to StockAdjustments with the COUNT
 * reason, so the approval limits a business set for adjustments apply to counts too -- a count
 * cannot be the back door around them.
 */
class Counting
{
    /** Round 1 is the count, round 2 the recount, round 3 the verification. */
    public const MAX_ROUNDS = 3;

    /** Negative variances come out of held stock first: a short pick put the missing units on hold. */
    private const SHORTFALL_ORDER = [FinStockPosition::ON_HOLD, FinStockPosition::AVAILABLE, FinStockPosition::DAMAGED];

    public function __construct(
        private readonly StockAdjustments $adjustments,
        private readonly StockLedger $ledger,
        private readonly CountInsights $insights,
    ) {}

    /**
     * @param array{warehouse_id:int, scope?:string, scope_ids?:?array, abc_class?:?string, min_value?:?float, max_lines?:?int,
     *              blind?:bool, freeze?:bool, recount_qty?:?float, recount_pct?:?float, recount_value?:?float, team?:?array,
     *              deadline_days?:?int, reason?:?string, trigger?:string, plan_id?:?int} $data
     */
    public function open(array $data, ?User $by): Count
    {
        $warehouse = FinWarehouse::query()->findOrFail($data['warehouse_id']);
        $scope = $data['scope'] ?? 'WAREHOUSE';
        if (! in_array($scope, CountPlan::SCOPES, true)) {
            throw new RuntimeException("Unknown count scope {$scope}.");
        }

        return DB::transaction(function () use ($warehouse, $scope, $data, $by) {
            $rows = $this->positions($warehouse, $scope, $data);
            if ($rows === []) {
                throw new RuntimeException('Nothing in that scope holds stock, so there is nothing to count.');
            }

            $count = Count::create([
                'company_id' => $warehouse->company_id, 'number' => $this->nextNumber((int) $warehouse->company_id),
                'plan_id' => $data['plan_id'] ?? null, 'warehouse_id' => $warehouse->id, 'reason' => $data['reason'] ?? null,
                'trigger' => $data['trigger'] ?? 'MANUAL', 'status' => Count::OPEN, 'blind' => $data['blind'] ?? true,
                'freeze' => $data['freeze'] ?? false, 'recount_qty' => $data['recount_qty'] ?? null,
                'recount_pct' => $data['recount_pct'] ?? null, 'recount_value' => $data['recount_value'] ?? null,
                'team' => array_values(array_map('intval', $data['team'] ?? [])) ?: null, 'snapshot_at' => now(),
                'deadline_at' => now()->addDays((int) ($data['deadline_days'] ?? 2))->endOfDay(), 'created_by' => $by?->id,
            ]);

            foreach ($rows as $r) {
                CountLine::create([
                    'company_id' => $count->company_id, 'count_id' => $count->id, 'bin_id' => $r->bin_id, 'item_id' => $r->item_id,
                    'lot_id' => $r->lot_id, 'snapshot_qty' => $r->qty, 'unit_cost' => $r->unit_cost, 'status' => CountLine::OPEN,
                ]);
            }

            if ($count->freeze) {
                $this->freezeBins($count);
            }

            return $count;
        });
    }

    /** Open the next run of every plan that is due (the daily schedule). Returns the counts opened. */
    public function runDuePlans(): int
    {
        $opened = 0;
        $plans = CountPlan::query()->where('is_active', true)->where('frequency', '!=', 'EVENT')
            ->whereNotNull('next_run_at')->where('next_run_at', '<=', now())->get();

        foreach ($plans as $plan) {
            try {
                $this->openFromPlan($plan, null);
                $opened++;
            } catch (RuntimeException) {
                // An empty scope is not a failure; the plan simply moves on to its next date.
            }
            $next = $plan->nextAfter($plan->next_run_at->max(now()->subDay()));
            $plan->update(['last_run_at' => now(), 'next_run_at' => $next, 'is_active' => $next !== null]);
        }

        return $opened;
    }

    public function openFromPlan(CountPlan $plan, ?User $by, string $trigger = 'PLAN', ?string $reason = null): Count
    {
        return $this->open([
            'warehouse_id' => $plan->warehouse_id, 'scope' => $plan->scope, 'scope_ids' => $plan->scope_ids,
            'abc_class' => $plan->abc_class, 'min_value' => $plan->min_value, 'max_lines' => $plan->max_lines,
            'blind' => $plan->blind, 'freeze' => $plan->freeze, 'recount_qty' => $plan->recount_qty,
            'recount_pct' => $plan->recount_pct, 'recount_value' => $plan->recount_value, 'team' => $plan->team,
            'deadline_days' => $plan->deadline_days, 'reason' => $reason ?? $plan->name, 'trigger' => $trigger, 'plan_id' => $plan->id,
        ], $by);
    }

    /**
     * Something on the floor says a bin is wrong -- a short pick, a pick from an empty bin. Count
     * that bin for that item now, unless an open count already covers it (WMS-070 event counts).
     */
    public function countOnEvent(FinStockBin $bin, FinStockItem $item, string $trigger, string $reason): ?Count
    {
        $covered = CountLine::query()->where('bin_id', $bin->id)->where('item_id', $item->id)
            ->whereHas('stockCount', fn ($q) => $q->whereIn('status', [Count::OPEN, Count::COUNTING, Count::REVIEW]))->exists();
        if ($covered) {
            return null;
        }

        try {
            return $this->open([
                'warehouse_id' => $bin->warehouse_id, 'scope' => 'BINS', 'scope_ids' => [$bin->id], 'item_filter' => [$item->id],
                'blind' => true, 'deadline_days' => 1, 'reason' => $reason, 'trigger' => $trigger,
            ], null);
        } catch (RuntimeException) {
            return null; // the bin is now empty in the ledger too; nothing to count against
        }
    }

    /**
     * One counter's entry for one line. Entries are never edited: a second entry by the same person
     * in the same round is kept and marked as a duplicate, and the latest one counts. A retried
     * submission (same client reference, e.g. from an offline device) returns the first entry.
     *
     * @param  list<string>|null  $serials
     */
    public function record(CountLine $line, float $qty, User $by, ?string $clientRef = null, ?array $serials = null): CountEntry
    {
        if ($clientRef !== null && ($existing = CountEntry::query()->where('client_ref', $clientRef)->first())) {
            return $existing;
        }
        if ($qty < 0) {
            throw new RuntimeException('A count cannot be negative.');
        }

        return DB::transaction(function () use ($line, $qty, $by, $clientRef, $serials) {
            $line = CountLine::query()->lockForUpdate()->findOrFail($line->id);
            $count = $line->stockCount;
            if (! in_array($count->status, [Count::OPEN, Count::COUNTING], true)) {
                throw new RuntimeException("{$count->number} is ".strtolower($count->status).' and takes no more counts.');
            }
            if (! in_array($line->status, [CountLine::OPEN, CountLine::RECOUNT, CountLine::MATCHED, CountLine::VARIANCE], true)) {
                throw new RuntimeException('That line has already been decided.');
            }
            if ($count->team && ! in_array($by->id, $count->team, true)) {
                throw new RuntimeException('Only the team assigned to this count can enter counts.');
            }
            // A line already counted and settled re-opens only by recounting, never by overwriting.
            $round = in_array($line->status, [CountLine::MATCHED, CountLine::VARIANCE], true) ? null : (int) $line->round;
            if ($round === null) {
                throw new RuntimeException('That line is already counted. Ask for a recount if the result is doubted.');
            }
            if ($round > 1) {
                $earlier = $line->entries()->where('round', '<', $round)->pluck('user_id')->filter()->all();
                if (in_array($by->id, $earlier, true)) {
                    throw new RuntimeException('A recount must be made by somebody who has not counted this line before.');
                }
            }

            $expected = $this->liveQty($line);
            $duplicate = $line->entries()->where('round', $round)->where('user_id', $by->id)->exists();
            $entry = CountEntry::create([
                'company_id' => $line->company_id, 'count_line_id' => $line->id, 'round' => $round, 'user_id' => $by->id,
                'qty' => $qty, 'serials' => $serials, 'client_ref' => $clientRef, 'duplicate' => $duplicate,
                'expected_at_entry' => $expected, 'moved_since_snapshot' => round($expected - (float) $line->snapshot_qty, 4),
                'counted_at' => now(),
            ]);

            $this->evaluate($line, $count, $qty, $expected, $round);
            if ($count->status === Count::OPEN) {
                $count->update(['status' => Count::COUNTING]);
            }

            return $entry;
        });
    }

    /** Stock found where the count did not expect it: a new line, counted at once. */
    public function recordUnexpected(Count $count, int $binId, int $itemId, ?int $lotId, float $qty, User $by, ?string $clientRef = null): CountEntry
    {
        $bin = FinStockBin::query()->where('warehouse_id', $count->warehouse_id)->findOrFail($binId);
        $item = FinStockItem::query()->findOrFail($itemId);

        $line = CountLine::query()->where('count_id', $count->id)->where('bin_id', $bin->id)->where('item_id', $item->id)
            ->when($lotId, fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'))->first()
            ?? CountLine::create([
                'company_id' => $count->company_id, 'count_id' => $count->id, 'bin_id' => $bin->id, 'item_id' => $item->id,
                'lot_id' => $lotId, 'snapshot_qty' => 0, 'unit_cost' => $item->isValued() ? ((float) $item->avg_cost ?: (float) $item->standard_cost) : 0,
                'status' => CountLine::OPEN, 'unexpected' => true,
            ]);

        return $this->record($line, $qty, $by, $clientRef);
    }

    /** A reviewer doubts a settled line: send it round again (to a different counter). */
    public function requestRecount(CountLine $line, User $by): CountLine
    {
        $count = $line->stockCount;
        if (! in_array($count->status, [Count::COUNTING, Count::REVIEW], true)) {
            throw new RuntimeException("{$count->number} is not being counted or reviewed.");
        }
        if ((int) $line->round >= self::MAX_ROUNDS) {
            throw new RuntimeException('This line has been counted three times; decide it from the entries.');
        }
        $line->update(['status' => CountLine::RECOUNT, 'round' => (int) $line->round + 1]);
        if ($count->status === Count::REVIEW) {
            $count->update(['status' => Count::COUNTING, 'completed_at' => null]);
        }

        return $line;
    }

    /** Every line counted: the variances go to review, each with what might explain it. */
    public function submit(Count $count, User $by): Count
    {
        if (! in_array($count->status, [Count::OPEN, Count::COUNTING], true)) {
            throw new RuntimeException("{$count->number} is ".strtolower($count->status).'.');
        }
        $waiting = $count->lines()->whereIn('status', [CountLine::OPEN, CountLine::RECOUNT])->count();
        if ($waiting > 0) {
            throw new RuntimeException("{$waiting} line(s) still need counting.");
        }

        return DB::transaction(function () use ($count) {
            foreach ($count->lines()->where('status', CountLine::VARIANCE)->get() as $line) {
                $line->update(['causes' => $this->insights->causes($line, $count)]);
            }
            $count->update(['status' => Count::REVIEW, 'completed_at' => now()]);

            return $count->fresh();
        });
    }

    /** Accept a variance (it will be adjusted) or dismiss it with the explanation that was found. */
    public function decide(CountLine $line, string $decision, ?string $investigation, User $by): CountLine
    {
        if ($line->stockCount->status !== Count::REVIEW) {
            throw new RuntimeException('Variances are decided once the count is in review.');
        }
        if (! in_array($line->status, [CountLine::VARIANCE, CountLine::ACCEPTED, CountLine::DISMISSED], true)) {
            throw new RuntimeException('Only a line with a variance needs a decision.');
        }
        if ($decision === 'dismiss' && blank($investigation)) {
            throw new RuntimeException('Say what explained the variance before dismissing it.');
        }
        $line->update([
            'status' => $decision === 'dismiss' ? CountLine::DISMISSED : CountLine::ACCEPTED,
            'investigation' => $investigation ?? $line->investigation, 'decided_by' => $by->id,
        ]);

        return $line;
    }

    /**
     * Close the count. Accepted (and undecided) variances become COUNT adjustments; those within
     * the adjustment limits post at once, the rest wait for approval in the adjustments queue.
     * Whoever counted cannot close their own count.
     */
    public function post(Count $count, User $by): Count
    {
        if ($count->status !== Count::REVIEW) {
            throw new RuntimeException("{$count->number} must be in review before it is posted.");
        }
        $counters = CountEntry::query()->whereIn('count_line_id', $count->lines()->select('id'))->pluck('user_id')->unique()->all();
        if (in_array($by->id, $counters, true)) {
            throw new RuntimeException('Somebody who counted cannot also approve the count.');
        }

        return DB::transaction(function () use ($count, $by) {
            $this->unfreezeBins($count);
            $this->adjustments->ensureReasonCodes((int) $count->company_id);
            $reason = ReasonCode::query()->where('code', 'COUNT')->firstOrFail();

            foreach ($count->lines()->whereIn('status', [CountLine::VARIANCE, CountLine::ACCEPTED])->with('item')->get() as $line) {
                $ids = $this->adjustLine($count, $line, $reason, $by);
                $first = $ids === [] ? null : StockAdjustment::query()->find($ids[0]);
                $allPosted = $ids !== [] && StockAdjustment::query()->whereIn('id', $ids)->where('status', '!=', StockAdjustment::POSTED)->doesntExist();
                $line->update([
                    'adjustment_id' => $first?->id, 'decided_by' => $line->decided_by ?? $by->id,
                    'status' => $allPosted ? CountLine::ADJUSTED : CountLine::ACCEPTED,
                ]);
            }

            if ($count->trigger === 'SHORT_PICK') {
                $this->releaseShortPickHolds($count);
            }
            $count->update(['status' => Count::POSTED, 'posted_at' => now(), 'approved_by' => $by->id]);

            return $count->fresh();
        });
    }

    public function cancel(Count $count, User $by, string $why): Count
    {
        if ($count->isClosed()) {
            throw new RuntimeException("{$count->number} is already ".strtolower($count->status).'.');
        }
        if (blank($why)) {
            throw new RuntimeException('Say why the count is cancelled.');
        }
        DB::transaction(function () use ($count, $why) {
            $this->unfreezeBins($count);
            $count->update(['status' => Count::CANCELLED, 'reason' => trim(($count->reason ? $count->reason.' -- ' : '').'Cancelled: '.$why)]);
        });

        return $count->fresh();
    }

    /**
     * The decision for an entry. Inside every threshold the count stands (matched or a small
     * variance). Beyond one, the line goes round again to somebody else -- unless two independent
     * counts already agree, in which case the stock really is different and a third count would
     * only tell us so again.
     */
    private function evaluate(CountLine $line, Count $count, float $qty, float $expected, int $round): void
    {
        $variance = round($qty - $expected, 4);
        $value = round($variance * (float) $line->unit_cost, 2);
        $previous = $round > 1 ? $line->entries()->where('round', $round - 1)->orderByDesc('id')->value('qty') : null;
        $agrees = $previous !== null && abs((float) $previous - $qty) < 0.0001;

        $status = match (true) {
            abs($variance) < 0.0001 => CountLine::MATCHED,
            $this->beyondThreshold($count, $variance, $expected, $value) && ! $agrees && $round < self::MAX_ROUNDS => CountLine::RECOUNT,
            default => CountLine::VARIANCE,
        };

        $line->update([
            'expected_qty' => $expected, 'counted_qty' => $qty, 'variance_qty' => $variance, 'variance_value' => $value,
            'status' => $status, 'round' => $status === CountLine::RECOUNT ? $round + 1 : $round,
            'found_empty' => $qty == 0.0 && $expected > 0,
        ]);
    }

    private function beyondThreshold(Count $count, float $variance, float $expected, float $value): bool
    {
        $none = $count->recount_qty === null && $count->recount_pct === null && $count->recount_value === null;
        if ($none) {
            return true; // no tolerance set: every difference is checked once by a second person
        }
        $pct = $expected > 0 ? abs($variance) / $expected * 100 : 100.0;

        return ($count->recount_qty !== null && abs($variance) > (float) $count->recount_qty)
            || ($count->recount_pct !== null && $pct > (float) $count->recount_pct)
            || ($count->recount_value !== null && abs($value) > (float) $count->recount_value);
    }

    /** @return list<int> adjustment ids */
    private function adjustLine(Count $count, CountLine $line, ReasonCode $reason, User $by): array
    {
        $variance = (float) $line->variance_qty;
        if (abs($variance) < 0.0001) {
            return [];
        }
        $note = "Count {$count->number}: expected {$this->num((float) $line->expected_qty)}, counted {$this->num((float) $line->counted_qty)}."
            .($line->investigation ? ' '.$line->investigation : '');
        $base = ['warehouse_id' => $count->warehouse_id, 'item_id' => $line->item_id, 'bin_id' => $line->bin_id,
            'lot_id' => $line->lot_id, 'reason_code_id' => $reason->id, 'notes' => $note];

        if ($variance > 0) {
            return [$this->adjustments->request($base + ['qty' => $variance, 'stock_status' => FinStockPosition::AVAILABLE], $by)->id];
        }

        // A shortfall comes out of the states that hold it, held stock first.
        $ids = [];
        $left = -$variance;
        $held = $this->positionQuery($line)->where('qty', '>', 0)->pluck('qty', 'status')->map(fn ($q) => (float) $q)->all();
        $order = array_unique([...self::SHORTFALL_ORDER, ...array_keys($held)]);
        foreach ($order as $status) {
            $take = round(min($left, $held[$status] ?? 0), 4);
            if ($take <= 0) {
                continue;
            }
            $ids[] = $this->adjustments->request($base + ['qty' => -$take, 'stock_status' => $status], $by)->id;
            $left = round($left - $take, 4);
            if ($left <= 0) {
                break;
            }
        }
        if ($left > 0.0001) {
            throw new RuntimeException("The ledger no longer holds the {$this->num($left)} the count found missing in that bin; recount the line.");
        }

        return $ids;
    }

    /** After a short-pick count the question is answered: what is still held for it goes back to available. */
    private function releaseShortPickHolds(Count $count): void
    {
        $warehouse = $count->warehouse;
        foreach ($count->lines()->with('item')->get() as $line) {
            $held = (float) $this->positionQuery($line)->where('status', FinStockPosition::ON_HOLD)->value('qty');
            if ($held > 0) {
                $this->ledger->changeStatus($line->item, $warehouse, $held, FinStockPosition::ON_HOLD, FinStockPosition::AVAILABLE, [
                    'bin_id' => $line->bin_id, 'lot_id' => $line->lot_id, 'reason' => "Released after count {$count->number}",
                    'reason_code' => 'COUNT', 'source_type' => 'WMS_COUNT', 'source_id' => $count->id,
                ]);
            }
        }
    }

    private function freezeBins(Count $count): void
    {
        $bins = $count->lines()->whereNotNull('bin_id')->distinct()->pluck('bin_id');
        $taken = FinStockBin::query()->whereIn('id', $bins)->whereNotNull('frozen_by_count_id')->value('code');
        if ($taken !== null) {
            throw new RuntimeException("Location {$taken} is already frozen by another count.");
        }
        FinStockBin::query()->whereIn('id', $bins)->update(['frozen_by_count_id' => $count->id]);
    }

    private function unfreezeBins(Count $count): void
    {
        FinStockBin::query()->where('frozen_by_count_id', $count->id)->update(['frozen_by_count_id' => null]);
    }

    /** Physical units in the line's place right now, whatever their state. */
    private function liveQty(CountLine $line): float
    {
        return round((float) $this->positionQuery($line)->sum('qty'), 4);
    }

    private function positionQuery(CountLine $line)
    {
        return FinStockPosition::query()->where('item_id', $line->item_id)->where('warehouse_id', $line->stockCount->warehouse_id)
            ->when($line->bin_id, fn ($q, $v) => $q->where('bin_id', $v), fn ($q) => $q->whereNull('bin_id'))
            ->when($line->lot_id, fn ($q, $v) => $q->where('lot_id', $v), fn ($q) => $q->whereNull('lot_id'));
    }

    /** @return list<object{bin_id:?int,item_id:int,lot_id:?int,qty:float,unit_cost:float}> */
    private function positions(FinWarehouse $warehouse, string $scope, array $data): array
    {
        $ids = array_values(array_filter(array_map('intval', $data['scope_ids'] ?? [])));
        $q = DB::table('fin_stock_positions as p')
            ->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
            ->where('p.warehouse_id', $warehouse->id)
            ->where('p.company_id', $warehouse->company_id)
            ->groupBy('p.bin_id', 'p.item_id', 'p.lot_id', 'i.is_valued', 'i.avg_cost', 'i.standard_cost')
            ->havingRaw('SUM(p.qty) <> 0')
            ->selectRaw('p.bin_id, p.item_id, p.lot_id, SUM(p.qty) AS qty')
            ->selectRaw('CASE WHEN COALESCE(i.is_valued, true) THEN COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost) ELSE 0 END AS unit_cost');

        if (! empty($data['item_filter'])) {
            $q->whereIn('p.item_id', $data['item_filter']);
        }

        match ($scope) {
            'ZONE' => $q->whereIn('p.bin_id', $this->binsUnder($ids)),
            'BINS' => $q->whereIn('p.bin_id', $ids),
            'ITEMS' => $q->whereIn('p.item_id', $ids),
            'LOTS' => $q->whereIn('p.lot_id', $ids),
            'CATEGORY' => $q->whereIn('p.item_id', DB::table('mfg_items')->whereIn('category_id', $ids)->whereNotNull('stock_item_id')->select('stock_item_id')),
            'ABC' => $q->whereIn('p.item_id', DB::table('wms_item_profiles')->where('abc_class', strtoupper((string) ($data['abc_class'] ?? 'A')))->select('stock_item_id')),
            'VALUE' => $q->havingRaw('SUM(p.qty) * (CASE WHEN COALESCE(i.is_valued, true) THEN COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost) ELSE 0 END) >= ?', [(float) ($data['min_value'] ?? 0)]),
            'RISK' => $this->risky($q, $warehouse),
            default => null,
        };

        // Cycle counts rotate: the places counted longest ago (or never) come first.
        $last = DB::table('wms_count_lines as cl')->join('wms_counts as c', 'c.id', '=', 'cl.count_id')
            ->where('c.status', Count::POSTED)->groupBy('cl.bin_id', 'cl.item_id')
            ->selectRaw('cl.bin_id, cl.item_id, MAX(c.posted_at) AS at');
        $rows = DB::query()->fromSub($q, 'x')
            ->leftJoinSub($last, 'lc', fn ($j) => $j->on('lc.item_id', '=', 'x.item_id')->whereRaw('lc.bin_id IS NOT DISTINCT FROM x.bin_id'))
            ->orderByRaw('lc.at ASC NULLS FIRST')->orderBy('x.bin_id')->orderBy('x.item_id')
            ->when($data['max_lines'] ?? null, fn ($q2, $n) => $q2->limit((int) $n))
            ->get(['x.*'])->all();

        return $rows;
    }

    /** Where losses happen: past count variances, short picks and loss adjustments in the last 90 days. */
    private function risky(Builder $q, FinWarehouse $warehouse): void
    {
        $since = now()->subDays(90);
        $q->where(function ($w) use ($since, $warehouse) {
            $w->whereIn('p.item_id', DB::table('wms_count_lines as cl')->join('wms_counts as c', 'c.id', '=', 'cl.count_id')
                ->where('c.warehouse_id', $warehouse->id)->where('c.created_at', '>=', $since)
                ->whereIn('cl.status', [CountLine::ADJUSTED, CountLine::ACCEPTED])->select('cl.item_id'))
                ->orWhereIn('p.item_id', DB::table('fin_stock_movements')->where('warehouse_id', $warehouse->id)
                    ->where('created_at', '>=', $since)->whereIn('reason_code', ['SHORT_PICK', 'THEFT', 'LOST', 'COUNT'])->select('item_id'));
        });
    }

    /** @param list<int> $roots @return list<int> */
    private function binsUnder(array $roots): array
    {
        $all = $roots;
        for ($frontier = $roots, $depth = 0; $frontier !== [] && $depth < 6; $depth++) {
            $frontier = FinStockBin::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $all = [...$all, ...$frontier];
        }

        return $all;
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-count-number:'.$companyId)]);
        $prefix = 'CNT-'.now()->format('Y').'-';
        $last = Count::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
