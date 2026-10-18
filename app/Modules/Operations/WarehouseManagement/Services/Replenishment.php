<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use App\Modules\Operations\WarehouseManagement\Models\ReplenishmentRule;
use App\Modules\Operations\WarehouseManagement\Models\ReplenishmentTask;
use App\Modules\Procurement\Services\PurchaseRequestService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replenishment (WMS-052) and its priorities (WMS-053).
 *
 * Two kinds of top-up, both from a min/max rule:
 *  - a pick face from bulk storage in the same warehouse: tasks, first-expiring stock first;
 *  - a whole warehouse from another warehouse: a draft transfer order.
 * And one kind of suggestion, for the buyer rather than the floor: items whose position --
 * available, plus what is on order, less what is backordered -- has fallen to the reorder point,
 * where the reorder point covers forecast demand over the lead time on top of safety stock. Those
 * become a Procurement purchase request, the same road Manufacturing's MRP uses.
 *
 * Priority is not a guess: an order already short of this item outranks a pick face about to run
 * dry, which outranks one feeding a waiting production order, which outranks a routine top-up --
 * and each task says which of those applied.
 */
class Replenishment
{
    public const DEMAND_WINDOW_DAYS = 90;

    public function __construct(
        private readonly StockAvailability $availability,
        private readonly BinTransfers $moves,
        private readonly TransferOrders $transfers,
    ) {}

    public function saveRule(FinWarehouse $warehouse, FinStockItem $item, array $data, ?ReplenishmentRule $rule = null): ReplenishmentRule
    {
        if ((float) $data['max_qty'] <= (float) $data['min_qty']) {
            throw new RuntimeException('The maximum must be above the minimum.');
        }
        if (($data['source'] ?? 'BULK') === 'BULK' && empty($data['pick_bin_id'])) {
            throw new RuntimeException('A top-up from bulk needs the pick bin it fills.');
        }
        if (($data['source'] ?? 'BULK') === 'WAREHOUSE' && (empty($data['source_warehouse_id']) || (int) $data['source_warehouse_id'] === $warehouse->id)) {
            throw new RuntimeException('Choose the other warehouse this one is topped up from.');
        }

        $rule ??= new ReplenishmentRule(['company_id' => $warehouse->company_id, 'warehouse_id' => $warehouse->id, 'item_id' => $item->id]);
        $rule->fill(array_intersect_key($data, array_flip(['pick_bin_id', 'source', 'source_warehouse_id', 'min_qty', 'max_qty', 'multiple', 'is_active'])))->save();

        return $rule;
    }

    /** Create what is needed now. Safe to run repeatedly: a rule never has two open tasks. @return int tasks created */
    public function generate(?FinWarehouse $only = null, ?User $user = null): int
    {
        $made = 0;
        $rules = ReplenishmentRule::query()->where('is_active', true)->when($only, fn ($q) => $q->where('warehouse_id', $only->id))
            ->with(['item', 'warehouse', 'pickBin', 'sourceWarehouse'])->get();

        foreach ($rules as $rule) {
            // An open task, a partial one, or an unresolved "nothing in bulk" exception already
            // speaks for this rule; a second would only duplicate the work or the alarm.
            if (ReplenishmentTask::query()->where('rule_id', $rule->id)->whereIn('status', ['OPEN', 'PARTIAL', 'FAILED'])->exists()) {
                continue;
            }
            $current = $this->currentLevel($rule);
            if ($current > (float) $rule->min_qty + 1e-6) {
                continue;
            }
            $need = $this->roundUp((float) $rule->max_qty - $current, (float) ($rule->multiple ?? 0));
            [$priority, $reasons] = $this->priority($rule, $current);

            $made += DB::transaction(fn () => $rule->source === 'WAREHOUSE'
                ? $this->fromWarehouse($rule, $need, $priority, $reasons, $user)
                : $this->fromBulk($rule, $need, $priority, $reasons));
        }

        return $made;
    }

