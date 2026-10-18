<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\AnalyticsService;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\ComplianceDashboardService;
use App\Modules\Procurement\Governance\Services\ReportingService;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Procurement\Governance\Services\WorkloadService;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reporting (§3), workload and capacity (§4), and the compliance dashboard (§3.5).
 *
 * Three capabilities, one controller, because they share a filter vocabulary and a period selector
 * and splitting them would mean maintaining that in three places. Each action is still gated on its
 * own capability by the route middleware, so a tenant without Workload sees no workload route at
 * all.
 */
class InsightsController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly ReportingService $reporting,
        private readonly AnalyticsService $analytics,
        private readonly WorkloadService $workload,
        private readonly ComplianceDashboardService $compliance,
        private readonly SettingsService $settings,
        private readonly OptionLists $options,
        private readonly Capabilities $capabilities,
    ) {}

    /** §3 reporting engine. */
    public function reports(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/ProcurementGovernance/Insights/Reports', [
                'noCompany' => true,
                'context' => $this->capabilities->forResponse(),
            ]);
        }

        $financialYear = $request->string('financial_year')->toString()
            ?: $this->settings->financialYearLabel($companyId);

        [$from, $to] = $this->resolvePeriod($companyId, $financialYear, $request->string('quarter')->toString());

        $filters = array_filter($request->only([
            'company_unit_id', 'category', 'project_id', 'officer_id', 'method',
        ]));

        return Inertia::render('Modules/ProcurementGovernance/Insights/Reports', [
            'noCompany' => false,
            'financialYear' => $financialYear,
            'quarter' => $request->string('quarter')->toString(),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'filters' => $filters,

            'spend' => $this->reporting->spend($companyId, $from, $to, $filters),
            'suppliers' => $this->reporting->supplierStatistics($companyId, $from, $to),
            'performance' => $this->reporting->performance($companyId, $from, $to, $filters),

            'financialYears' => $this->options->financialYearLabels($companyId),
            'departments' => $this->options->departments($companyId),
            'officers' => $this->options->officers($companyId),
            'categories' => $this->options->procurementCategories($companyId),
            'methods' => OptionLists::PROCUREMENT_METHODS,

            'savedRuns' => DB::table('procgov_report_runs')
                ->where('company_id', $companyId)
                ->orderByDesc('generated_at')
                ->limit(50)
                ->get(['uuid', 'report_type', 'title', 'period_label', 'generated_at', 'payload_hash']),

            'can' => ['export' => (bool) $request->user()?->can('procgov.report.export')],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /** Freeze the current view as a report run (§3). */
    public function generateReport(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'report_type' => ['required', Rule::in(['SPEND', 'SUPPLIER', 'PERFORMANCE', 'COMPLIANCE', 'WORKLOAD', 'FULL'])],
            'financial_year' => ['required', 'string', 'max:20'],
            'quarter' => ['nullable', 'string', 'max:4'],
            'filters' => ['nullable', 'array'],
        ]);

        [$from, $to] = $this->resolvePeriod($companyId, $data['financial_year'], $data['quarter'] ?? null);

        $label = $data['quarter']
            ? "{$data['financial_year']} {$data['quarter']}"
            : $data['financial_year'];

        $run = $this->reporting->generate(
            $companyId,
            $data['report_type'],
            $from,
            $to,
            $data['filters'] ?? [],
            null,
            $label,
        );

        return back()->with('success', __('procurement_governance.flash.insights.report_generated', ['title' => $run->title]));
    }

    public function showRun(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);

        $run = DB::table('procgov_report_runs')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $run, 404);

        $run->payload = json_decode((string) $run->payload, true);
        $run->filters = json_decode((string) $run->filters, true);

        return Inertia::render('Modules/ProcurementGovernance/Insights/ReportRun', [
            'run' => $run,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /** §4 workload and capacity. */
    public function workload(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/ProcurementGovernance/Insights/Workload', [
            'noCompany' => $companyId === null,
            'dashboard' => $companyId ? $this->workload->dashboard($companyId) : null,
            'alerts' => $companyId ? $this->workload->alerts($companyId) : [],
            'trend' => $companyId ? $this->workloadTrend($companyId) : [],
            'can' => ['manage' => (bool) $request->user()?->can('procgov.workload.manage')],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function acknowledgeAlert(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(['ACKNOWLEDGED', 'ACTIONED', 'DISMISSED'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::table('procgov_capacity_alerts')
            ->where('company_id', $companyId)
            ->where('uuid', $uuid)
            ->update([
                'status' => $data['status'],
                'acknowledged_by' => $request->user()->id,
                'acknowledged_at' => Carbon::now(),
                'resolution_note' => $data['note'] ?? null,
                'updated_at' => Carbon::now(),
            ]);

        return back()->with('success', __('procurement_governance.flash.insights.alert_updated'));
    }

    /**
     * §3.1 and §3.4 cross-module analytics.
     *
     * Separate from reports() rather than another tab on it, because the two answer to different
     * people. Reports are a finance artefact — spend, by whom, frozen and citable. This is the
     * governance picture: how competitive the tenders were, where approvals stall, how often the
     * normal route was left. Bolting them together would produce a page that serves neither
     * audience and takes twice as long to load.
     */
    public function analytics(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/ProcurementGovernance/Insights/Analytics', [
                'noCompany' => true,
                'context' => $this->capabilities->forResponse(),
            ]);
        }

        $financialYear = $request->string('financial_year')->toString()
            ?: $this->settings->financialYearLabel($companyId);

        [$from, $to] = $this->resolvePeriod($companyId, $financialYear, $request->string('quarter')->toString());

        return Inertia::render('Modules/ProcurementGovernance/Insights/Analytics', [
            'noCompany' => false,
            'financialYear' => $financialYear,
            'quarter' => $request->string('quarter')->toString(),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'financialYears' => $this->options->financialYearLabels($companyId),

            'headlines' => $this->analytics->headlines($companyId, $from, $to),
            'pipeline' => $this->analytics->pipeline($companyId, $from, $to),
            'competition' => $this->analytics->competition($companyId, $from, $to),
            'intake' => $this->analytics->intake($companyId, $from, $to),
            'deviations' => $this->analytics->deviations($companyId, $from, $to),
            'approvals' => $this->analytics->approvals($companyId, $from, $to),
            'awards' => $this->analytics->awards($companyId, $from, $to),
            'trend' => $this->analytics->trend($companyId, $from, $to),

            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /** §3.5 compliance dashboard. */
    public function compliance(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/ProcurementGovernance/Insights/Compliance', [
            'noCompany' => $companyId === null,
            'snapshot' => $companyId ? $this->compliance->snapshot($companyId, $request->all()) : null,
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'can' => ['configure' => (bool) $request->user()?->can('procgov.configure')],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{0:Carbon,1:Carbon} */
    private function resolvePeriod(int $companyId, string $financialYear, ?string $quarter): array
    {
        [$from, $to] = $this->settings->financialYearRange($companyId, $financialYear);

        if ($quarter) {
            $quarters = $this->reporting->quarters($companyId, $financialYear);

            if (isset($quarters[$quarter])) {
                return [$quarters[$quarter]['from'], $quarters[$quarter]['to']];
            }
        }

        return [$from, $to];
    }

    /** 90 days of capacity history, for the trend line the live dashboard cannot produce. */
    private function workloadTrend(int $companyId): array
    {
        return DB::table('procgov_officer_workload')
            ->where('company_id', $companyId)
            ->where('snapshot_date', '>=', Carbon::today()->subDays(90))
            ->groupBy('snapshot_date')
            ->selectRaw('snapshot_date,
                         avg(capacity_index) as avg_index,
                         sum(active_procurements) as active,
                         sum(overdue_actions) as overdue,
                         count(*) filter (where capacity_status = \'OVERLOADED\') as overloaded')
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($r) => [
                'date' => $r->snapshot_date,
                'avg_index' => round((float) $r->avg_index, 2),
                'active' => (int) $r->active,
                'overdue' => (int) $r->overdue,
                'overloaded' => (int) $r->overloaded,
            ])
            ->all();
    }
}
