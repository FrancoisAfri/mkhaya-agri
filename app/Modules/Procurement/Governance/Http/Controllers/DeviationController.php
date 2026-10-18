<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\DeviationService;
use App\Modules\Procurement\Governance\Services\DocumentService;
use App\Modules\Procurement\Governance\Support\Integration\SupplierBridge;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deviation Management (§5).
 *
 * Note the asymmetry between `store`/`update` and `submit`: saving validates almost nothing, while
 * submitting runs the tenant's full rule set. That is deliberate — see DeviationService — and it is
 * why the create form shows a live compliance preview rather than blocking the save button.
 */
class DeviationController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly DeviationService $deviations,
        private readonly DocumentService $documents,
        private readonly OptionLists $options,
        private readonly SupplierBridge $suppliers,
        private readonly Capabilities $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $rows = $companyId === null ? collect() : DB::table('procgov_deviations as d')
            ->leftJoin('company_units as u', 'u.id', '=', 'd.company_unit_id')
            ->leftJoin('users as o', 'o.id', '=', 'd.requesting_officer_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'o.id')
            ->leftJoin('procgov_records as r', 'r.id', '=', 'd.record_id')
            ->where('d.company_id', $companyId)
            ->whereNull('d.deleted_at')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('d.status', $s))
            ->when($request->string('risk_level')->toString(), fn ($q, $s) => $q->where('d.risk_level', $s))
            ->when($request->integer('company_unit_id'), fn ($q, $v) => $q->where('d.company_unit_id', $v))
            ->orderByDesc('d.created_at')
            ->limit(400)
            ->get([
                'd.id', 'd.uuid', 'd.reference', 'd.title', 'd.status', 'd.risk_level',
                'd.deviation_type_code', 'd.procurement_value', 'd.currency', 'd.compliance_result',
                'd.submitted_at', 'd.decided_at', 'd.decision', 'd.supplier_name',
                'u.name as department', 'r.reference as record_reference', 'r.uuid as record_uuid',
                DB::raw("trim(coalesce(p.first_name,'') || ' ' || coalesce(p.surname,'')) as officer"),
            ]);

        return Inertia::render('Modules/ProcurementGovernance/Deviations/Index', [
            'deviations' => $rows,
            'filters' => $request->only(['status', 'risk_level', 'company_unit_id']),
            'statuses' => array_keys(DeviationService::TRANSITIONS),
            'riskLevels' => DeviationService::RISK_LEVELS,
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'noCompany' => $companyId === null,
            'can' => [
                'create' => (bool) $request->user()?->can('procgov.deviation.create'),
                'approve' => (bool) $request->user()?->can('procgov.deviation.approve'),
            ],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/ProcurementGovernance/Deviations/Create', [
            'types' => $companyId ? $this->deviationTypes($companyId) : [],
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'suppliers' => $companyId ? $this->suppliers->search($companyId, null, 200) : [],
            'supplierModule' => $this->suppliers->isAvailable(),
            'riskLevels' => DeviationService::RISK_LEVELS,
            'records' => $companyId ? $this->openRecords($companyId) : [],
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'record_id' => ['nullable', 'integer'],
            'procurement_reference' => ['nullable', 'string', 'max:80'],
            'company_unit_id' => ['nullable', 'integer'],
            'requesting_officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'procurement_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:190'],
            'deviation_type_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'business_justification' => ['nullable', 'string', 'max:10000'],
            'circumstances' => ['nullable', 'string', 'max:10000'],
            'risk_assessment' => ['nullable', 'string', 'max:10000'],
            'risk_level' => ['nullable', Rule::in(DeviationService::RISK_LEVELS)],
            'proposed_alternative' => ['nullable', 'string', 'max:5000'],
            'financial_impact' => ['nullable', 'string', 'max:5000'],
            'financial_impact_amount' => ['nullable', 'numeric'],
            'requested_approval_date' => ['nullable', 'date'],
        ]);

        $deviation = $this->deviations->create($companyId, $data);

        return redirect()
            ->route('procgov.deviations.show', $deviation->uuid)
            ->with('success', __('procurement_governance.flash.deviations.created', ['reference' => $deviation->reference]));
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);
        $deviation = $this->find($companyId, $uuid);

        $approval = $deviation->approval_request_id
            ? DB::table('procgov_approval_requests')->find($deviation->approval_request_id)
            : null;

        $check = $deviation->compliance_check_id
            ? DB::table('procgov_compliance_checks')->find($deviation->compliance_check_id)
            : null;

        return Inertia::render('Modules/ProcurementGovernance/Deviations/Show', [
            'deviation' => $deviation,
            'history' => $companyId ? $this->deviations->history($companyId, (int) $deviation->id) : [],
            'preflight' => $companyId && $deviation->status === 'DRAFT'
                ? $this->deviations->preflight($companyId, (int) $deviation->id)
                : null,
            'complianceCheck' => $check,
            'complianceResults' => $check
                ? DB::table('procgov_compliance_results')
                    ->where('check_id', $check->id)
                    ->orderByRaw("case outcome when 'FAIL' then 0 when 'WARNING' then 1 when 'SKIPPED' then 2 else 3 end")
                    ->get()
                : [],
            'approval' => $approval,
            'approvalSteps' => $approval
                ? DB::table('procgov_approval_steps')->where('request_id', $approval->id)->orderBy('step_no')->get()
                : [],
            'documents' => $companyId
                ? $this->documents->forSubject($companyId, 'procgov_deviations', (int) $deviation->id, $request->user())
                : [],
            'supplier' => $companyId && $deviation->supplier_id
                ? $this->suppliers->compliance((int) $deviation->supplier_id, $companyId)
                : null,
            'nextStatuses' => DeviationService::TRANSITIONS[$deviation->status] ?? [],
            'types' => $companyId ? $this->deviationTypes($companyId) : [],
            'riskLevels' => DeviationService::RISK_LEVELS,
            'can' => [
                'edit' => (bool) $request->user()?->can('procgov.deviation.edit'),
                'submit' => (bool) $request->user()?->can('procgov.deviation.create'),
                'approve' => (bool) $request->user()?->can('procgov.deviation.approve'),
            ],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $deviation = $this->find($companyId, $uuid);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'procurement_value' => ['nullable', 'numeric', 'min:0'],
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:190'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'business_justification' => ['nullable', 'string', 'max:10000'],
            'circumstances' => ['nullable', 'string', 'max:10000'],
            'risk_assessment' => ['nullable', 'string', 'max:10000'],
            'risk_level' => ['nullable', Rule::in(DeviationService::RISK_LEVELS)],
            'proposed_alternative' => ['nullable', 'string', 'max:5000'],
            'financial_impact' => ['nullable', 'string', 'max:5000'],
            'financial_impact_amount' => ['nullable', 'numeric'],
            'requested_approval_date' => ['nullable', 'date'],
        ]);

        try {
            $this->deviations->update($companyId, (int) $deviation->id, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.deviations.updated'));
    }

    public function submit(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $deviation = $this->find($companyId, $uuid);

        $data = $request->validate([
            'accept_warnings' => ['nullable', 'boolean'],
            'acceptance_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $accept = (bool) ($data['accept_warnings'] ?? false);

        // Accepting a warning without saying why is the same as not reading it.
        if ($accept && trim((string) ($data['acceptance_reason'] ?? '')) === '') {
            return back()->with('error', __('procurement_governance.flash.deviations.acceptance_reason_required'));
        }

        $result = $this->deviations->submit(
            $companyId,
            (int) $deviation->id,
            $accept,
            $data['acceptance_reason'] ?? null,
        );

        if (! $result['ok']) {
            return back()->with('error', __('procurement_governance.flash.deviations.cannot_submit', ['problems' => implode(' ', $result['problems'])]));
        }

        return back()->with('success', __('procurement_governance.flash.deviations.submitted', ['reference' => $deviation->reference]));
    }

    public function transition(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $deviation = $this->find($companyId, $uuid);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(DeviationService::TRANSITIONS))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->deviations->transition($companyId, (int) $deviation->id, $data['status'], $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.deviations.moved', ['status' => $data['status']]));
    }

    public function uploadDocument(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $deviation = $this->find($companyId, $uuid);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'title' => ['nullable', 'string', 'max:255'],
            'category_code' => ['nullable', 'string', 'max:40'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $this->documents->store($companyId, $request->file('file'), [
            'title' => $request->input('title'),
            'category_code' => $request->input('category_code'),
            'expires_at' => $request->input('expires_at'),
            'link' => [
                'link_role' => 'deviation',
                'entity_type' => 'procgov_deviations',
                'entity_id' => (int) $deviation->id,
                'entity_reference' => $deviation->reference,
            ],
        ]);

        return back()->with('success', __('procurement_governance.flash.deviations.document_attached'));
    }

    // ── Deviation types (§5.1 configuration) ─────────────────────────────────────────────────────

    public function storeType(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'default_risk_level' => ['nullable', Rule::in(DeviationService::RISK_LEVELS)],
            'approval_chain_code' => ['nullable', 'string', 'max:40'],
            'requires_supplier' => ['boolean'],
            'requires_risk_assessment' => ['boolean'],
        ]);

        DB::table('procgov_deviation_types')->updateOrInsert(
            ['company_id' => $companyId, 'code' => $data['code']],
            array_merge($data, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
        );

        return back()->with('success', __('procurement_governance.flash.deviations.type_saved'));
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function find(?int $companyId, string $uuid): object
    {
        $deviation = DB::table('procgov_deviations')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $deviation, 404);

        return $deviation;
    }

    private function deviationTypes(int $companyId): array
    {
        return DB::table('procgov_deviation_types')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'default_risk_level',
                'requires_supplier', 'requires_risk_assessment'])
            ->map(fn ($t) => (array) $t)
            ->all();
    }

    private function openRecords(int $companyId): array
    {
        return DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->orderByDesc('opened_at')
            ->limit(200)
            ->get(['id', 'reference', 'title', 'estimated_value', 'company_unit_id'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
