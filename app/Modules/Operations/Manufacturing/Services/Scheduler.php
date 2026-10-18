<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\AssetWorkOrder;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\MachineProfile;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\RoutingOperationAlternate;
use App\Modules\Operations\Manufacturing\Models\ScheduleRun;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production scheduling (MFG-014): forward, finite-capacity, in day buckets.
 *
 * Each work centre's capacity per day is the factory calendar's shift time for its line, times the
 * jobs it can run at once, less the share of its machines held or due for preventive maintenance
 * that day. Orders are placed one after another in the order the chosen priority rule puts them;
 * each operation starts no earlier than the operations before it (outside its parallel group), no
 * earlier than the order's material can be ready, and no earlier than a planner's "not before",
 * at whichever permitted work centre -- the routing's own or an approved alternate -- finishes it
 * soonest. Subcontracted operations take their lead time in calendar days and no capacity.
 *
 * The same calculation previews a planner's changes without saving them, so the impact on due
 * dates, capacity and material is visible before it is applied.
 */
class Scheduler
{
    private Carbon $today;

    private int $days;

    /** @var array<int, list<float>> work centre => remaining minutes per day */
    private array $free = [];

    /** @var array<int, list<float>> work centre => capacity per day */
    private array $capacity = [];

    private array $exceptions = [];

    public function __construct(
        private readonly FactoryCalendar $calendar,
        private readonly StockLedger $stock,
    ) {}

    /**
     * @param  array<string, array{earliest_start?: ?string, priority?: ?int}>  $changes  order uuid => what the planner moved
     */
    public function schedule(string $rule = 'DUE_DATE', int $horizonDays = 42, array $changes = [], bool $save = false, ?User $actor = null): array
    {
        $this->today = now()->startOfDay();
        $this->days = max(7, min(180, $horizonDays));
        $this->free = $this->capacity = $this->exceptions = [];

        $orders = PO::query()->whereIn('status', [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])
            ->with(['operations.workCentre', 'components', 'item.stockItem:id,code,name'])->get();
        foreach ($orders as $o) {
            if (isset($changes[$o->uuid])) {
                $c = $changes[$o->uuid];
                if (array_key_exists('earliest_start', $c)) {
                    $o->sched_earliest_start = $c['earliest_start'] ? Carbon::parse($c['earliest_start']) : null;
                }
                if (! empty($c['priority'])) {
                    $o->priority = (int) $c['priority'];
                }
            }
        }

        $ordered = $this->ordered($orders, $rule);
        $plan = [];
        foreach ($ordered as $order) {
            $plan[$order->id] = $this->place($order);
        }

        $result = $this->result($ordered, $plan);

        if ($save) {
            $this->save($ordered, $plan, $rule, $result, $actor);
        }

        return $result;
    }

    // ── Placing an order ─────────────────────────────────────────────────────────────────────

    private function place(PO $order): array
    {
        $earliest = $this->today->copy();
        if ($order->sched_earliest_start && $order->sched_earliest_start->gt($earliest)) {
            $earliest = $order->sched_earliest_start->copy()->startOfDay();
        }

        $ready = $this->materialReady($order);
        if ($ready['date']->gt($earliest)) {
            $earliest = $ready['date']->copy();
        }

        $ops = [];
        $ends = [];   // sequence => [day, group]
        foreach ($order->operations->sortBy('sequence') as $op) {
            if ($op->status === Op::COMPLETED || $op->status === Op::SKIPPED) {
                $end = $op->completed_at ? $op->completed_at->copy()->startOfDay() : $this->today->copy();
                $ops[$op->id] = ['start' => $op->started_at?->copy()->startOfDay() ?? $end, 'end' => $end, 'centre' => $op->work_centre_id, 'done' => true];
                $ends[$op->sequence] = [$end, $op->parallel_group];

                continue;
            }

            $start = $earliest->copy();
            foreach ($ends as $seq => [$day, $group]) {
                if (($op->parallel_group === null || $group !== $op->parallel_group) && $day->gt($start)) {
                    $start = $day->copy();
                }
            }
            if (in_array($op->status, [Op::IN_PROGRESS, Op::PAUSED], true)) {
                $start = $this->today->copy();
            }

            if ($op->is_subcontracted) {
                $lead = (int) (DB::table('mfg_routing_operations')->where('id', $op->routing_operation_id)->value('subcontract_lead_days') ?? 0);
                $placed = ['start' => $start, 'end' => $start->copy()->addDays($lead), 'centre' => null, 'done' => false, 'subcontract' => true];
            } else {
                $minutes = (float) $op->planned_minutes;
                if (in_array($op->status, [Op::IN_PROGRESS, Op::PAUSED], true)) {
                    $minutes = max(1.0, $minutes - (float) $op->actual_machine_minutes);
                }
                $placed = $this->bestCentre($op, $start, $minutes);
                if ($placed === null) {
                    $this->exceptions[] = ['kind' => 'UNSCHEDULED', 'order' => $order->number,
                        'message' => "{$order->number} operation {$op->sequence} {$op->name} cannot be fitted within {$this->days} days."];
                    $placed = ['start' => $start, 'end' => $start->copy()->addDays($this->days), 'centre' => $op->work_centre_id, 'done' => false, 'unscheduled' => true];
                }
            }

            $ops[$op->id] = $placed;
            $ends[$op->sequence] = [$placed['end'], $op->parallel_group];
        }

        $finish = collect($ops)->max('end') ?? $earliest;

        if ($ready['short']) {
            $this->exceptions[] = ['kind' => 'MATERIAL', 'order' => $order->number,
                'message' => "{$order->number} waits for material until {$ready['date']->toDateString()}: ".implode(', ', $ready['short']).'.'];
        }
        if ($order->due_date && $finish->gt($order->due_date)) {
            $this->exceptions[] = ['kind' => 'LATE', 'order' => $order->number,
                'message' => sprintf('%s finishes %s, %d day(s) after it is due (%s).', $order->number, $finish->toDateString(),
                    (int) $order->due_date->diffInDays($finish), $order->due_date->toDateString())];
        }

        return ['ops' => $ops, 'finish' => $finish, 'start' => collect($ops)->where('done', false)->min('start') ?? $earliest, 'material_ready' => $ready['date']];
    }

