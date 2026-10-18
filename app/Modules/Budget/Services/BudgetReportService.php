<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * BI datasets for Budget Management.
 *
 * One flat table per area of the module — header row plus rows, nothing nested — so each goes
 * straight into Power BI, Excel or a warehouse without anybody unpicking JSON first. That flatness
 * is the point: a report nobody can load into their own tool is a report they will rebuild badly
 * from a database export, and then there are two versions of the budget.
 *
 * Nothing is recomputed here that the module already knows. The position of every budget comes from
 * the same aggregate the tracking page and the fund bar read, so an exported variance report cannot
 * disagree with the screen it was exported from — and the export is what reaches the board pack.
 */
class BudgetReportService
{
    /** Rows per dataset on screen. The CSV export is unbounded — that is what it is for. */
    private const PREVIEW_LIMIT = 500;

    private ?CarbonImmutable $from = null;

    private ?CarbonImmutable $to = null;

    private ?int $financialYearId = null;

    private ?int $budgetId = null;

    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BudgetPlanningService $planning,
    )
    {
    }

    /**
     * Every report, keyed, with the area it belongs to and where its rows are captured.
     *
     * `source` answers the question every empty report provokes — "where do I enter this?" A BI page
     * showing an empty Commitments table and saying nothing about how it fills is a page that makes
     * people think the feature is broken, when in fact no purchase order has been issued yet.
     *
     * @return array<string, array{label:string, area:string, source:string}>
     */
    public static function reportKeys(): array
    {
        return [
            'register'     => ['label' => 'Budget register',      'area' => 'budgets',    'source' => 'budgets'],
            'utilisation'  => ['label' => 'Utilisation',          'area' => 'budgets',    'source' => 'ledger'],
            'variance'     => ['label' => 'Budget vs actual',     'area' => 'budgets',    'source' => 'ledger'],
            'variance_explanations' => ['label' => 'Variance explanations', 'area' => 'budgets', 'source' => 'phasing'],
            'allocations'  => ['label' => 'Allocation lines',     'area' => 'budgets',    'source' => 'allocations'],
            'reservations' => ['label' => 'Reservations',         'area' => 'ledger',     'source' => 'reservations'],
            'commitments'  => ['label' => 'Commitments',          'area' => 'ledger',     'source' => 'commitments'],
            'consumptions' => ['label' => 'Actual spend',         'area' => 'ledger',     'source' => 'consumptions'],
            'adjustments'  => ['label' => 'Adjustments',          'area' => 'governance', 'source' => 'adjustments'],
            'overrides'    => ['label' => 'Overspend overrides',  'area' => 'governance', 'source' => 'overrides'],
            'approvals'    => ['label' => 'Approval requests',    'area' => 'governance', 'source' => 'approvals'],
            'decisions'    => ['label' => 'Approval trail',       'area' => 'governance', 'source' => 'decisions'],
            'alerts'       => ['label' => 'Alerts & blockers',    'area' => 'tracking',   'source' => 'alerts'],
        ];
    }

    public function withRange(?string $from, ?string $to): self
    {
        $this->from = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
        $this->to = $to ? CarbonImmutable::parse($to)->endOfDay() : null;

        return $this;
    }

    public function forFinancialYear(?int $id): self
    {
        $this->financialYearId = $id;

        return $this;
    }

    public function forBudget(?int $id): self
    {
        $this->budgetId = $id;

        return $this;
    }

    /** Headline figures for the page. */
    public function summary(): array
    {
        $ids = $this->scopedBudgets()->pluck('id')->all();
        $totals = ['allocated' => 0.0, 'reserved' => 0.0, 'committed' => 0.0, 'actual' => 0.0, 'available' => 0.0];

        foreach ($this->budgets->amountsForAll($ids) as $amounts) {
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + $amounts[$key], 2);
            }
        }

        return ['budgets' => count($ids)] + $totals;
    }

    /** @return array{0: string[], 1: array<int, array<int, mixed>>} */
    public function dataset(string $key, bool $full = false): array
    {
        $limit = $full ? null : self::PREVIEW_LIMIT;

        return match ($key) {
            'register'     => $this->register($limit),
            'utilisation'  => $this->utilisation($limit),
            'variance'     => $this->variance($limit),
            'variance_explanations' => $this->varianceExplanations($limit),
            'allocations'  => $this->allocations($limit),
            'reservations' => $this->ledger('budget_reservations', $limit),
            'commitments'  => $this->ledger('budget_commitments', $limit),
            'consumptions' => $this->ledger('budget_consumptions', $limit),
            'adjustments'  => $this->adjustments($limit),
            'overrides'    => $this->overrides($limit),
            'approvals'    => $this->approvals($limit),
            'decisions'    => $this->decisions($limit),
            'alerts'       => $this->alerts($limit),
            default        => [[], []],
        };
    }

    // ── Budgets ──────────────────────────────────────────────────────────────

    private function register(?int $limit): array
    {
        $rows = $this->scopedBudgets()
            ->with(['financialYear:id,name', 'category:id,name', 'period:id,name'])
            ->orderBy('code')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get();

        return [
            ['Code', 'Name', 'Financial year', 'Period', 'Category', 'Type', 'Scope', 'Scope label', 'Currency', 'Allocated', 'Status', 'Created'],
            $rows->map(fn (Budget $b) => [
                $b->code, $b->name, $b->financialYear?->name, $b->period?->name, $b->category?->name,
                $b->expenditure_type, $b->scope_type, $b->scope_label, $b->currency,
                (float) $b->allocated_amount, $b->status, $b->created_at?->toDateString(),
            ])->all(),
        ];
    }

    private function utilisation(?int $limit): array
    {
        $budgets = $this->scopedBudgets()->with('category:id,name')->orderBy('code')
            ->when($limit, fn ($q, $n) => $q->limit($n))->get();

        $positions = $this->budgets->amountsForAll($budgets->pluck('id')->all());

        return [
            ['Code', 'Name', 'Category', 'Type', 'Allocated', 'Approved overrides', 'Reserved', 'Committed', 'Actual', 'Available', 'Used %'],
            $budgets->map(function (Budget $b) use ($positions) {
                $a = $positions[$b->id] ?? ['allocated' => 0, 'overrides' => 0, 'reserved' => 0, 'committed' => 0, 'actual' => 0, 'available' => 0];
                $ceiling = $a['allocated'] + $a['overrides'];

                return [
                    $b->code, $b->name, $b->category?->name, $b->expenditure_type,
                    $a['allocated'], $a['overrides'], $a['reserved'], $a['committed'], $a['actual'], $a['available'],
                    $ceiling > 0 ? round((($a['reserved'] + $a['committed'] + $a['actual']) / $ceiling) * 100, 1) : 0,
                ];
            })->all(),
        ];
    }

    /**
     * Allocated against spent, with the variance signed the way finance reads it.
     *
     * Positive variance means under budget. That convention is stated here because the opposite one
     * is equally common, and a column labelled only "Variance" is one half the readers get backwards.
     */
    private function variance(?int $limit): array
    {
        $budgets = $this->scopedBudgets()->orderBy('code')
            ->when($limit, fn ($q, $n) => $q->limit($n))->get();

        $positions = $this->budgets->amountsForAll($budgets->pluck('id')->all());

        return [
            ['Code', 'Name', 'Allocated', 'Actual', 'Variance (under budget +)', 'Variance %', 'Reforecast year end', 'Reforecast variance', 'Unexplained variances', 'Status'],
            $budgets->map(function (Budget $b) use ($positions) {
                $a = $positions[$b->id] ?? ['allocated' => 0, 'actual' => 0];
                $variance = round($a['allocated'] - $a['actual'], 2);
                $phasing = $this->planning->phasing($b);
                $reforecast = $this->planning->reforecast($b, $phasing, $this->budgets->amounts($b));

                return [
                    $b->code, $b->name, $a['allocated'], $a['actual'], $variance,
                    $a['allocated'] > 0 ? round(($variance / $a['allocated']) * 100, 1) : 0,
                    $reforecast['reforecast'], $reforecast['reforecast_variance'],
                    collect($phasing)->where('needs_explanation', true)->count(),
                    $b->status,
                ];
            })->all(),
        ];
    }

    /**
     * Every closed period whose variance crossed the year's threshold, with the reason given —
     * or a blank, which is the point: the report shows which ones nobody has explained yet.
     */
    private function varianceExplanations(?int $limit): array
    {
        $rows = $this->planning->materialVariances($this->financialYearId, $this->budgetId);
        if ($limit) {
            $rows = $rows->take($limit);
        }

        return [
            ['Code', 'Name', 'Period', 'Planned', 'Actual', 'Variance (under budget +)', 'Variance %', 'Explained', 'Explanation', 'Explained by', 'Explained at'],
            $rows->map(fn ($r) => [
                $r['code'], $r['name'], $r['label'], $r['planned'], $r['actual'], $r['variance'], $r['variance_pct'],
                $r['explanation'] ? 'Yes' : 'No — required', $r['explanation'], $r['explained_by'], $r['explained_at'],
            ])->all(),
        ];
    }

    private function allocations(?int $limit): array
    {
        $rows = DB::table('budget_allocations as a')
            ->join('budgets as b', 'b.id', '=', 'a.budget_id')
            ->whereNull('b.deleted_at')
            ->when($this->financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('b.id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('a.created_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('a.created_at', '<=', $d))
            ->orderByDesc('a.id')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get(['b.code', 'b.name as budget', 'a.label', 'a.scope_type', 'a.scope_id', 'a.amount', 'a.note', 'a.created_at']);

        return [
            ['Budget code', 'Budget', 'Line', 'Scope', 'Scope id', 'Amount', 'Note', 'Created'],
            $rows->map(fn ($r) => [
                $r->code, $r->budget, $r->label, $r->scope_type, $r->scope_id,
                (float) $r->amount, $r->note, (string) $r->created_at,
            ])->all(),
        ];
    }

    // ── Ledger ───────────────────────────────────────────────────────────────

    /** Reservations, commitments and consumptions share a shape, so they share a query. */
    private function ledger(string $table, ?int $limit): array
    {
        $hasStatus = $table !== 'budget_consumptions';

        $columns = ['b.code', 'b.name as budget', 'l.amount', 'l.source_type', 'l.source_id', 'l.created_at'];

        if ($hasStatus) {
            $columns[] = 'l.status';
        }

        $rows = DB::table($table . ' as l')
            ->join('budgets as b', 'b.id', '=', 'l.budget_id')
            ->whereNull('b.deleted_at')
            ->when($this->financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('b.id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('l.created_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('l.created_at', '<=', $d))
            ->orderByDesc('l.id')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get($columns);

        return [
            ['Budget code', 'Budget', 'Amount', 'Status', 'Source', 'Source id', 'Date'],
            $rows->map(fn ($r) => [
                $r->code, $r->budget, (float) $r->amount, $r->status ?? 'posted',
                $r->source_type, $r->source_id, (string) $r->created_at,
            ])->all(),
        ];
    }

    // ── Governance ───────────────────────────────────────────────────────────

    private function adjustments(?int $limit): array
    {
        $rows = DB::table('budget_adjustments as a')
            ->join('budgets as b', 'b.id', '=', 'a.budget_id')
            ->leftJoin('budgets as t', 't.id', '=', 'a.transfer_to_budget_id')
            ->whereNull('a.deleted_at')
            ->whereNull('b.deleted_at')
            ->when($this->financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('b.id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('a.created_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('a.created_at', '<=', $d))
            ->orderByDesc('a.id')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get(['b.code', 'b.name as budget', 'a.type', 'a.amount', 't.code as transfer_to', 'a.reason', 'a.status', 'a.created_at', 'a.acted_at']);

        return [
            ['Budget code', 'Budget', 'Type', 'Amount', 'Transfer to', 'Reason', 'Status', 'Requested', 'Decided'],
            $rows->map(fn ($r) => [
                $r->code, $r->budget, $r->type, (float) $r->amount, $r->transfer_to,
                $r->reason, $r->status, (string) $r->created_at, (string) $r->acted_at,
            ])->all(),
        ];
    }

    private function overrides(?int $limit): array
    {
        $rows = DB::table('budget_overrides as o')
            ->join('budgets as b', 'b.id', '=', 'o.budget_id')
            ->whereNull('o.deleted_at')
            ->whereNull('b.deleted_at')
            ->when($this->financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('b.id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('o.created_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('o.created_at', '<=', $d))
            ->orderByDesc('o.id')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get(['b.code', 'b.name as budget', 'o.amount', 'o.reason', 'o.status', 'o.source_type', 'o.created_at', 'o.acted_at']);

        return [
            ['Budget code', 'Budget', 'Amount', 'Reason', 'Status', 'Raised by', 'Requested', 'Decided'],
            $rows->map(fn ($r) => [
                $r->code, $r->budget, (float) $r->amount, $r->reason, $r->status,
                $r->source_type, (string) $r->created_at, (string) $r->acted_at,
            ])->all(),
        ];
    }

    private function approvals(?int $limit): array
    {
        $rows = DB::table('budget_approval_requests as r')
            ->leftJoin('budgets as b', 'b.id', '=', 'r.budget_id')
            ->leftJoin('budget_approval_flows as f', 'f.id', '=', 'r.flow_id')
            ->whereNull('r.deleted_at')
            ->when($this->financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('b.id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('r.created_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('r.created_at', '<=', $d))
            ->orderByDesc('r.id')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get([
                'b.code', 'r.subject_type', 'r.subject_label', 'r.amount', 'r.currency', 'r.status',
                'f.name as flow', 'r.current_position', 'r.created_at', 'r.decided_at', 'r.decision_reason',
            ]);

        return [
            ['Budget code', 'What', 'Label', 'Amount', 'Currency', 'Status', 'Flow', 'Step', 'Submitted', 'Decided', 'Hours to decide', 'Reason'],
            $rows->map(function ($r) {
                $hours = $r->decided_at
                    ? round(CarbonImmutable::parse($r->created_at)->diffInMinutes(CarbonImmutable::parse($r->decided_at)) / 60, 1)
                    : null;

                return [
                    $r->code, $r->subject_type, $r->subject_label, $r->amount === null ? null : (float) $r->amount,
                    $r->currency, $r->status, $r->flow, $r->current_position,
                    (string) $r->created_at, (string) $r->decided_at, $hours, $r->decision_reason,
                ];
            })->all(),
        ];
    }

    /** The trail — every signature, with the name kept as it was at the time. */
    private function decisions(?int $limit): array
    {
        $rows = DB::table('budget_approval_actions as a')
            ->join('budget_approval_requests as r', 'r.id', '=', 'a.request_id')
            ->leftJoin('budgets as b', 'b.id', '=', 'r.budget_id')
            ->leftJoin('budget_approval_steps as s', 's.id', '=', 'a.step_id')
            ->when($this->financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('b.id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('a.acted_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('a.acted_at', '<=', $d))
            ->orderByDesc('a.id')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get([
                'b.code', 'r.subject_type', 'r.subject_label', 'r.amount',
                's.name as step', 'a.position', 'a.actor_name', 'a.decision', 'a.comment', 'a.acted_at',
            ]);

        return [
            ['Budget code', 'What', 'Label', 'Amount', 'Step', 'Position', 'Decided by', 'Decision', 'Comment', 'When'],
            $rows->map(fn ($r) => [
                $r->code, $r->subject_type, $r->subject_label, $r->amount === null ? null : (float) $r->amount,
                $r->step, $r->position, $r->actor_name, $r->decision, $r->comment, (string) $r->acted_at,
            ])->all(),
        ];
    }

    private function alerts(?int $limit): array
    {
        $rows = DB::table('budget_alerts as al')
            ->join('budgets as b', 'b.id', '=', 'al.budget_id')
            ->leftJoin('budget_alert_rules as ru', 'ru.id', '=', 'al.rule_id')
            ->whereNull('b.deleted_at')
            ->when($this->financialYearId, fn ($q, $id) => $q->where('b.financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('b.id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('al.created_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('al.created_at', '<=', $d))
            ->orderByDesc('al.id')
            ->when($limit, fn ($q, $n) => $q->limit($n))
            ->get([
                'b.code', 'b.name as budget', 'ru.name as rule', 'al.severity', 'al.metric',
                'al.threshold', 'al.observed', 'al.status', 'al.message',
                'al.created_at', 'al.acknowledged_at', 'al.resolved_at',
            ]);

        return [
            ['Budget code', 'Budget', 'Rule', 'Severity', 'Metric', 'Threshold', 'Observed', 'Status', 'Message', 'Raised', 'Acknowledged', 'Resolved'],
            $rows->map(fn ($r) => [
                $r->code, $r->budget, $r->rule, $r->severity, $r->metric,
                $r->threshold === null ? null : (float) $r->threshold,
                $r->observed === null ? null : (float) $r->observed,
                $r->status, $r->message, (string) $r->created_at,
                (string) $r->acknowledged_at, (string) $r->resolved_at,
            ])->all(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function scopedBudgets()
    {
        return Budget::query()
            ->when($this->financialYearId, fn ($q, $id) => $q->where('financial_year_id', $id))
            ->when($this->budgetId, fn ($q, $id) => $q->where('id', $id))
            ->when($this->from, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($this->to, fn ($q, $d) => $q->where('created_at', '<=', $d));
    }
}
