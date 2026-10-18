<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Finance\Accounting\Models\FinExchangeRate;
use App\Modules\Operations\ProjectManagement\Models\CostEntry;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Earned value: whether a project is actually where its spend says it is.
 *
 * "60% of the budget is gone" and "60% of the work is done" are two different statements, and a
 * project reports the first far more readily than the second. Earned value is the discipline of
 * putting them side by side, and it is the one calculation a project manager is asked for that
 * this module could not previously produce.
 *
 * ── The three numbers ───────────────────────────────────────────────────────
 *
 *   PV  planned value    what should have been earned by today, per the plan
 *   EV  earned value     what has actually been earned — progress × what it was budgeted at
 *   AC  actual cost      what has actually been spent
 *
 * Everything else is arithmetic on those:
 *
 *   SPI = EV / PV   below 1 means behind schedule
 *   CPI = EV / AC   below 1 means over cost for the work done
 *   EAC = BAC / CPI the forecast, if today's efficiency continues
 *
 * ── Where each comes from ───────────────────────────────────────────────────
 *
 * **Weighted by money, not by task count.** Ten trivial tasks finished and one large one not
 * started is not 91% complete, and a count would say it was. Where a task carries no budget it
 * falls back to its duration, which is the next best proxy for its share of the work.
 *
 * **EV uses derived progress**, which comes from the evidence ledger (PM-ADR-010) — so the earned
 * figure inherits the same precedence as everything else: a measured quantity beats a typed
 * percentage, and nobody can improve SPI by claiming a bigger number.
 *
 * **PV prefers the baseline.** A plan that has been re-baselined three times is not a plan you can
 * measure against; the saved baseline is what was committed to. Without one, current planned dates
 * are used and the figure is honestly labelled as having no baseline behind it.
 */
class EarnedValueService
{
    /**
     * The full picture for one project.
     *
     * @return array{
     *     bac:float, pv:float, ev:float, ac:float, sv:float, cv:float,
     *     spi:?float, cpi:?float, eac:?float, etc:?float, vac:?float,
     *     percent_planned:float, percent_complete:float, has_baseline:bool, currency:?string
     * }
     */
    public function forProject(Project $project, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $tasks = Task::where('project_id', $project->id)->real()->get();

        $bac = $project->budget_amount !== null
            ? (float) $project->budget_amount
            : (float) $tasks->sum(fn (Task $t) => (float) $t->budget_amount);

        $weights = $this->weights($tasks);
        $totalWeight = array_sum($weights);

        $plannedFraction = $this->plannedFraction($tasks, $weights, $totalWeight, $asOf, $project, $hasBaseline);
        $completeFraction = $this->completeFraction($tasks, $weights, $totalWeight);

        $pv = round($bac * $plannedFraction, 2);
        $ev = round($bac * $completeFraction, 2);
        $ac = $this->actualCost($project);

        // Null rather than a number when the denominator is zero. An index of 1.0 on a project
        // that has earned nothing reads as "on plan", which is the opposite of what it means.
        $spi = $pv > 0 ? round($ev / $pv, 3) : null;
        $cpi = $ac > 0 ? round($ev / $ac, 3) : null;

        return [
            'bac'              => round($bac, 2),
            'pv'               => $pv,
            'ev'               => $ev,
            'ac'               => round($ac, 2),
            'sv'               => round($ev - $pv, 2),
            'cv'               => round($ev - $ac, 2),
            'spi'              => $spi,
            'cpi'              => $cpi,
            // The forecast. Only meaningful once there is enough spend for CPI to mean anything —
            // a project three days in with one invoice would forecast wildly and be believed.
            'eac'              => $cpi && $cpi > 0 ? round($bac / $cpi, 2) : null,
            'etc'              => $cpi && $cpi > 0 ? round(($bac / $cpi) - $ac, 2) : null,
            'vac'              => $cpi && $cpi > 0 ? round($bac - ($bac / $cpi), 2) : null,
            'percent_planned'  => round($plannedFraction * 100, 1),
            'percent_complete' => round($completeFraction * 100, 1),
            // Said plainly, because PV without a baseline is measured against a plan that may have
            // moved — which is a weaker claim and the reader should know it.
            'has_baseline'     => $hasBaseline,
            'currency'         => $project->currency,
        ];
    }

    /**
     * The portfolio, rolled up.
     *
     * Summed rather than averaged: a portfolio's SPI is the ratio of all value earned to all value
     * planned, and averaging per-project indices would let a tiny project on plan cancel out a
     * large one that is not.
     */
    public function portfolio(?string $reportingCurrency = null, $asOf = null): array
    {
        $reportingCurrency = strtoupper($reportingCurrency ?: config('finance.base_currency', 'ZAR'));
        $on = $asOf ? CarbonImmutable::parse($asOf) : CarbonImmutable::now();

        $pv = $ev = $ac = $bac = 0.0;
        $rates = [];
        $unconvertible = [];

        foreach (Project::query()->active()->get() as $project) {
            $currency = strtoupper($project->currency ?: $reportingCurrency);
            $rate = $this->rateTo($currency, $reportingCurrency, $on, $rates);

            /*
             * No rate: the project is left out and its currency is named.
             *
             * The alternative is to add its figures in anyway, which is what used to happen — a
             * portfolio total that silently mixed rand and dollars as though they were the same
             * unit. A total that omits something and says so can be acted on; one that is quietly
             * wrong by whatever the exchange rate happens to be cannot.
             */
            if ($rate === null) {
                $unconvertible[$currency] = true;

                continue;
            }

            $row = $this->forProject($project);

            $bac += $row['bac'] * $rate;
            $pv += $row['pv'] * $rate;
            $ev += $row['ev'] * $rate;
            $ac += $row['ac'] * $rate;
        }

        return [
            'bac' => round($bac, 2),
            'pv'  => round($pv, 2),
            'ev'  => round($ev, 2),
            'ac'  => round($ac, 2),
            'spi' => $pv > 0 ? round($ev / $pv, 3) : null,
            'cpi' => $ac > 0 ? round($ev / $ac, 3) : null,
            // Stated, not assumed. A number of this size with no currency on it is the reason
            // somebody adds it to another one.
            'currency' => $reportingCurrency,
            // The rates actually used and the date they were read on, so a report run in March
            // reproduces its March figures rather than silently re-stating at today's rate.
            'rates'     => $rates,
            'rate_date' => $on->toDateString(),
            // Named so the reader knows the total is not everything.
            'excluded_currencies' => array_keys($unconvertible),
        ];
    }