    /** The permitted centre that finishes the operation soonest, booked. */
    private function bestCentre(Op $op, Carbon $from, float $minutes): ?array
    {
        $candidates = collect([$op->work_centre_id])
            ->merge(RoutingOperationAlternate::query()->where('operation_id', $op->routing_operation_id)->orderBy('priority')->pluck('work_centre_id'))
            ->filter()->unique()->values();

        if ($candidates->isEmpty()) {
            $this->exceptions[] = ['kind' => 'NO_CENTRE', 'order' => null, 'message' => "Operation {$op->sequence} {$op->name} has no work centre to schedule on."];

            return null;
        }

        $best = null;
        foreach ($candidates as $centreId) {
            $try = $this->book((int) $centreId, $from, $minutes, true);
            if ($try && (! $best || $try['end']->lt($best['end']))) {
                $best = $try + ['centre' => (int) $centreId];
            }
        }

        if (! $best) {
            return null;
        }

        $this->book($best['centre'], $from, $minutes, false);

        return ['start' => $best['start'], 'end' => $best['end'], 'centre' => $best['centre'], 'done' => false,
            'alternate' => (int) $best['centre'] !== (int) $op->work_centre_id];
    }

    /** Consume a centre's free minutes from a day on; a dry run only reports where it would land. */
    private function book(int $centreId, Carbon $from, float $minutes, bool $dryRun): ?array
    {
        $this->loadCentre($centreId);
        $offset = max(0, (int) $this->today->diffInDays($from, false));
        $left = $minutes;
        $start = null;
        $free = $this->free[$centreId];

        for ($d = $offset; $d < $this->days; $d++) {
            if ($free[$d] <= 0.0001) {
                continue;
            }
            $start ??= $d;
            $take = min($free[$d], $left);
            $free[$d] -= $take;
            $left -= $take;
            if ($left <= 0.0001) {
                if (! $dryRun) {
                    $this->free[$centreId] = $free;
                }

                return ['start' => $this->today->copy()->addDays($start), 'end' => $this->today->copy()->addDays($d)];
            }
        }

        return null;
    }

