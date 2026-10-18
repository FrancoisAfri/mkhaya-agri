<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Support\Integration\SupplierBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Procurement Reporting (§3).
 *
 * §3.1 is emphatic that reports come from live system data rather than compiled spreadsheets, and
 * that shapes everything here: a report *definition* stores only the filter selection, and every
 * number is computed at run time from the records, plans, reviews and deviations that already
 * exist. Nothing is denormalised into a reporting table that would then need keeping in step.
 *
 * The one exception is a **report run**, which freezes the computed result. That is not a
 * contradiction: a quarterly report tabled at a board meeting must still read the same next year
 * even as the underlying transactions are legitimately corrected. Live for the dashboard, frozen
 * for the document.
 *
 * Figures that depend on an absent module come back as null and are labelled, never zeroed. A spend
 * report showing R0 actual because Accounting is not installed is worse than one that says the
 * figure is unavailable — the first is a claim, the second is a fact.
 */
class ReportingService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SupplierBridge $suppliers,
        private readonly DeviationService $deviations,
        private readonly AuditLedger $audit,
        private readonly ReferenceGenerator $references,
    ) {}

    /**
     * §3.2 spend analysis for a period, sliced the ways §3.1 allows.
     *
     * @param  array<string,mixed>  $filters
     */
    public function spend(int $companyId, Carbon $from, Carbon $to, array $filters = []): array
    {
        $records = $this->recordQuery($companyId, $filters)
            ->whereBetween('r.opened_at', [$from, $to])
            ->get([
                'r.id', 'r.reference', 'r.title', 'r.category', 'r.company_unit_id',
                'r.estimated_value', 'r.awarded_value', 'r.currency', 'r.stage', 'r.status',
                'r.officer_id', 'r.project_id', 'r.method',
                'u.name as department',
            ]);

        $recordIds = $records->pluck('id')->all();

        // Contracted and invoiced values come from the links into Contract Management and
        // Accounting. Absent those modules the links do not exist and the totals are null.
        $linked = $recordIds === [] ? collect() : DB::table('procgov_record_links')
            ->whereIn('record_id', $recordIds)
            ->whereIn('link_role', ['contract', 'invoice', 'purchase_order'])
            ->get(['record_id', 'link_role', 'amount']);

        $contractValue = $linked->where('link_role', 'contract')->sum('amount');
        $invoiced = $linked->where('link_role', 'invoice')->sum('amount');

        $planned = (float) $this->planItemQuery($companyId, $filters)
            ->whereBetween('i.created_at', [$from, $to])
            ->sum('i.estimated_value');

        $approved = (float) $this->planItemQuery($companyId, $filters)
            ->whereBetween('i.created_at', [$from, $to])
            ->whereIn('i.status', ['APPROVED', 'IN_PROGRESS', 'TENDERED', 'AWARDED', 'COMPLETED'])
            ->sum('i.estimated_value');

        $awarded = (float) $records->sum('awarded_value');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'planned_spend' => round($planned, 2),
                'approved_spend' => round($approved, 2),
                'awarded_value' => round($awarded, 2),
                'contract_value' => $linked->isEmpty() ? null : round((float) $contractValue, 2),
                'actual_spend' => $linked->where('link_role', 'invoice')->isEmpty()
                    ? null
                    : round((float) $invoiced, 2),
                'remaining_value' => $linked->where('link_role', 'invoice')->isEmpty()
                    ? null
                    : round((float) $contractValue - (float) $invoiced, 2),
                'budget_variance' => round($approved - $awarded, 2),
            ],
            'by_department' => $records->groupBy('department')->map(fn ($g, $k) => [
                'label' => $k ?: 'Unassigned',
                'count' => $g->count(),
                'estimated' => round((float) $g->sum('estimated_value'), 2),
                'awarded' => round((float) $g->sum('awarded_value'), 2),
            ])->values(),
            'by_category' => $records->groupBy('category')->map(fn ($g, $k) => [
                'label' => $k ?: 'Uncategorised',
                'count' => $g->count(),
                'estimated' => round((float) $g->sum('estimated_value'), 2),
                'awarded' => round((float) $g->sum('awarded_value'), 2),
            ])->values(),
            'by_method' => $records->groupBy('method')->map(fn ($g, $k) => [
                'label' => $k ?: 'Not recorded',
                'count' => $g->count(),
                'estimated' => round((float) $g->sum('estimated_value'), 2),
                'awarded' => round((float) $g->sum('awarded_value'), 2),
            ])->values(),
            'by_supplier' => $this->spendBySupplier($companyId, $recordIds),
        ];
    }

    /** §3.3 supplier statistics. */
    public function supplierStatistics(int $companyId, Carbon $from, Carbon $to): array
    {
        $base = $this->suppliers->statistics($companyId, $from, $to);

        $awards = DB::table('procgov_bidders as b')
            ->join('procgov_tender_reviews as r', 'r.id', '=', 'b.review_id')
            ->where('b.company_id', $companyId)
            ->where('b.status', 'AWARDED')
            ->whereBetween('r.awarded_at', [$from, $to])
            ->get(['b.supplier_id', 'b.supplier_name', 'b.bid_amount']);

        $totalAwarded = (float) $awards->sum('bid_amount');

        $bySupplier = $awards->groupBy('supplier_name')->map(fn ($g, $name) => [
            'supplier' => $name,
            'awards' => $g->count(),
            'value' => round((float) $g->sum('bid_amount'), 2),
            // §3.3 supplier concentration: the share one supplier holds of everything awarded.
            'share_pct' => $totalAwarded > 0 ? round(((float) $g->sum('bid_amount') / $totalAwarded) * 100, 1) : null,
        ])->sortByDesc('value')->values();

        return array_merge($base, [
            'suppliers_used' => $awards->pluck('supplier_name')->unique()->count(),
            'total_awarded' => round($totalAwarded, 2),
            'by_supplier' => $bySupplier,
            // The top three's combined share: the number that says whether a market is competitive.
            'concentration_top3_pct' => $bySupplier->take(3)->sum('share_pct'),
        ]);
    }

    /**
     * §3.4 procurement performance, and §4.4's processing-time analytics.
     *
     * Cycle times come from `procgov_stage_events`, which records how long each stage lasted at the
     * moment it ended. Deriving them from timestamps on the records instead would produce a
     * different answer whenever a record moved backwards, which happens whenever a recommendation
     * is sent back — and that is exactly the case management most wants to see.
     */
    public function performance(int $companyId, Carbon $from, Carbon $to, array $filters = []): array
    {
        $records = $this->recordQuery($companyId, $filters)
            ->whereBetween('r.opened_at', [$from, $to])
            ->get(['r.id', 'r.stage', 'r.status', 'r.opened_at', 'r.closed_at', 'r.officer_id']);

        $completed = $records->filter(fn ($r) => $r->closed_at !== null);

        $reviews = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'status', 'evaluation_opened_at', 'evaluation_closed_at',
                'evaluation_deadline', 'tender_closing_at', 'awarded_at']);

        $evaluated = $reviews->filter(fn ($r) => $r->evaluation_opened_at && $r->evaluation_closed_at);

        $approvals = DB::table('procgov_approval_requests')
            ->where('company_id', $companyId)
            ->whereBetween('requested_at', [$from, $to])
            ->whereNotNull('completed_at')
            ->get(['requested_at', 'completed_at']);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'volumes' => [
                'procurements_opened' => $records->count(),
                'procurements_completed' => $completed->count(),
                'tenders' => $reviews->count(),
                'tenders_completed' => $reviews->where('status', 'AWARDED')->count(),
                'tenders_cancelled' => $reviews->where('status', 'CANCELLED')->count(),
                'tenders_delayed' => $reviews->filter(fn ($r) => $r->evaluation_deadline
                    && ! $r->evaluation_closed_at
                    && Carbon::parse($r->evaluation_deadline)->isPast())->count(),
                'deviations' => $this->deviations->statistics($companyId, $from, $to)['total'],
            ],
            'cycle_times_days' => [
                'procurement_cycle' => $completed->isEmpty() ? null : round(
                    $completed->avg(fn ($r) => Carbon::parse($r->opened_at)->diffInHours(Carbon::parse($r->closed_at)) / 24),
                    1,
                ),
                'tender_evaluation' => $evaluated->isEmpty() ? null : round(
                    $evaluated->avg(fn ($r) => Carbon::parse($r->evaluation_opened_at)
                        ->diffInHours(Carbon::parse($r->evaluation_closed_at)) / 24),
                    1,
                ),
                'approval' => $approvals->isEmpty() ? null : round(
                    $approvals->avg(fn ($a) => Carbon::parse($a->requested_at)
                        ->diffInHours(Carbon::parse($a->completed_at)) / 24),
                    1,
                ),
            ],
            'stage_durations_days' => $this->stageDurations($companyId, $from, $to),
            'deviations' => $this->deviations->statistics($companyId, $from, $to),
        ];
    }

    /**
     * §4.4: average days spent on each leg of the chain. Named the way the brief names them, so a
     * bottleneck maps directly onto the process people recognise.
     *
     * @return list<array{transition:string, average_days:float, count:int}>
     */
    public function stageDurations(int $companyId, Carbon $from, Carbon $to): array
    {
        return DB::table('procgov_stage_events')
            ->where('company_id', $companyId)
            ->whereNotNull('from_stage')
            ->whereNotNull('seconds_in_from_stage')
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('from_stage', 'to_stage')
            ->selectRaw('from_stage, to_stage, avg(seconds_in_from_stage) as avg_seconds, count(*) as transitions')
            ->get()
            ->map(fn ($row) => [
                'transition' => "{$row->from_stage} → {$row->to_stage}",
                'from_stage' => $row->from_stage,
                'to_stage' => $row->to_stage,
                'average_days' => round(((float) $row->avg_seconds) / 86400, 1),
                'count' => (int) $row->transitions,
            ])
            ->sortByDesc('average_days')
            ->values()
            ->all();
    }

    /**
     * Assemble and freeze a report run (§3, quarterly and annual generation).
     *
     * @param  array<string,mixed>  $filters
     */
    public function generate(
        int $companyId,
        string $reportType,
        Carbon $from,
        Carbon $to,
        array $filters = [],
        ?int $definitionId = null,
        ?string $periodLabel = null,
    ): object {
        $payload = match ($reportType) {
            'SPEND' => $this->spend($companyId, $from, $to, $filters),
            'SUPPLIER' => $this->supplierStatistics($companyId, $from, $to),
            'PERFORMANCE' => $this->performance($companyId, $from, $to, $filters),
            'COMPLIANCE' => app(ComplianceDashboardService::class)->snapshot($companyId, $filters),
            'WORKLOAD' => app(WorkloadService::class)->dashboard($companyId),
            default => [
                'spend' => $this->spend($companyId, $from, $to, $filters),
                'suppliers' => $this->supplierStatistics($companyId, $from, $to),
                'performance' => $this->performance($companyId, $from, $to, $filters),
            ],
        };

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = Carbon::now();

        $title = sprintf(
            '%s report — %s',
            Str::headline(mb_strtolower($reportType)),
            $periodLabel ?: ($from->toDateString().' to '.$to->toDateString()),
        );

        $id = DB::table('procgov_report_runs')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'definition_id' => $definitionId,
            'report_type' => $reportType,
            'title' => $title,
            'period_label' => $periodLabel,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'filters' => json_encode($filters),
            'payload' => $json,
            'payload_hash' => hash('sha256', (string) $json),
            'status' => 'COMPLETE',
            'generated_by' => auth()->id(),
            'generated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($definitionId) {
            DB::table('procgov_report_definitions')->where('id', $definitionId)->update([
                'last_run_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->audit->record($companyId, [
            'action' => 'report_generated',
            'capability' => 'reporting',
            'entity_type' => 'procgov_report_runs',
            'entity_id' => $id,
            'summary' => "Generated {$title}",
        ]);

        return DB::table('procgov_report_runs')->find($id);
    }

    /** The four quarters of a financial year, as date ranges, honouring the tenant's year start. */
    public function quarters(int $companyId, string $financialYearLabel): array
    {
        [$start] = $this->settings->financialYearRange($companyId, $financialYearLabel);

        $quarters = [];

        for ($q = 1; $q <= 4; $q++) {
            $qStart = $start->copy()->addMonths(($q - 1) * 3);
            $quarters["Q{$q}"] = [
                'label' => "{$financialYearLabel} Q{$q}",
                'from' => $qStart,
                'to' => $qStart->copy()->addMonths(3)->subDay()->endOfDay(),
            ];
        }

        return $quarters;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function recordQuery(int $companyId, array $filters)
    {
        return DB::table('procgov_records as r')
            ->leftJoin('company_units as u', 'u.id', '=', 'r.company_unit_id')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')
            ->when($filters['company_unit_id'] ?? null, fn ($q, $v) => $q->where('r.company_unit_id', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('r.category', $v))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('r.project_id', $v))
            ->when($filters['officer_id'] ?? null, fn ($q, $v) => $q->where('r.officer_id', $v))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->where('r.method', $v))
            ->when($filters['financial_year'] ?? null, fn ($q, $v) => $q->where('r.financial_year_label', $v));
    }

    private function planItemQuery(int $companyId, array $filters)
    {
        return DB::table('procgov_plan_items as i')
            ->join('procgov_plans as p', 'p.id', '=', 'i.plan_id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->whereNull('p.deleted_at')
            ->when($filters['company_unit_id'] ?? null, fn ($q, $v) => $q->where(function ($inner) use ($v) {
                $inner->where('i.company_unit_id', $v)->orWhere('p.company_unit_id', $v);
            }))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('i.category', $v))
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('i.project_id', $v))
            ->when($filters['officer_id'] ?? null, fn ($q, $v) => $q->where('i.responsible_officer_id', $v))
            ->when($filters['financial_year'] ?? null, fn ($q, $v) => $q->where('p.financial_year_label', $v));
    }

    private function spendBySupplier(int $companyId, array $recordIds)
    {
        if ($recordIds === []) {
            return collect();
        }

        return DB::table('procgov_record_links')
            ->where('company_id', $companyId)
            ->whereIn('record_id', $recordIds)
            ->where('link_role', 'supplier')
            ->groupBy('label')
            ->selectRaw('label, count(*) as procurements, coalesce(sum(amount),0) as value')
            ->orderByDesc('value')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'supplier' => $r->label ?: 'Unnamed',
                'procurements' => (int) $r->procurements,
                'value' => round((float) $r->value, 2),
            ]);
    }
}