    /**
     * The rate from a project's currency into the reporting currency, cached per run.
     *
     * `FinExchangeRate::rateFor()` already handles the parts that are easy to get wrong — the most
     * recent rate on or before the date, and the inverse pair when only one direction is captured —
     * so this only decides what to do when there is no answer, which is to say so rather than
     * guess at 1.0. Treating an unknown rate as parity is how a dollar project quietly enters a
     * rand total at a sixth of its size.
     *
     * @param  array<string, float>  $rates
     */
    private function rateTo(string $from, string $to, CarbonImmutable $on, array &$rates): ?float
    {
        if ($from === $to) {
            return 1.0;
        }

        if (array_key_exists($from, $rates)) {
            return $rates[$from];
        }

        if (! Schema::hasTable('fin_exchange_rates')) {
            return null;
        }

        $rate = FinExchangeRate::rateFor($from, $to, $on->toDateString());

        return $rates[$from] = ($rate !== null && $rate > 0 ? $rate : null);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Each task's share of the project, by money where there is any and by duration otherwise.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @return array<int, float>
     */
    private function weights($tasks): array
    {
        $anyBudget = $tasks->contains(fn (Task $t) => (float) $t->budget_amount > 0);

        $weights = [];

        foreach ($tasks as $task) {
            $weights[$task->id] = $anyBudget
                ? (float) $task->budget_amount
                : max(1.0, (float) $task->duration_minutes);
        }

        // Every weight zero — a bill of quantities with no money and no durations. Fall back to
        // equal shares so the figures are at least defined.
        if (array_sum($weights) <= 0) {
            $weights = array_fill_keys($tasks->pluck('id')->all(), 1.0);
        }

        return $weights;
    }

    /**
     * How much of the work *should* be done by now.
     *
     * A task is counted pro rata through its window: a two-week task a week in contributes half its
     * weight. Straight-line within the task, which is the standard assumption and the only one
     * available without a resource-loaded curve.
     */
    private function plannedFraction($tasks, array $weights, float $totalWeight, CarbonImmutable $asOf, Project $project, ?bool &$hasBaseline = null): float
    {
        $baseline = $project->baselines()->latest('id')->first();
        $hasBaseline = $baseline !== null;

        $baselineDates = $hasBaseline
            ? $baseline->tasks()->get()->keyBy('task_id')
            : collect();

        if ($totalWeight <= 0) {
            return 0.0;
        }

        $planned = 0.0;

        foreach ($tasks as $task) {
            $row = $baselineDates->get($task->id);

            $start = $row?->planned_start ?? $task->planned_start;
            $end = $row?->planned_end ?? $task->planned_end;

            if (! $start || ! $end) {
                // Unscheduled work is not "late" — nobody has said when it happens, so it
                // contributes nothing to what should have been earned.
                continue;
            }

            $start = CarbonImmutable::parse($start);
            $end = CarbonImmutable::parse($end);

            $fraction = match (true) {
                $asOf >= $end   => 1.0,
                $asOf <= $start => 0.0,
                default         => $end->diffInSeconds($start) > 0
                    ? $asOf->diffInSeconds($start) / $end->diffInSeconds($start)
                    : 1.0,
            };

            $planned += $weights[$task->id] * $fraction;
        }

        return min(1.0, $planned / $totalWeight);
    }

    /** How much of the work actually is done, from derived progress. */
    private function completeFraction($tasks, array $weights, float $totalWeight): float
    {
        if ($totalWeight <= 0) {
            return 0.0;
        }

        $earned = 0.0;

        foreach ($tasks as $task) {
            $earned += $weights[$task->id] * (min(100.0, (float) $task->progress_pct) / 100);
        }

        return min(1.0, $earned / $totalWeight);
    }

    /**
     * What has been spent: approved labour plus paid and approved non-labour cost.
     *
     * Committed-but-unpaid is deliberately excluded. AC means money gone, and folding in an order
     * that has not been invoiced would depress CPI for work that has not been done yet — making a
     * healthy project look inefficient on the day it places its largest order.
     */
    private function actualCost(Project $project): float
    {
        $labour = (float) TimeEntry::where('project_id', $project->id)->approved()->sum('cost_amount');
        $other = (float) CostEntry::where('project_id', $project->id)->counted()->sum('amount');

        return $labour + $other;
    }
}
