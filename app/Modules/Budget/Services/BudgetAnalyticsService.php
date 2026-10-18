<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The charts behind the Budget BI page, and the dashboard's smaller ones.
 *
 * One service rather than a query per page, because a dashboard tile and a report that disagree
 * about the same figure destroy trust in both — and the second thing anybody does after noticing is
 * rebuild the number in a spreadsheet, which is how a company ends up with three versions of its
 * own budget.
 *
 * Everything is scoped by the active tenant through row-level security; nothing here adds a
 * company filter of its own, and nothing here should.
 */
class BudgetAnalyticsService
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetTrackingService $tracking,
    ) {
    }

    /**
     * Every chart, optionally narrowed to one financial year.
     *
     * @return array<string, mixed>
     */
    public function dashboard(?int $financialYearId = null): array
    {
        $budgets = Budget::query()
            ->with(['category:id,name', 'financialYear:id,name'])
            ->when($financialYearId, fn ($q, $id) => $q->where('financial_year_id', $id))
            ->get();

        $positions = $this->tracking->positions($budgets->pluck('id')->all());

        return [
            'position'            => $this->position($budgets, $positions),
            'by_category'         => $this->byCategory($budgets, $positions),
            'by_expenditure_type' => $this->byExpenditureType($budgets, $positions),
            'by_scope'            => $this->byScope($budgets, $positions),
            'utilisation_bands'   => $this->utilisationBands($budgets, $positions),
            'top_utilised'        => $this->topUtilised($budgets, $positions),
            'overruns'            => $this->overruns($budgets, $positions),
            'spend_trend'         => $this->spendTrend($financialYearId),
            'encumbrance_trend'   => $this->encumbranceTrend(),
            'adjustment_mix'      => $this->adjustmentMix(),
            'approval_backlog'    => $this->approvalBacklog(),
            'approval_cycle'      => $this->approvalCycle(),
            'alerts_by_severity'  => $this->alertsBySeverity(),
            'spend_by_source'     => $this->spendBySource(),
        ];
    }

    /** Headline numbers, shared by the dashboard and the analytics page. */
    public function summary(?int $financialYearId = null): array
    {
        $ids = Budget::query()
            ->when($financialYearId, fn ($q, $id) => $q->where('financial_year_id', $id))
            ->pluck('id')->all();

        $totals = ['allocated' => 0.0, 'reserved' => 0.0, 'committed' => 0.0, 'actual' => 0.0, 'overrides' => 0.0, 'available' => 0.0];

        foreach ($this->budgets->amountsForAll($ids) as $amounts) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $amounts[$key];
            }
        }

        $ceiling = $totals['allocated'] + $totals['overrides'];

        return $totals + [
            'budgets'     => count($ids),
            'utilisation' => $ceiling > 0
                ? round((($totals['reserved'] + $totals['committed'] + $totals['actual']) / $ceiling) * 100, 1)
                : 0.0,
        ];
    }

    // ── Composition ──────────────────────────────────────────────────────────

    private function position($budgets, array $positions): array
    {
        $out = ['reserved' => 0.0, 'committed' => 0.0, 'actual' => 0.0, 'available' => 0.0];

        foreach ($budgets as $budget) {
            $a = $positions[$budget->id] ?? null;

            if (! $a) {
                continue;
            }

            $out['reserved']  += $a['reserved'];
            $out['committed'] += $a['committed'];
            $out['actual']    += $a['actual'];
            // A negative balance is an overrun, not negative headroom to draw on the chart.
            $out['available'] += max(0, $a['available']);
        }

        return array_map(fn ($v) => round($v, 2), $out);
    }

    private function byCategory($budgets, array $positions): array
    {
        return $this->groupBy($budgets, $positions, fn (Budget $b) => $b->category?->name ?: __('budget_management.analytics.uncategorised'));
    }

    private function byExpenditureType($budgets, array $positions): array
    {
        return $this->groupBy($budgets, $positions, fn (Budget $b) => $b->expenditure_type ?: '—');
    }

    private function byScope($budgets, array $positions): array
    {
        return $this->groupBy($budgets, $positions, fn (Budget $b) => $b->scope_type ?: '—');
    }

    /** @return array<int, array{label:string, allocated:float, committed:float, actual:float}> */
    private function groupBy($budgets, array $positions, callable $key): array
    {
        $groups = [];

        foreach ($budgets as $budget) {
            $a = $positions[$budget->id] ?? null;

            if (! $a) {
                continue;
            }

            $label = $key($budget);
            $groups[$label] ??= ['label' => $label, 'allocated' => 0.0, 'committed' => 0.0, 'actual' => 0.0];
            $groups[$label]['allocated'] += $a['allocated'];
            $groups[$label]['committed'] += $a['reserved'] + $a['committed'];
            $groups[$label]['actual']    += $a['actual'];
        }

        $rows = array_values($groups);
        usort($rows, fn ($a, $b) => $b['allocated'] <=> $a['allocated']);

        return array_map(fn ($r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r), $rows);
    }

    /**
     * How many budgets sit in each health band.
     *
     * The bands are the ones people actually manage by — comfortable, watch it, nearly gone,
     * already over — rather than a histogram nobody reads off.
     */
    private function utilisationBands($budgets, array $positions): array
    {
        $bands = ['under_50' => 0, 'from_50' => 0, 'from_80' => 0, 'from_95' => 0, 'over' => 0];

        foreach ($budgets as $budget) {
            $a = $positions[$budget->id] ?? null;

            if (! $a || $a['allocated'] <= 0) {
                continue;
            }

            $pct = $a['committed_pct'] ?? 0;

            match (true) {
                $a['available'] < 0 => $bands['over']++,
                $pct >= 95          => $bands['from_95']++,
                $pct >= 80          => $bands['from_80']++,
                $pct >= 50          => $bands['from_50']++,
                default             => $bands['under_50']++,
            };
        }

        return $bands;
    }

    private function topUtilised($budgets, array $positions, int $limit = 10): array
    {
        $rows = [];

        foreach ($budgets as $budget) {
            $a = $positions[$budget->id] ?? null;

            if (! $a || $a['allocated'] <= 0) {
                continue;
            }

            $rows[] = [
                'id'        => $budget->id,
                'code'      => $budget->code,
                'name'      => $budget->name,
                'allocated' => $a['allocated'],
                'used'      => round($a['reserved'] + $a['committed'] + $a['actual'], 2),
                'percent'   => $a['committed_pct'] ?? 0,
            ];
        }

        usort($rows, fn ($a, $b) => $b['percent'] <=> $a['percent']);

        return array_slice($rows, 0, $limit);
    }

    private function overruns($budgets, array $positions, int $limit = 10): array
    {
        $rows = [];

        foreach ($budgets as $budget) {
            $a = $positions[$budget->id] ?? null;

            if (! $a || $a['available'] >= 0) {
                continue;
            }

            $rows[] = [
                'id'        => $budget->id,
                'code'      => $budget->code,
                'name'      => $budget->name,
                'allocated' => $a['allocated'],
                'over_by'   => round(abs($a['available']), 2),
            ];
        }

        usort($rows, fn ($a, $b) => $b['over_by'] <=> $a['over_by']);

        return array_slice($rows, 0, $limit);
    }

    // ── Trends ───────────────────────────────────────────────────────────────

    /** Actual spend by month, and the same figure accumulated — a burn line needs both. */
    private function spendTrend(?int $financialYearId): array
    {
        $rows = DB::table('budget_consumptions as c')
            ->join('budgets as b', 'b.id', '=', 'c.budget_id')
            ->when($financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->whereNull('b.deleted_at')
            ->where('c.created_at', '>=', now()->subMonths(12)->startOfMonth())
            ->selectRaw("to_char(c.created_at, 'YYYY-MM') as month, sum(c.amount) as amount")
            ->groupBy('month')->orderBy('month')->get();

        $running = 0.0;

        return $rows->map(function ($row) use (&$running) {
            $running += (float) $row->amount;

            return [
                'month'      => $row->month,
                'amount'     => round((float) $row->amount, 2),
                'cumulative' => round($running, 2),
            ];
        })->all();
    }

    /** Reservations and commitments raised per month — the pipeline ahead of actual spend. */
    private function encumbranceTrend(): array
    {
        $since = now()->subMonths(12)->startOfMonth();

        $reserved = DB::table('budget_reservations')
            ->where('created_at', '>=', $since)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as month, sum(amount) as amount")
            ->groupBy('month')->pluck('amount', 'month');

        $committed = DB::table('budget_commitments')
            ->where('created_at', '>=', $since)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as month, sum(amount) as amount")
            ->groupBy('month')->pluck('amount', 'month');

        $months = collect(range(0, 11))
            ->map(fn ($i) => Carbon::now()->subMonths(11 - $i)->format('Y-m'));

        return $months->map(fn ($month) => [
            'month'     => $month,
            'reserved'  => round((float) ($reserved[$month] ?? 0), 2),
            'committed' => round((float) ($committed[$month] ?? 0), 2),
        ])->all();
    }

    private function adjustmentMix(): array
    {
        return DB::table('budget_adjustments')
            ->whereNull('deleted_at')
            ->selectRaw('type, status, count(*) as n, coalesce(sum(amount), 0) as amount')
            ->groupBy('type', 'status')
            ->get()
            ->map(fn ($r) => [
                'type'   => $r->type,
                'status' => $r->status,
                'count'  => (int) $r->n,
                'amount' => round((float) $r->amount, 2),
            ])->all();
    }

    /**
     * How long the queue has been waiting, in the buckets people escalate on.
     *
     * "Four things over a month old" is a sentence somebody acts on. An average age is not.
     */
    private function approvalBacklog(): array
    {
        $rows = DB::table('budget_approval_requests')
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->get(['created_at', 'subject_type']);

        $buckets = ['today' => 0, 'this_week' => 0, 'over_a_week' => 0, 'over_a_month' => 0];

        foreach ($rows as $row) {
            $days = Carbon::parse($row->created_at)->diffInDays(now());

            match (true) {
                $days >= 30 => $buckets['over_a_month']++,
                $days >= 7  => $buckets['over_a_week']++,
                $days >= 1  => $buckets['this_week']++,
                default     => $buckets['today']++,
            };
        }

        return $buckets;
    }

    /** Average hours from submission to decision, by what was being decided. */
    private function approvalCycle(): array
    {
        return DB::table('budget_approval_requests')
            ->whereNotNull('decided_at')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw('subject_type, count(*) as n, avg(extract(epoch from (decided_at - created_at)) / 3600) as hours')
            ->groupBy('subject_type')
            ->get()
            ->map(fn ($r) => [
                'label' => $r->subject_type,
                'count' => (int) $r->n,
                'hours' => round((float) $r->hours, 1),
            ])->all();
    }

    private function alertsBySeverity(): array
    {
        $rows = DB::table('budget_alerts')
            ->where('status', '!=', 'resolved')
            ->selectRaw('severity, count(*) as n')
            ->groupBy('severity')
            ->pluck('n', 'severity');

        return collect(['info', 'warning', 'critical', 'block'])
            ->map(fn ($severity) => ['label' => $severity, 'count' => (int) ($rows[$severity] ?? 0)])
            ->all();
    }

    /** Where the money actually went — by the system that posted it. */
    private function spendBySource(): array
    {
        return DB::table('budget_consumptions')
            ->selectRaw("coalesce(source_type, 'manual') as label, sum(amount) as amount, count(*) as n")
            ->groupBy('label')
            ->orderByDesc('amount')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'label'  => $r->label,
                'amount' => round((float) $r->amount, 2),
                'count'  => (int) $r->n,
            ])->all();
    }
}