    private function loadCentre(int $centreId): void
    {
        if (isset($this->free[$centreId])) {
            return;
        }

        $centre = WorkCentre::query()->with('factory')->find($centreId);
        $days = $centre ? $this->calendar->days($centre->factory, $this->today, $this->today->copy()->addDays($this->days - 1), $centre->production_line_id) : [];
        $machines = DB::table('mfg_work_centre_machines')->where('work_centre_id', $centreId)->pluck('asset_id');
        $held = $machines->isNotEmpty() ? MachineProfile::query()->whereIn('asset_id', $machines)->where('hold_status', '!=', MachineProfile::NONE)->count() : 0;
        $pm = $machines->isNotEmpty()
            ? AssetWorkOrder::query()->whereIn('asset_id', $machines)->whereIn('type', ['preventive', 'inspection'])
                ->whereNotIn('status', ['completed', 'cancelled'])->whereNotNull('scheduled_for')->get(['scheduled_for'])
                ->groupBy(fn ($w) => Carbon::parse($w->scheduled_for)->toDateString())->map->count()
            : collect();

        $jobs = max(1, (int) ($centre?->concurrent_jobs ?? 1));
        $cap = [];
        foreach (range(0, $this->days - 1) as $d) {
            $date = $this->today->copy()->addDays($d)->toDateString();
            $minutes = (float) ($days[$d]['minutes'] ?? 0) * $jobs;
            if ($machines->isNotEmpty()) {
                $down = min($machines->count(), $held + ($pm[$date] ?? 0));
                $minutes *= ($machines->count() - $down) / $machines->count();
            }
            $cap[] = $minutes;
        }

        if ($centre && array_sum($cap) <= 0) {
            $this->exceptions[] = ['kind' => 'NO_CAPACITY', 'order' => null, 'message' => $held && $held >= $machines->count()
                ? "Every machine at {$centre->code} is on hold: it has no capacity until one is returned to service."
                : "{$centre->code} has no shift time in the next {$this->days} days."];
        }

        $this->capacity[$centreId] = $cap;
        $this->free[$centreId] = $cap;
    }

    /**
     * When the order's material can be ready: today if what it still needs is free for it now,
     * otherwise after the slowest short material's lead time.
     *
     * @return array{date: Carbon, short: list<string>}
     */
    private function materialReady(PO $order): array
    {
        $date = $this->today->copy();
        $short = [];
        $warehouseId = $order->component_warehouse_id ?? $order->warehouse_id;

        foreach ($order->components as $c) {
            if (! $c->isConsumed() || $c->outstanding() <= 0.0001) {
                continue;
            }
            $item = FinStockItem::withoutGlobalScopes()->find($c->stock_item_id);
            if (! $item) {
                continue;
            }
            $free = $this->stock->available($item, $warehouseId, null, null, null, [PO::SOURCE_TYPE, $order->id]);
            if ($free + 0.0001 >= $c->outstanding()) {
                continue;
            }
            $lead = PlanningParam::query()->where('stock_item_id', $item->id)->value('lead_time_days')
                ?? (Schema::hasTable('wms_item_profiles') ? DB::table('wms_item_profiles')->where('stock_item_id', $item->id)->value('lead_time_days') : null)
                ?? 0;
            $when = $this->today->copy()->addDays((int) $lead);
            if ($when->gt($date)) {
                $date = $when;
            }
            $short[] = "{$item->code} ".rtrim(rtrim(number_format($c->outstanding() - $free, 4, '.', ''), '0'), '.').' short';
        }

        return ['date' => $date, 'short' => $short];
    }

    // ── Ordering and results ─────────────────────────────────────────────────────────────────

    private function ordered(Collection $orders, string $rule): Collection
    {
        // Work already on the floor keeps its place ahead of everything not yet started.
        $started = fn (PO $o) => in_array($o->status, [PO::IN_PRODUCTION], true) ? 0 : 1;

        $sorted = match ($rule) {
            'PRIORITY' => $orders->sortBy([fn ($a, $b) => $started($a) <=> $started($b), ['priority', 'asc'], ['due_date', 'asc']]),
            'SHORTEST_FIRST' => $orders->sortBy([fn ($a, $b) => $started($a) <=> $started($b),
                fn ($a, $b) => $a->operations->where('status', '!=', Op::COMPLETED)->sum('planned_minutes') <=> $b->operations->where('status', '!=', Op::COMPLETED)->sum('planned_minutes')]),
            'CUSTOMER_FIRST' => $orders->sortBy([fn ($a, $b) => $started($a) <=> $started($b),
                fn ($a, $b) => (int) ($b->demand_type === 'SALES_ORDER') <=> (int) ($a->demand_type === 'SALES_ORDER'), ['due_date', 'asc']]),
            default => $orders->sortBy([fn ($a, $b) => $started($a) <=> $started($b), ['due_date', 'asc'], ['priority', 'asc']]),
        };

        return $sorted->values();
    }

