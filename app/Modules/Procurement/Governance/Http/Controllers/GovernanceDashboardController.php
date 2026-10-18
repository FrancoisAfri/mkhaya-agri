<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\ApprovalRouter;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\ComplianceDashboardService;
use App\Modules\Procurement\Governance\Services\PlanningService;
use App\Modules\Procurement\Governance\Services\RecordService;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Procurement\Governance\Services\WorkloadService;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The module landing page: what needs attention, across every capability the tenant has switched on.
 *
 * Each section is skipped entirely when its capability is off (§12), rather than rendered empty.
 * An empty "Deviations" panel on a tenant who never bought deviation management reads as a broken
 * feature; its absence reads as a feature they do not have.
 */
class GovernanceDashboardController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly Capabilities $capabilities,
        private readonly ComplianceDashboardService $compliance,
        private readonly PlanningService $planning,
        private readonly WorkloadService $workload,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return Inertia::render('Modules/ProcurementGovernance/Dashboard', [
                'noCompany' => true,
                'context' => $this->capabilities->forResponse(),
            ]);
        }

        $has = fn (string $capability) => $this->capabilities->has($capability);

        return Inertia::render('Modules/ProcurementGovernance/Dashboard', [
            'noCompany' => false,
            'context' => $this->capabilities->forResponse(),
            'financialYear' => $this->settings->financialYearLabel($companyId),

            'pipeline' => $this->pipeline($companyId),
            'myApprovals' => $this->myApprovals($companyId, (int) $request->user()->id),

            'planning' => $has('planning') ? $this->planning->dashboard($companyId) : null,
            'compliance' => $has('governance') ? $this->compliance->snapshot($companyId) : null,
            'workload' => $has('workload') ? $this->workload->dashboard($companyId)['summary'] : null,

            'evaluations' => $has('tender_evaluation') ? $this->evaluationSummary($companyId) : null,
            'deviations' => $has('deviations') ? $this->deviationSummary($companyId) : null,

            'recentActivity' => DB::table('procgov_audit_events')
                ->where('company_id', $companyId)
                ->orderByDesc('occurred_at')
                ->orderByDesc('sequence')
                ->limit(15)
                ->get(['occurred_at', 'actor_label', 'actor_role', 'summary', 'entity_reference', 'decision']),

            'can' => [
                'configure' => (bool) $request->user()?->can('procgov.configure'),
                'audit' => (bool) $request->user()?->can('procgov.audit.view'),
            ],
        ]);
    }

    /** §11's chain, as counts — where every live procurement currently sits. */
    private function pipeline(int $companyId): array
    {
        $stages = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->groupBy('stage')
            ->selectRaw('stage, count(*) as total, coalesce(sum(estimated_value),0) as value')
            ->get()
            ->keyBy('stage');

        return collect(RecordService::STAGES)
            ->map(fn (string $stage) => [
                'stage' => $stage,
                'count' => (int) ($stages[$stage]->total ?? 0),
                'value' => round((float) ($stages[$stage]->value ?? 0), 2),
            ])
            ->all();
    }

    private function myApprovals(int $companyId, int $userId): array
    {
        $rows = DB::table('procgov_approval_steps as s')
            ->join('procgov_approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.company_id', $companyId)
            ->where('s.status', 'ACTIVE')
            ->orderBy('s.due_at')
            ->limit(50)
            ->get([
                's.id as step_id', 's.name', 's.due_at', 's.approver_label',
                'r.id as request_id', 'r.uuid', 'r.subject_type', 'r.subject_reference',
                'r.subject_label', 'r.amount', 'r.requested_at',
            ]);

        $router = app(ApprovalRouter::class);

        return $rows
            // Filtered through the router rather than by a column comparison: a step can be
            // assigned to a role or a relationship, and only the router knows how to resolve those.
            ->filter(fn ($row) => $router->mayDecide((int) $row->request_id, $userId))
            ->map(function ($row) {
                $row->overdue = $row->due_at && Carbon::parse($row->due_at)->isPast();

                return $row;
            })
            ->values()
            ->all();
    }

    private function evaluationSummary(int $companyId): array
    {
        $reviews = DB::table('procgov_tender_reviews')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get(['status', 'evaluation_deadline']);

        return [
            'total' => $reviews->count(),
            'in_setup' => $reviews->whereIn('status', ['DRAFT', 'COMMITTEE_SETUP', 'CRITERIA_SETUP'])->count(),
            'evaluating' => $reviews->where('status', 'EVALUATION')->count(),
            'moderating' => $reviews->where('status', 'MODERATION')->count(),
            'awaiting_approval' => $reviews->whereIn('status', ['RECOMMENDATION', 'APPROVAL'])->count(),
            'awarded' => $reviews->where('status', 'AWARDED')->count(),
            'overdue' => $reviews->filter(fn ($r) => $r->status === 'EVALUATION'
                && $r->evaluation_deadline
                && Carbon::parse($r->evaluation_deadline)->isPast())->count(),
        ];
    }

    private function deviationSummary(int $companyId): array
    {
        $deviations = DB::table('procgov_deviations')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get(['status', 'risk_level', 'compliance_result', 'procurement_value']);

        return [
            'total' => $deviations->count(),
            'draft' => $deviations->where('status', 'DRAFT')->count(),
            'pending' => $deviations->whereIn('status', [
                'SUBMITTED', 'COMPLIANCE_REVIEW', 'PROCUREMENT_REVIEW', 'FINANCIAL_REVIEW', 'MANAGEMENT_APPROVAL',
            ])->count(),
            'approved' => $deviations->where('status', 'APPROVED')->count(),
            'rejected' => $deviations->where('status', 'REJECTED')->count(),
            'high_risk' => $deviations->whereIn('risk_level', ['HIGH', 'CRITICAL'])->count(),
            'value' => round((float) $deviations->sum('procurement_value'), 2),
        ];
    }
}
