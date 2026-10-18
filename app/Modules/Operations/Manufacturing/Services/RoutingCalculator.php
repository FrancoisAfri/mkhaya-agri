<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Estimated lead time and capacity requirements for making a quantity (MFG-003).
 *
 * The parts that make this more than adding up minutes:
 *
 *  - Yield runs backwards. To end with 100 good units from a routing that keeps 95% at cutting and
 *    98% at assembly, assembly must start 102.04 and cutting 107.41. Each operation is timed for
 *    the quantity that actually passes through it, not for the quantity ordered.
 *  - Batches. Above the maximum batch size the order runs as several batches, and setup is paid
 *    per batch. Below the minimum it is flagged, because the plant cannot run it as asked.
 *  - Parallel operations. Operations sharing a group run at the same time, so the group takes as
 *    long as its slowest member -- lead time follows the critical path, capacity counts every one.
 *  - Subcontracting. A subcontracted operation takes calendar days at the supplier and no minutes
 *    of this plant's capacity.
 *  - The calendar. Minutes become a finish date through the factory's working calendar, so a job
 *    that needs twenty hours does not "finish tomorrow" across a weekend.
 */
class RoutingCalculator
{
    public function __construct(private readonly FactoryCalendar $calendar) {}

    /**
     * @return array{
     *   quantity: float, batches: int, batch_size: float, below_min_batch: bool,
     *   start_quantity: float, lead_minutes: float, subcontract_days: int,
     *   operations: list<array<string, mixed>>, capacity: list<array<string, mixed>>,
     *   start: ?string, estimated_finish: ?string, warnings: list<string>
     * }
     */
    public function estimate(Routing $routing, float $quantity, ?Factory $factory = null, ?DateTimeInterface $start = null): array
    {
        if ($quantity <= 0) {
            throw new DomainException('Estimate for what quantity? A plan for zero is not a plan.');
        }

        $ops = $routing->operations()->with('workCentre')->get();

        if ($ops->isEmpty()) {
            throw new DomainException("Routing revision {$routing->revision} has no operations to estimate.");
        }

        $warnings = [];
        $max = $routing->max_batch_size !== null ? (float) $routing->max_batch_size : null;
        $min = $routing->min_batch_size !== null ? (float) $routing->min_batch_size : null;

        $batches = $max ? max(1, (int) ceil($quantity / $max)) : 1;
        $batchSize = round($quantity / $batches, 6);
        $belowMin = $min !== null && $batchSize + 1e-9 < $min;

        if ($belowMin) {
            $warnings[] = sprintf('A batch of %s is below the minimum of %s; the plant cannot run it as asked.',
                $this->num($batchSize), $this->num($min));
        }

        // Quantity entering each operation, worked backwards from the good quantity wanted.
        $through = $this->quantitiesThrough($ops, $quantity);

        $rows = [];
        foreach ($ops as $op) {
            $units = $through[$op->id];
            $minutes = $op->is_subcontracted ? 0.0 : $this->operationMinutes($op, $units, $batches);

            $rows[] = [
                'operation_id' => $op->uuid,
                'sequence' => $op->sequence,
                'name' => $op->name,
                'work_centre_id' => $op->work_centre_id,
                'work_centre' => $op->workCentre?->name,
                'machine_type' => $op->machine_type,
                'parallel_group' => $op->parallel_group,
                'is_subcontracted' => (bool) $op->is_subcontracted,
                'units_in' => round($units, 4),
                'setup_minutes' => $op->is_subcontracted ? 0.0 : round((float) $op->setup_minutes * $batches, 2),
                'minutes' => round($minutes, 2),
                'move_minutes' => (float) $op->move_minutes,
                'labour_minutes' => round($minutes * (float) $op->labour_headcount * ((float) $op->labour_percent_of_time / 100), 2),
                'subcontract_days' => $op->is_subcontracted ? (int) $op->subcontract_lead_days : 0,
            ];
        }

        [$leadMinutes, $subcontractDays] = $this->criticalPath($rows);

        $capacity = collect($rows)->filter(fn ($r) => ! $r['is_subcontracted'])
            ->groupBy(fn ($r) => $r['work_centre_id'] ? 'wc:'.$r['work_centre_id'] : 'type:'.$r['machine_type'])
            ->map(fn ($g) => [
                'work_centre_id' => $g->first()['work_centre_id'],
                'work_centre' => $g->first()['work_centre'],
                'machine_type' => $g->first()['machine_type'],
                'minutes' => round($g->sum('minutes'), 2),
                'hours' => round($g->sum('minutes') / 60, 2),
                'labour_minutes' => round($g->sum('labour_minutes'), 2),
            ])->values()->all();

        $finish = null;
        $startAt = null;
        if ($factory) {
            $startAt = Carbon::instance($start ?? now());
            $finish = $this->finishDate($factory, $startAt, $leadMinutes, $subcontractDays, $warnings);
        }

        return [
            'quantity' => $quantity,
            'batches' => $batches,
            'batch_size' => $batchSize,
            'below_min_batch' => $belowMin,
            'start_quantity' => round($through[$ops->first()->id], 4),
            'lead_minutes' => round($leadMinutes, 2),
            'subcontract_days' => $subcontractDays,
            'operations' => $rows,
            'capacity' => $capacity,
            'start' => $startAt?->toDateString(),
            'estimated_finish' => $finish,
            'warnings' => $warnings,
        ];
    }

