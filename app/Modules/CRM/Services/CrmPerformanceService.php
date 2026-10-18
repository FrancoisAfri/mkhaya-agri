<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmTarget;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Computes sales performance — a user's (or a team's) actual activity and deal
 * outcomes for a period, measured against their targets. Powers the rep, team
 * and executive dashboards plus the performance scheduled reports.
 */
class CrmPerformanceService
{
    /** Resolve a period keyword to a [from, to] window. */
    public function window(string $period): array
    {
        return match ($period) {
            'weekly', 'week'  => [now()->startOfWeek(), now()->endOfWeek()],
            'monthly', 'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'quarter'         => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year'            => [now()->startOfYear(), now()->endOfYear()],
            default           => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    /**
     * Actual metric values for a user within a window.
     *
     * @return array<string, float>
     */
    public function actuals(int $userId, CarbonInterface $from, CarbonInterface $to): array
    {
        $byType = CrmActivity::where('owner_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type');

        $wonDeals = CrmDeal::where('owner_id', $userId)
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to]);

        return [
            'calls'      => (float) ($byType['call'] ?? 0),
            'emails'     => (float) ($byType['email'] ?? 0),
            'meetings'   => (float) ($byType['meeting'] ?? 0),
            'activities' => (float) $byType->sum(),
            'leads'      => (float) CrmLead::where('owner_id', $userId)->whereBetween('created_at', [$from, $to])->count(),
            'deals_won'  => (float) (clone $wonDeals)->count(),
            'revenue'    => (float) (clone $wonDeals)->sum('amount'),
            'deals_lost' => (float) CrmDeal::where('owner_id', $userId)->where('status', 'lost')->whereBetween('closed_at', [$from, $to])->count(),
            'open_deals' => (float) CrmDeal::where('owner_id', $userId)->where('status', 'open')->count(),
            'open_value' => (float) CrmDeal::where('owner_id', $userId)->where('status', 'open')->sum('amount'),
        ];
    }

    /** A user's targets keyed by metric for a given period type (weekly|monthly). */
    public function targets(int $userId, string $period): array
    {
        return CrmTarget::where('user_id', $userId)->where('period', $period)
            ->pluck('target_value', 'metric')->toArray();
    }

    /**
     * Full scorecard for a user: each target metric with actual, target and % of
     * goal, plus the raw actuals for the period.
     */
    public function scorecard(int $userId, string $period = 'monthly'): array
    {
        [$from, $to] = $this->window($period);
        $actuals = $this->actuals($userId, $from, $to);
        $targets = $this->targets($userId, $period);

        $metrics = [];
        foreach (CrmTarget::METRICS as $key => $meta) {
            $actual = $actuals[$key] ?? 0;
            $target = (float) ($targets[$key] ?? 0);
            $metrics[] = [
                'metric'  => $key,
                'label'   => $meta['label'],
                'icon'    => $meta['icon'],
                'format'  => $meta['format'],
                'actual'  => $actual,
                'target'  => $target,
                'percent' => $target > 0 ? min(999, round($actual / $target * 100)) : null,
                'on_track'=> $target > 0 ? $actual >= $this->expectedPace($target, $period, $to) : null,
            ];
        }

        return [
            'period'  => $period,
            'from'    => $from->toDateString(),
            'to'      => $to->toDateString(),
            'metrics' => $metrics,
            'actuals' => $actuals,
        ];
    }

    /**
     * Both weekly and monthly progress per metric in one structure, so a
     * dashboard can show the % of weekly AND monthly target side by side.
     */
    public function dualScorecard(int $userId): array
    {
        $weekly  = $this->scorecard($userId, 'weekly');
        $monthly = $this->scorecard($userId, 'monthly');

        $w = collect($weekly['metrics'])->keyBy('metric');
        $m = collect($monthly['metrics'])->keyBy('metric');

        $metrics = [];
        foreach (CrmTarget::METRICS as $key => $meta) {
            $metrics[] = [
                'metric'  => $key,
                'label'   => $meta['label'],
                'icon'    => $meta['icon'],
                'format'  => $meta['format'],
                'weekly'  => $this->slice($w->get($key)),
                'monthly' => $this->slice($m->get($key)),
            ];
        }

        return ['metrics' => $metrics, 'weekly' => $weekly, 'monthly' => $monthly];
    }

    /** Revenue attainment (% of target) for a user, for one period. */
    public function revenueAttainment(int $userId, string $period): ?int
    {
        foreach ($this->scorecard($userId, $period)['metrics'] as $m) {
            if ($m['metric'] === 'revenue') {
                return $m['percent'];
            }
        }
        return null;
    }

    private function slice(?array $metric): array
    {
        return [
            'actual'  => $metric['actual'] ?? 0,
            'target'  => $metric['target'] ?? 0,
            'percent' => $metric['percent'] ?? null,
        ];
    }

    /** Expected pace so far this period (pro-rata) — for the "on track" flag. */
    private function expectedPace(float $target, string $period, CarbonInterface $to): float
    {
        [$start, $end] = $this->window($period);
        $total = max(1, $start->diffInDays($end) + 1);
        $elapsed = min($total, $start->diffInDays(Carbon::now()->min($end)) + 1);

        return $target * ($elapsed / $total);
    }
}