    public function complete(ReplenishmentTask $task, float $qty, User $user, array $scans = []): ReplenishmentTask
    {
        if (! in_array($task->status, ['OPEN', 'PARTIAL'], true) || $task->transfer_id) {
            throw new RuntimeException("{$task->number} cannot be completed here.");
        }
        $remaining = (float) $task->qty - (float) $task->done_qty;
        if ($qty <= 0 || $qty > $remaining + 1e-6) {
            throw new RuntimeException("Move between 0 and {$remaining}.");
        }

        return DB::transaction(function () use ($task, $qty, $user, $scans) {
            $this->moves->move($task->warehouse, $task->item, $task->fromBin, $task->toBin, $qty, $task->lot_id,
                FinStockPosition::AVAILABLE, $user, $scans, "Replenishment {$task->number}", intoPickFace: true);
            $done = round((float) $task->done_qty + $qty, 4);
            $task->update(['done_qty' => $done, 'status' => $done >= (float) $task->qty - 1e-6 ? 'DONE' : 'PARTIAL',
                'completed_by' => $user->id, 'completed_at' => now()]);

            return $task->fresh();
        });
    }

    public function fail(ReplenishmentTask $task, string $reason): ReplenishmentTask
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say what went wrong.');
        }
        $task->update(['status' => 'FAILED', 'exception_note' => $reason]);

        return $task;
    }

    /**
     * Items at or below their reorder point (WMS-052, from suppliers).
     *
     * @return list<array<string,mixed>>
     */
    public function reorderSuggestions(): array
    {
        $profiles = ItemProfile::query()->get()->keyBy('stock_item_id');
        $items = FinStockItem::query()->where('status', FinStockItem::ACTIVE)->where('is_valued', true)
            ->where(fn ($q) => $q->where('reorder_level', '>', 0)->orWhereIn('id', $profiles->filter(fn ($p) => $p->safety_stock > 0 || $p->max_stock > 0)->keys()))
            ->get();
        if ($items->isEmpty()) {
            return [];
        }

        $rows = collect($this->availability->rows($items->pluck('id')->all()))->groupBy('item_id');
        $demand = $this->dailyDemand($items->pluck('id')->all());
        $out = [];

        foreach ($items as $item) {
            $p = $profiles[$item->id] ?? null;
            $set = $rows[$item->id] ?? collect();
            $atp = (float) $set->sum('available_to_promise');
            $onOrder = (float) ($set->first()['expected_receipts'] ?? 0);
            $back = (float) $set->sum('backordered');
            $position = round($atp + $onOrder - $back, 4);
            $daily = $demand[$item->id] ?? 0.0;
            $lead = (int) ($p?->lead_time_days ?? 0);
            $reorderPoint = max((float) $item->reorder_level, (float) ($p?->safety_stock ?? 0) + $daily * $lead);
            if ($reorderPoint <= 0 || $position > $reorderPoint + 1e-6) {
                continue;
            }
            $target = (float) ($p?->max_stock ?? 0) > 0 ? (float) $p->max_stock : $reorderPoint + max((float) ($p?->reorder_qty ?? 0), $daily * max($lead, 1));
            $qty = max((float) ($p?->reorder_qty ?? 0), round($target - $position, 4));

            $out[] = [
                'item_id' => $item->uuid, 'code' => $item->code, 'name' => $item->name, 'uom' => $item->uom,
                'available' => $atp, 'on_order' => $onOrder, 'backordered' => $back, 'position' => $position,
                'daily_demand' => round($daily, 4), 'lead_time_days' => $lead, 'safety_stock' => (float) ($p?->safety_stock ?? 0),
                'reorder_point' => round($reorderPoint, 4), 'suggested_qty' => round($qty, 4),
                'days_of_cover' => $daily > 0 ? round($atp / $daily, 1) : null, 'unit_cost' => (float) ($item->avg_cost ?: $item->standard_cost),
            ];
        }

        return collect($out)->sortBy('days_of_cover')->values()->all();
    }

    /**
     * Raise the chosen suggestions as one draft purchase request -- Procurement's document, through
     * Procurement's service, so its approval and budget rules apply exactly as to any other request.
     *
     * @param  array<string,float>  $lines  item uuid => quantity
     */
    public function raiseRequest(array $lines, User $user, ?string $note = null): \App\Modules\Procurement\Models\PurchaseRequest
    {
        if ($lines === []) {
            throw new RuntimeException('Choose at least one item to order.');
        }

        return DB::transaction(function () use ($lines, $user, $note) {
            $items = FinStockItem::query()->whereIn('uuid', array_keys($lines))->get()->keyBy('uuid');
            $total = $items->sum(fn ($i) => (float) $lines[$i->uuid] * (float) ($i->avg_cost ?: $i->standard_cost));
            $request = app(PurchaseRequestService::class)->create([
                'justification' => trim('Raised from warehouse reorder suggestions. '.($note ?? '')),
                'urgency' => 'normal', 'currency' => config('finance.base_currency', 'ZAR'), 'status' => 'draft',
                'estimated_total' => round($total, 2),
            ], $user->id);

            foreach ($items as $uuid => $item) {
                $qty = (float) $lines[$uuid];
                $price = (float) ($item->avg_cost ?: $item->standard_cost);
                $line = $request->items()->create([
                    'item_type' => 'goods', 'name' => trim("{$item->code} — {$item->name}"), 'quantity' => $qty, 'unit_price' => $price,
                    'line_total' => round($qty * $price, 2), 'source' => 'WMS_REORDER', 'external_ref' => $item->code,
                ]);
                $line->forceFill(['stock_item_id' => $item->id])->save();
            }

            return $request;
        });
    }

    private function currentLevel(ReplenishmentRule $rule): float
    {
        if ($rule->source === 'WAREHOUSE' || ! $rule->pick_bin_id) {
            return (float) collect($this->availability->rows([$rule->item_id], $rule->warehouse_id))->sum('available_to_promise');
        }

        return (float) DB::table('fin_stock_positions as p')->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->where('p.item_id', $rule->item_id)->where('p.bin_id', $rule->pick_bin_id)->where('p.status', FinStockPosition::AVAILABLE)
            ->where(fn ($q) => $q->whereNull('l.expires_on')->orWhere('l.expires_on', '>=', now()->toDateString()))
            ->sum('p.qty');
    }

    private function fromBulk(ReplenishmentRule $rule, float $need, int $priority, array $reasons): int
    {
        // First-expiring first, then oldest, from storage bins other than the pick face.
        $sources = DB::table('fin_stock_positions as p')->join('fin_stock_bins as b', 'b.id', '=', 'p.bin_id')
            ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->where('p.item_id', $rule->item_id)->where('p.warehouse_id', $rule->warehouse_id)->where('p.status', FinStockPosition::AVAILABLE)
            ->where('p.qty', '>', 0)->where('p.bin_id', '!=', $rule->pick_bin_id)->where('b.kind', 'STORAGE')
            ->where(fn ($q) => $q->whereNull('l.expires_on')->orWhere('l.expires_on', '>=', now()->toDateString()))
            ->orderByRaw('l.expires_on NULLS LAST')->orderBy('p.id')
            ->get(['p.bin_id', 'p.lot_id', 'p.qty']);

        $made = 0;
        foreach ($sources as $s) {
            if ($need <= 1e-6) {
                break;
            }
            $take = min($need, (float) $s->qty);
            ReplenishmentTask::create([
                'company_id' => $rule->company_id, 'number' => $this->nextNumber((int) $rule->company_id),
                'rule_id' => $made === 0 ? $rule->id : null, 'warehouse_id' => $rule->warehouse_id, 'item_id' => $rule->item_id,
                'lot_id' => $s->lot_id, 'from_bin_id' => $s->bin_id, 'to_bin_id' => $rule->pick_bin_id, 'qty' => $take,
                'priority' => $priority, 'priority_reasons' => $reasons, 'status' => 'OPEN',
            ]);
            $need -= $take;
            $made++;
        }

        if ($made === 0) {
            // Nothing in bulk to top up from: an exception the floor cannot fix and the buyer must.
            ReplenishmentTask::create([
                'company_id' => $rule->company_id, 'number' => $this->nextNumber((int) $rule->company_id), 'rule_id' => $rule->id,
                'warehouse_id' => $rule->warehouse_id, 'item_id' => $rule->item_id, 'to_bin_id' => $rule->pick_bin_id, 'qty' => $need,
                'priority' => $priority, 'priority_reasons' => $reasons, 'status' => 'FAILED',
                'exception_note' => 'No usable stock in bulk storage to replenish from.',
            ]);
        }

        return $made;
    }

    private function fromWarehouse(ReplenishmentRule $rule, float $need, int $priority, array $reasons, ?User $user): int
    {
        $user ??= User::query()->find($rule->warehouse->manager_user_id) ?? User::query()->where('company_id', $rule->company_id)->first();
        $order = $this->transfers->draft($rule->sourceWarehouse, $rule->warehouse, [['item_id' => $rule->item_id, 'qty' => $need]], $user,
            ['reason' => 'Replenishment to max '.((float) $rule->max_qty), 'priority' => $priority, 'source' => 'REPLENISHMENT']);
        ReplenishmentTask::create([
            'company_id' => $rule->company_id, 'number' => $this->nextNumber((int) $rule->company_id), 'rule_id' => $rule->id,
            'warehouse_id' => $rule->warehouse_id, 'item_id' => $rule->item_id, 'qty' => $need, 'priority' => $priority,
            'priority_reasons' => $reasons, 'status' => 'OPEN', 'transfer_id' => $order->id,
        ]);

        return 1;
    }

    /** @return array{0:int,1:list<string>} */
    private function priority(ReplenishmentRule $rule, float $current): array
    {
        $score = 10;
        $reasons = [];
        $row = collect($this->availability->rows([$rule->item_id], $rule->warehouse_id))->first() ?? [];

        if ((float) ($row['backordered'] ?? 0) > 0 || (float) ($row['allocated_not_picked'] ?? 0) > $current + 1e-6) {
            $score += 50;
            $reasons[] = 'orders are already short of this item';
        }
        $daily = $this->dailyDemand([$rule->item_id])[$rule->item_id] ?? 0.0;
        if ($daily > 0 && $current / $daily < 2) {
            $score += 25;
            $reasons[] = 'the pick face runs out within two days at the current rate';
        } elseif ($current <= 0) {
            $score += 25;
            $reasons[] = 'the pick face is empty';
        }
        $production = DB::table('fin_stock_reservations')->where('item_id', $rule->item_id)->where('warehouse_id', $rule->warehouse_id)
            ->where('status', 'OPEN')->where('source_type', 'PRODUCTION_ORDER')->exists();
        if ($production) {
            $score += 15;
            $reasons[] = 'a production order is waiting for it';
        }
        if ($reasons === []) {
            $reasons[] = 'routine top-up below the minimum';
        }

        return [min(100, $score), $reasons];
    }

    /** @return array<int,float> item id => average units out per day over the demand window */
    private function dailyDemand(array $itemIds): array
    {
        return DB::table('fin_stock_movements')->whereIn('item_id', $itemIds)->where('qty', '<', 0)
            ->whereIn('type', ['issue', 'sale', 'mfg_issue', 'dispatch'])
            ->where('created_at', '>=', now()->subDays(self::DEMAND_WINDOW_DAYS))
            ->groupBy('item_id')->selectRaw('item_id, -SUM(qty) / ? AS daily', [self::DEMAND_WINDOW_DAYS])
            ->pluck('daily', 'item_id')->map(fn ($v) => (float) $v)->all();
    }

    private function roundUp(float $qty, float $multiple): float
    {
        return $multiple > 0 ? ceil($qty / $multiple - 1e-9) * $multiple : round($qty, 4);
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-repl-number:'.$companyId)]);
        $prefix = 'RPL-'.now()->format('Y').'-';
        $last = ReplenishmentTask::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
    }
}
