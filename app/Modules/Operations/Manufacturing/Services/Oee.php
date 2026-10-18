<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\OeeSetting;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use Illuminate\Support\Carbon;

/**
 * Overall Equipment Effectiveness for a work centre over a period (MFG-013).
 *
 *   Availability = run time / planned production time
 *   Performance  = (ideal cycle time x total count) / run time
 *   Quality      = good count / total count
 *   OEE          = Availability x Performance x Quality
 *
 * Planned production time is the factory calendar's shift time (less planned stops where the
 * centre's settings say so); run time is planned time less unplanned stops; counts come from the
 * operations completed at the centre. Ideal cycle time is configured per centre, or taken from the
 * routing's run time when the centre says so.
 *
 * A figure that cannot be calculated is null with the reason, never 0 or 100%. A performance over
 * 100% is reported as invalid: it means the ideal cycle time is wrong, not that the machine beat it.
 */
class Oee
{
    public function __construct(private readonly FactoryCalendar $calendar) {}

    public function forCentre(WorkCentre $centre, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $settings = OeeSetting::query()->where('work_centre_id', $centre->id)->first();
        $flags = [];

        // ── Time ───────────────────────────────────────────────────────────────────────────
        $scheduled = (float) $this->calendar->minutesBetween($centre->factory()->firstOrFail(), $from, $to, $centre->production_line_id);
        $stops = DowntimeEvent::query()->where('work_centre_id', $centre->id)
            ->where('started_at', '<', $to)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->with('reason')->get();
        $minutesIn = fn ($e) => max(0, Carbon::parse(max($e->started_at, $from))->diffInSeconds(Carbon::parse(min($e->ended_at ?? now(), $to))) / 60);
        $planned = $stops->filter(fn ($e) => $e->reason?->is_planned)->sum($minutesIn);
        $unplanned = $stops->reject(fn ($e) => $e->reason?->is_planned)->sum($minutesIn);

        $plannedTime = $scheduled - (($settings?->exclude_planned_downtime ?? true) ? $planned : 0);
        $runTime = max(0, $plannedTime - $unplanned);

        if ($scheduled <= 0) {
            $flags[] = 'No shifts are scheduled for this centre\'s factory in the period, so there is no planned production time.';
        }

        // ── Counts ─────────────────────────────────────────────────────────────────────────
        $ops = Op::query()->where('work_centre_id', $centre->id)->where('status', Op::COMPLETED)
            ->whereBetween('completed_at', [$from, $to])->get();
        $good = (float) $ops->sum('qty_completed');
        $total = $good + (float) $ops->sum('qty_rejected') + (float) $ops->sum('qty_scrapped');

        if ($total <= 0) {
            $flags[] = 'No operations were completed at this centre in the period.';
        }

        // ── Ideal cycle time ───────────────────────────────────────────────────────────────
        $idealMinutes = null;
        if ($settings?->ideal_from_routing) {
            $idealMinutes = 0.0;
            foreach ($ops as $op) {
                $rop = $op->routing_operation_id ? RoutingOperation::withoutGlobalScopes()->find($op->routing_operation_id) : null;
                $perUnit = $rop && $rop->run_basis === 'PER_UNIT' ? (float) $rop->run_minutes : null;
                if ($perUnit === null) {
                    $flags[] = "Operation {$op->sequence} {$op->name} has no per-unit run time on its routing to use as the ideal cycle.";
                    $idealMinutes = null;
                    break;
                }
                $idealMinutes += $perUnit * ((float) $op->qty_completed + (float) $op->qty_rejected + (float) $op->qty_scrapped);
            }
        } elseif ($settings?->ideal_cycle_seconds) {
            $idealMinutes = (float) $settings->ideal_cycle_seconds * $total / 60;
        } else {
            $flags[] = 'No ideal cycle time is configured for this centre, so performance cannot be calculated.';
        }

        // ── The three factors ──────────────────────────────────────────────────────────────
        $availability = $plannedTime > 0 ? $runTime / $plannedTime : null;
        $performance = $idealMinutes !== null && $runTime > 0 && $total > 0 ? $idealMinutes / $runTime : null;
        $quality = $total > 0 ? $good / $total : null;

        if ($performance !== null && $performance > 1.0001) {
            $flags[] = sprintf('Performance works out at %.1f%%: the ideal cycle time is slower than the centre actually ran, so it is reported as invalid.', $performance * 100);
            $performance = null;
        }

        $oee = $availability !== null && $performance !== null && $quality !== null ? $availability * $performance * $quality : null;

        return [
            'work_centre' => ['id' => $centre->id, 'code' => $centre->code, 'name' => $centre->name],
            'availability' => $this->pct($availability), 'performance' => $this->pct($performance),
            'quality' => $this->pct($quality), 'oee' => $this->pct($oee),
            'inputs' => [
                'scheduled_minutes' => round($scheduled, 1), 'planned_downtime_minutes' => round($planned, 1),
                'planned_production_minutes' => round($plannedTime, 1), 'unplanned_downtime_minutes' => round($unplanned, 1),
                'run_minutes' => round($runTime, 1), 'ideal_minutes' => $idealMinutes !== null ? round($idealMinutes, 1) : null,
                'good' => $good, 'total' => $total, 'operations' => $ops->count(),
            ],
            'definitions' => [
                'ideal' => $settings?->ideal_from_routing ? 'routing run time per unit' : ($settings?->ideal_cycle_seconds ? "{$settings->ideal_cycle_seconds} s per unit" : null),
                'planned_downtime' => ($settings?->exclude_planned_downtime ?? true) ? 'excluded from planned time' : 'counted against availability',
                'good_output' => 'completed quantity; total adds rejected and scrapped',
            ],
            'flags' => array_values(array_unique($flags)),
        ];
    }

    private function pct(?float $v): ?float
    {
        return $v === null ? null : round($v * 100, 1);
    }
}