    /**
     * Operations that must be finished before this one may be completed (MFG-003's routing engine).
     *
     * Every mandatory operation with a lower sequence, except those sharing this one's parallel
     * group -- they run alongside it, not before it.
     *
     * @return Collection<int, RoutingOperation>
     */
    public function prerequisites(RoutingOperation $operation): Collection
    {
        return RoutingOperation::query()
            ->where('routing_id', $operation->routing_id)
            ->where('sequence', '<', $operation->sequence)
            ->where('is_mandatory', true)
            ->when($operation->parallel_group !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('parallel_group')->orWhere('parallel_group', '!=', $operation->parallel_group)))
            ->orderBy('sequence')
            ->get();
    }

    /** @return array<int, float> operation id => units entering it */
    private function quantitiesThrough(Collection $ops, float $good): array
    {
        $through = [];
        $needed = $good;

        foreach ($ops->sortByDesc('sequence') as $op) {
            $yield = max(0.0001, (float) $op->expected_yield_percent / 100);
            $needed = $needed / $yield;
            $through[$op->id] = $needed;
        }

        return $through;
    }

    private function operationMinutes(RoutingOperation $op, float $units, int $batches): float
    {
        $run = $op->run_basis === RoutingOperation::PER_BATCH
            ? (float) $op->run_minutes * $batches
            : (float) $op->run_minutes * $units;

        return (float) $op->setup_minutes * $batches + $run;
    }

    /**
     * Lead time along the critical path: consecutive operations of one parallel group form a stage
     * that takes as long as its slowest member; every other operation is a stage of its own.
     *
     * @return array{0: float, 1: int}
     */
    private function criticalPath(array $rows): array
    {
        $stages = [];
        foreach ($rows as $r) {
            $key = $r['parallel_group'] !== null ? 'g:'.$r['parallel_group'] : 's:'.$r['sequence'];
            $stages[$key][] = $r;
        }

        $minutes = 0.0;
        $days = 0;
        foreach ($stages as $members) {
            $minutes += max(array_map(fn ($m) => $m['minutes'] + $m['move_minutes'], $members));
            $days += max(array_map(fn ($m) => $m['subcontract_days'], $members));
        }

        return [$minutes, $days];
    }

    /** Consume working minutes day by day through the factory calendar, then add subcontract days. */
    private function finishDate(Factory $factory, Carbon $start, float $minutes, int $subcontractDays, array &$warnings): ?string
    {
        $left = $minutes;
        $day = $start->copy()->startOfDay();
        $guard = 0;

        while ($left > 0.0001) {
            if (++$guard > 730) {
                $warnings[] = 'The factory calendar has too little working time to finish this within two years. Check its shifts.';

                return null;
            }

            $left -= $this->calendar->day($factory, $day)['minutes'];

            if ($left > 0.0001) {
                $day->addDay();
            }
        }

        return $day->addDays($subcontractDays)->toDateString();
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
