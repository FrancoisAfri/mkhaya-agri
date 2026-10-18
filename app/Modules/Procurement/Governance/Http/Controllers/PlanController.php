<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\PlanningService;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Procurement\Governance\Support\Integration\BudgetBridge;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Procurement Planning (§2).
 *
 * Reads use the query builder rather than Eloquent: these pages join across plans, lines, users and
 * company units and the payload is a flat table, so hydrating models would cost a great deal and
 * buy nothing. Tenant isolation is enforced at the database by row-level security, so a plan id
 * belonging to another company does not resolve here at all — the explicit company_id predicates
 * are belt-and-braces for the console context where RLS is inactive.
 */
class PlanController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly PlanningService $planning,
        private readonly SettingsService $settings,
        private readonly BudgetBridge $budgets,
        private readonly Capabilities $capabilities,
        private readonly OptionLists $options,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $plans = $companyId === null ? collect() : DB::table('procgov_plans as p')
            ->leftJoin('company_units as u', 'u.id', '=', 'p.company_unit_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->when($request->string('financial_year')->toString(), fn ($q, $fy) => $q->where('p.financial_year_label', $fy))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('p.status', $s))
            ->when($request->integer('company_unit_id'), fn ($q, $u) => $q->where('p.company_unit_id', $u))
            ->orderByDesc('p.financial_year_label')
            ->orderBy('p.reference')
            ->limit(400)
            ->get([
                'p.id', 'p.uuid', 'p.reference', 'p.title', 'p.financial_year_label', 'p.status',
                'p.total_estimated_value', 'p.total_committed_value', 'p.total_actual_value',
                'p.currency', 'p.approved_at', 'p.created_at', 'u.name as department',
            ]);

        $planIds = $plans->pluck('id')->all();

        $lineCounts = $planIds === [] ? collect() : DB::table('procgov_plan_items')
            ->whereIn('plan_id', $planIds)
            ->whereNull('deleted_at')
            ->groupBy('plan_id')
            ->selectRaw('plan_id, count(*) as line_count')
            ->pluck('line_count', 'plan_id');

        return Inertia::render('Modules/ProcurementGovernance/Planning/Index', [
            'plans' => $plans->map(fn ($p) => array_merge((array) $p, [
                'line_count' => (int) ($lineCounts[$p->id] ?? 0),
            ]))->values(),
            'filters' => $request->only(['financial_year', 'status', 'company_unit_id']),
            'statuses' => array_keys(PlanningService::TRANSITIONS),
            'financialYears' => $companyId ? $this->options->financialYearLabels($companyId) : [],
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'noCompany' => $companyId === null,
            'can' => [
                'create' => (bool) $request->user()?->can('procgov.plan.create'),
                'approve' => (bool) $request->user()?->can('procgov.plan.approve'),
            ],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/ProcurementGovernance/Planning/Create', [
            'defaults' => [
                'financial_year_label' => $companyId ? $this->settings->financialYearLabel($companyId) : null,
                'currency' => $companyId ? $this->settings->get($companyId)->currency : 'ZAR',
            ],
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'projects' => $companyId ? $this->options->projects($companyId) : [],
            'budgets' => $this->budgets->selectable(),
            'budgetModule' => $this->budgets->isAvailable(),
            'categories' => $companyId ? $this->options->procurementCategories($companyId) : [],
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'financial_year_label' => ['required', 'string', 'max:20'],
            'financial_year_id' => ['nullable', 'integer'],
            'company_unit_id' => ['nullable', 'integer'],
            'business_unit_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'cost_centre' => ['nullable', 'string', 'max:60'],
            'budget_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:120'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $plan = $this->planning->createPlan($companyId, $data);

        return redirect()
            ->route('procgov.plans.show', $plan->uuid)
            ->with('success', __('procurement_governance.flash.plans.created', ['reference' => $plan->reference]));
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);

        $plan = DB::table('procgov_plans')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $plan, 404);

        $items = DB::table('procgov_plan_items as i')
            ->leftJoin('users as ou', 'ou.id', '=', 'i.responsible_officer_id')
            ->leftJoin('hr_people as op', 'op.user_id', '=', 'ou.id')
            ->leftJoin('procgov_records as r', 'r.id', '=', 'i.record_id')
            ->where('i.plan_id', $plan->id)
            ->whereNull('i.deleted_at')
            ->orderBy('i.sort_order')
            ->orderBy('i.id')
            ->get([
                'i.id', 'i.uuid', 'i.reference', 'i.description', 'i.business_justification',
                'i.category', 'i.estimated_value', 'i.committed_amount', 'i.actual_spend',
                'i.currency', 'i.procurement_method', 'i.priority', 'i.status',
                'i.planned_start_date', 'i.planned_tender_date', 'i.expected_award_date',
                'i.expected_contract_start', 'i.expected_contract_end',
                'i.actual_tender_date', 'i.actual_award_date', 'i.record_id', 'i.budget_id',
                'r.reference as record_reference', 'r.stage as record_stage', 'r.uuid as record_uuid',
                DB::raw("trim(coalesce(op.first_name,'') || ' ' || coalesce(op.surname,'')) as officer_name"),
                'ou.email as officer_email',
            ])
            ->map(function ($i) {
                $i->officer = trim((string) $i->officer_name) ?: $i->officer_email;
                unset($i->officer_name, $i->officer_email);

                return $i;
            });

        $approval = $plan->approval_request_id
            ? DB::table('procgov_approval_requests')->find($plan->approval_request_id)
            : null;

        return Inertia::render('Modules/ProcurementGovernance/Planning/Show', [
            'plan' => $plan,
            'items' => $items,
            'budgetPosition' => $companyId ? $this->planning->budgetPosition($companyId, (int) $plan->id) : null,
            'history' => $companyId ? $this->planning->history($companyId, (int) $plan->id) : [],
            'nextStatuses' => $this->planning->transitionsFor($plan->status),
            'approval' => $approval,
            'approvalSteps' => $approval
                ? DB::table('procgov_approval_steps')->where('request_id', $approval->id)->orderBy('step_no')->get()
                : [],
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'budgets' => $this->budgets->selectable($plan->financial_year_id ? (int) $plan->financial_year_id : null),
            'budgetModule' => $this->budgets->isAvailable(),
            'methods' => OptionLists::PROCUREMENT_METHODS,
            'can' => [
                'edit' => (bool) $request->user()?->can('procgov.plan.edit'),
                'submit' => (bool) $request->user()?->can('procgov.plan.submit'),
                'approve' => (bool) $request->user()?->can('procgov.plan.approve'),
                'activate' => (bool) $request->user()?->can('procgov.plan.activate'),
            ],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function storeItem(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $plan = $this->findPlan($companyId, $uuid);

        $data = $request->validate($this->itemRules());

        $item = $this->planning->addItem($companyId, (int) $plan->id, $data);

        return back()->with('success', __('procurement_governance.flash.plans.line_added', ['reference' => $item->reference]));
    }

    public function updateItem(Request $request, string $uuid, string $itemUuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $plan = $this->findPlan($companyId, $uuid);

        $item = DB::table('procgov_plan_items')
            ->where('company_id', $companyId)
            ->where('plan_id', $plan->id)
            ->where('uuid', $itemUuid)
            ->first();

        abort_if(! $item, 404);

        $data = $request->validate($this->itemRules(false));

        $this->planning->updateItem($companyId, (int) $item->id, $data);

        return back()->with('success', __('procurement_governance.flash.plans.line_updated', ['reference' => $item->reference]));
    }

    public function transition(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $plan = $this->findPlan($companyId, $uuid);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(PlanningService::TRANSITIONS))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->planning->transition($companyId, (int) $plan->id, $data['status'], $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Reaching management approval is where a configured chain, if the tenant has one, takes
        // over. Absent a chain the status workflow alone still governs the plan.
        if ($data['status'] === 'MANAGEMENT_APPROVAL') {
            $this->planning->requestApproval($companyId, (int) $plan->id);
        }

        return back()->with('success', __('procurement_governance.flash.plans.moved', ['status' => $data['status']]));
    }

    public function activateItem(Request $request, string $uuid, string $itemUuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $plan = $this->findPlan($companyId, $uuid);

        $item = DB::table('procgov_plan_items')
            ->where('company_id', $companyId)
            ->where('plan_id', $plan->id)
            ->where('uuid', $itemUuid)
            ->first();

        abort_if(! $item, 404);

        if ($plan->status !== 'APPROVED' && $plan->status !== 'IN_PROGRESS') {
            return back()->with('error', __('procurement_governance.flash.plans.only_approved_live'));
        }

        try {
            $record = $this->planning->activateItem($companyId, (int) $item->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.plans.line_live', ['line' => $item->reference, 'record' => $record->reference]));
    }

    public function refreshFinancials(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $plan = $this->findPlan($companyId, $uuid);

        if (! $this->budgets->isAvailable()) {
            return back()->with('error', __('procurement_governance.flash.plans.budget_unavailable'));
        }

        $this->planning->refreshFinancials($companyId, (int) $plan->id);

        return back()->with('success', __('procurement_governance.flash.plans.budget_refreshed'));
    }

    /** §2.4 procurement status dashboard. */
    public function dashboard(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $filters = $request->only(['financial_year', 'company_unit_id', 'officer_id', 'category']);

        return Inertia::render('Modules/ProcurementGovernance/Planning/Dashboard', [
            'summary' => $companyId ? $this->planning->dashboard($companyId, $filters) : null,
            'filters' => $filters,
            'financialYears' => $companyId ? $this->options->financialYearLabels($companyId) : [],
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'upcoming' => $companyId ? $this->upcomingLines($companyId, $filters) : [],
            'delayed' => $companyId ? $this->delayedLines($companyId, $filters) : [],
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function findPlan(int $companyId, string $uuid): object
    {
        $plan = DB::table('procgov_plans')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $plan, 404);

        return $plan;
    }

    private function itemRules(bool $required = true): array
    {
        $r = $required ? 'required' : 'sometimes';

        return [
            'description' => [$r, 'string', 'max:255'],
            'business_justification' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:120'],
            'estimated_value' => [$r, 'numeric', 'min:0', 'max:99999999999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'budget_id' => ['nullable', 'integer'],
            'budget_allocation' => ['nullable', 'numeric', 'min:0'],
            'procurement_method' => ['nullable', 'string', Rule::in(OptionLists::PROCUREMENT_METHODS)],
            'planned_start_date' => ['nullable', 'date'],
            'planned_tender_date' => ['nullable', 'date'],
            'expected_award_date' => ['nullable', 'date'],
            'expected_contract_start' => ['nullable', 'date'],
            'expected_contract_end' => ['nullable', 'date', 'after_or_equal:expected_contract_start'],
            'responsible_officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'company_unit_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function upcomingLines(int $companyId, array $filters)
    {
        return $this->lineQuery($companyId, $filters)
            ->whereIn('i.status', ['PLANNED', 'APPROVED'])
            ->whereNotNull('i.planned_tender_date')
            ->whereBetween('i.planned_tender_date', [now()->toDateString(), now()->addDays(60)->toDateString()])
            ->orderBy('i.planned_tender_date')
            ->limit(50)
            ->get();
    }

    private function delayedLines(int $companyId, array $filters)
    {
        return $this->lineQuery($companyId, $filters)
            ->whereNotIn('i.status', ['COMPLETED', 'CANCELLED', 'AWARDED'])
            ->whereRaw('coalesce(i.expected_award_date, i.planned_tender_date) < ?', [now()->toDateString()])
            ->orderByRaw('coalesce(i.expected_award_date, i.planned_tender_date)')
            ->limit(50)
            ->get();
    }

    private function lineQuery(int $companyId, array $filters)
    {
        return DB::table('procgov_plan_items as i')
            ->join('procgov_plans as p', 'p.id', '=', 'i.plan_id')
            ->leftJoin('users as ou', 'ou.id', '=', 'i.responsible_officer_id')
            ->leftJoin('hr_people as op', 'op.user_id', '=', 'ou.id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->whereNull('p.deleted_at')
            ->when($filters['financial_year'] ?? null, fn ($q, $fy) => $q->where('p.financial_year_label', $fy))
            ->when($filters['company_unit_id'] ?? null, fn ($q, $u) => $q->where(function ($inner) use ($u) {
                $inner->where('i.company_unit_id', $u)->orWhere('p.company_unit_id', $u);
            }))
            ->when($filters['officer_id'] ?? null, fn ($q, $o) => $q->where('i.responsible_officer_id', $o))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('i.category', $c))
            ->select([
                'i.uuid', 'i.reference', 'i.description', 'i.estimated_value', 'i.status',
                'i.planned_tender_date', 'i.expected_award_date', 'i.priority',
                'p.uuid as plan_uuid', 'p.reference as plan_reference',
                DB::raw("trim(coalesce(op.first_name,'') || ' ' || coalesce(op.surname,'')) as officer"),
            ]);
    }
}