    private function result(Collection $orders, array $plan): array
    {
        $centres = WorkCentre::query()->whereIn('id', array_keys($this->capacity))->orderBy('code')->get()->keyBy('id');
        $bars = [];
        foreach ($orders as $o) {
            foreach ($o->operations as $op) {
                $p = $plan[$o->id]['ops'][$op->id] ?? null;
                if (! $p) {
                    continue;
                }
                $bars[] = ['order_id' => $o->uuid, 'order' => $o->number, 'operation' => "{$op->sequence} {$op->name}", 'status' => $op->status,
                    'centre_id' => $p['centre'], 'start' => $p['start']->toDateString(), 'end' => $p['end']->toDateString(),
                    'done' => $p['done'], 'alternate' => $p['alternate'] ?? false, 'subcontract' => $p['subcontract'] ?? false,
                    'late' => $o->due_date && $plan[$o->id]['finish']->gt($o->due_date)];
            }
        }

        $utilisation = [];
        foreach ($this->capacity as $centreId => $cap) {
            $used = array_map(fn ($c, $f) => $c - $f, $cap, $this->free[$centreId]);
            $utilisation[] = ['centre_id' => $centreId, 'code' => $centres[$centreId]?->code, 'name' => $centres[$centreId]?->name,
                'days' => array_map(fn ($c, $u, $d) => ['date' => $this->today->copy()->addDays($d)->toDateString(), 'capacity' => round($c, 1),
                    'used' => round($u, 1), 'percent' => $c > 0 ? round($u / $c * 100, 1) : null], $cap, $used, array_keys($cap)),
                'percent' => array_sum($cap) > 0 ? round(array_sum($used) / array_sum($cap) * 100, 1) : null];
        }
        usort($utilisation, fn ($a, $b) => ($b['percent'] ?? 0) <=> ($a['percent'] ?? 0));

        return [
            'from' => $this->today->toDateString(), 'days' => $this->days,
            'orders' => $orders->map(fn (PO $o) => ['id' => $o->uuid, 'number' => $o->number, 'status' => $o->status, 'priority' => (int) $o->priority,
                'product' => trim(($o->item?->stockItem?->code ?? '').' — '.($o->item?->stockItem?->name ?? '')),
                'due_date' => $o->due_date?->toDateString(), 'earliest_start' => $o->sched_earliest_start?->toDateString(),
                'start' => $plan[$o->id]['start']->toDateString(), 'finish' => $plan[$o->id]['finish']->toDateString(),
                'previous_finish' => $o->getOriginal('sched_finish') ? Carbon::parse($o->getOriginal('sched_finish'))->toDateString() : null,
                'material_ready' => $plan[$o->id]['material_ready']->toDateString(),
                'late' => $o->due_date && $plan[$o->id]['finish']->gt($o->due_date)])->values()->all(),
            'bars' => $bars,
            'utilisation' => $utilisation,
            'bottleneck' => $utilisation[0]['code'] ?? null,
            'exceptions' => $this->exceptions,
        ];
    }

    private function save(Collection $orders, array $plan, string $rule, array $result, ?User $actor): void
    {
        DB::transaction(function () use ($orders, $plan, $rule, $result, $actor) {
            foreach ($orders as $o) {
                foreach ($o->operations as $op) {
                    $p = $plan[$o->id]['ops'][$op->id] ?? null;
                    if (! $p || $p['done']) {
                        continue;
                    }
                    Op::query()->whereKey($op->id)->update([
                        'sched_start' => $p['start']->toDateString(), 'sched_end' => $p['end']->toDateString(),
                        'sched_work_centre_id' => $p['centre'], 'baseline_end' => $op->baseline_end?->toDateString() ?? $p['end']->toDateString(),
                    ]);
                }
                PO::query()->whereKey($o->id)->update(['sched_finish' => $plan[$o->id]['finish']->toDateString(),
                    'sched_earliest_start' => $o->sched_earliest_start?->toDateString(), 'priority' => $o->priority]);
            }

            ScheduleRun::create(['company_id' => app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id() ?? $actor?->company_id,
                'rule' => $rule, 'horizon_days' => $this->days, 'run_by' => $actor?->id, 'exceptions' => $result['exceptions'],
                'summary' => ['orders' => count($result['orders']), 'late' => collect($result['orders'])->where('late', true)->count(), 'bottleneck' => $result['bottleneck']]]);
        });
    }

    /**
     * Planned against actual: of the operations completed in a period that had a baseline, how many
     * finished on or before it, and by how much the rest slipped.
     */
    public function adherence(Carbon $from, Carbon $to): array
    {
        $ops = Op::query()->where('status', Op::COMPLETED)->whereNotNull('baseline_end')->whereBetween('completed_at', [$from, $to])->get();
        $onTime = $ops->filter(fn ($op) => $op->completed_at->copy()->startOfDay()->lte($op->baseline_end));
        $late = $ops->diff($onTime);

        return ['operations' => $ops->count(), 'on_time' => $onTime->count(),
            'percent' => $ops->count() ? round($onTime->count() / $ops->count() * 100, 1) : null,
            'average_slip_days' => $late->count() ? round($late->avg(fn ($op) => $op->baseline_end->diffInDays($op->completed_at->copy()->startOfDay())), 1) : 0];
    }
}
