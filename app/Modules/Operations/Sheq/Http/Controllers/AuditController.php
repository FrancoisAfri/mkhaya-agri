<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use App\Modules\Operations\Sheq\Services\AuditService;
use App\Modules\Operations\Sheq\Services\SupplierOnboardingService;
use App\Modules\Operations\Sheq\Services\SupplierPortalBridge;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audits and supplier SHEQ onboarding — the quality half of the module.
 *
 * Together because they are the same workflow: a scored assessment against a standard, producing
 * findings that must be answered and an approval that somebody owns.
 */
class AuditController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly AuditService $audits,
        private readonly SupplierOnboardingService $suppliers,
        private readonly ApprovalService $approvals,
        private readonly SupplierPortalBridge $portal,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Audits/Index', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.compliance.manage'),
            'audits' => $companyId === null ? [] : $this->auditList($companyId),
            'sites' => $companyId === null ? [] : DB::table('sheq_sites')
                ->where('company_id', $companyId)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => (array) $s)->all(),
            'openFindings' => $companyId === null ? 0 : DB::table('sheq_audit_findings')
                ->where('company_id', $companyId)->whereIn('grade', AuditService::ACTIONABLE_GRADES)
                ->whereNotIn('status', ['CLOSED'])->whereNull('deleted_at')->count(),
        ]);
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);

        return Inertia::render('Modules/Sheq/Audits/Show', [
            'canManage' => (bool) $request->user()?->can('sheq.compliance.manage'),
            'grades' => AuditService::GRADES,
        ] + $this->audits->summary($companyId, $uuid));
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.compliance.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.compliance.manage');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'audit_type' => ['required', Rule::in(['INTERNAL', 'SUPPLIER', 'CERTIFICATION',
                'CUSTOMER', 'REGULATORY'])],
            'standard' => ['nullable', 'string', 'max:60'],
            'scope' => ['nullable', 'string', 'max:2000'],
            'site_id' => ['nullable', 'integer'],
            'planned_start' => ['nullable', 'date'],
            'planned_end' => ['nullable', 'date', 'after_or_equal:planned_start'],
        ]);

        $audit = $this->audits->plan($companyId, $validated, $request->user()?->id);

        return redirect("/sheq/audits/{$audit['uuid']}")
            ->with('success', "{$audit['reference']} planned.");
    }

    public function recordFieldwork(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'responses' => ['required', 'array'],
            'responses.*.answer' => ['required', 'string', 'max:30'],
            'responses.*.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->audits->recordFieldwork($companyId, $uuid, $validated['responses'],
            $request->user()?->id);

        return back()->with('success', $result['score'] === null
            ? 'Fieldwork saved.'
            : "Fieldwork saved — {$result['score']}% conformity across {$result['questions_scored']} questions.");
    }

    public function raiseFinding(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.compliance.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.compliance.manage');

        $validated = $request->validate([
            'grade' => ['required', Rule::in(AuditService::GRADES)],
            'clause' => ['nullable', 'string', 'max:60'],
            'statement' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'string', 'max:2000'],
            'responsible_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
        ]);

        $finding = $this->audits->raiseFinding($companyId, $uuid, $validated, $request->user()?->id);

        return back()->with('success', $finding['action_id']
            ? "{$finding['reference']} raised, with a corrective action assigned."
            : "{$finding['reference']} raised.");
    }

    public function respondToFinding(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $validated = $request->validate([
            'root_cause' => ['required', 'string', 'max:2000'],
            'correction' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->audits->respondToFinding($companyId, $uuid, $validated, $request->user()?->id);

        return back()->with('success', 'Response recorded. It now needs independent verification.');
    }

    public function requestFindingClosure(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $this->audits->requestClosure($companyId, $uuid, (int) $request->user()->id);

        return back()->with('success',
            'Sent for verification. Somebody other than you has to agree it is fixed.');
    }

    public function close(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.compliance.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.compliance.manage');

        $validated = $request->validate(['summary' => ['nullable', 'string', 'max:5000']]);

        $this->audits->close($companyId, $uuid, (int) $request->user()->id,
            $validated['summary'] ?? null);

        return back()->with('success', 'Audit closed.');
    }

    // ── Supplier onboarding ───────────────────────────────────────────────────────────────────

    public function suppliers(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/Sheq/Suppliers/Index', [
            'noCompany' => $companyId === null,
            'canManage' => (bool) $request->user()?->can('sheq.contractor.manage'),
            'riskCategories' => SupplierOnboardingService::RISK_CATEGORIES,
            'requiredByRisk' => SupplierOnboardingService::REQUIRED_DOCUMENTS,
            'assessments' => $companyId === null ? [] : $this->assessmentList($companyId),
            'expiring' => $companyId === null ? [] : $this->suppliers->expiringWithin($companyId),
            // Without Supplier Management installed there is nothing to link to, and offering the
            // action would be a button that always refuses.
            'canLink' => $this->portal->isAvailable(),
        ]);
    }

    /**
     * Tie this assessment to the supplier's commercial record (CON-5).
     *
     * `SupplierPortalBridge::link()` was written for exactly this and had no caller, so
     * `sheq_supplier_assessments.supplier_id` was never set: a supplier could hold a safety
     * assessment here and a record in Supplier Management with nothing joining them, and the
     * portal login they already have could not be reused for their safety file.
     */
    public function linkSupplier(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $result = $this->portal->link($this->requireCompany($request), $uuid);

        return $result['linked']
            ? back()->with('success', 'Linked to their Supplier Management record.')
            : back()->with('error', $result['message']);
    }

    public function inviteSupplier(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'contact_email' => ['nullable', 'email', 'max:200'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'scope_of_work' => ['nullable', 'string', 'max:255'],
            'risk_category' => ['required', Rule::in(SupplierOnboardingService::RISK_CATEGORIES)],
        ]);

        $result = $this->suppliers->invite($companyId, $validated, $request->user()?->id);

        return back()->with('success', sprintf(
            '%s invited. %d document(s) required for %s risk.',
            $validated['supplier_name'], count($result['required_documents']), $result['risk_category'],
        ));
    }

    public function submitSupplier(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $this->suppliers->submitForApproval($companyId, $uuid, (int) $request->user()->id);

        return back()->with('success', 'Submitted for approval.');
    }

    /** @return list<array<string,mixed>> */
    private function auditList(int $companyId): array
    {
        return DB::table('sheq_audits as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            ->orderByDesc('a.created_at')->limit(200)
            ->get(['a.uuid', 'a.reference', 'a.title', 'a.audit_type', 'a.standard', 'a.status',
                'a.score', 'a.passed', 'a.planned_start', 'a.completed_at', 's.name as site_name'])
            ->map(function ($audit) use ($companyId) {
                $counts = DB::table('sheq_audit_findings')
                    ->where('company_id', $companyId)
                    ->whereIn('audit_id', function ($q) use ($audit) {
                        $q->select('id')->from('sheq_audits')->where('uuid', $audit->uuid);
                    })
                    ->whereNull('deleted_at')
                    ->selectRaw("count(*) filter (where grade = 'MAJOR_NC') as major,
                        count(*) filter (where grade = 'MINOR_NC') as minor,
                        count(*) filter (where status <> 'CLOSED') as open")
                    ->first();

                return (array) $audit + [
                    'major' => (int) ($counts->major ?? 0),
                    'minor' => (int) ($counts->minor ?? 0),
                    'open_findings' => (int) ($counts->open ?? 0),
                ];
            })->values()->all();
    }

    /** @return list<array<string,mixed>> */
    private function assessmentList(int $companyId): array
    {
        return DB::table('sheq_supplier_assessments')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(200)
            ->get(['uuid', 'supplier_name', 'risk_category', 'status', 'score',
                'valid_until', 'scope_of_work', 'submitted_at', 'supplier_id'])
            ->map(function ($assessment) use ($companyId) {
                $state = $this->suppliers->completeness($companyId, $assessment->uuid);

                /*
                 * The answer somebody actually came for.
                 *
                 * `mayEngage()` was written to say whether this supplier can be used and nothing
                 * called it, so the screen showed a status, a score and a missing-document list and
                 * left the buyer to work out the verdict. The three reasons it checks are not
                 * obvious from those fields either: an approval that has expired reads as
                 * APPROVED, a CONDITIONAL approval is a yes, and evidence that has gone out of
                 * date does not change the status at all. A procurement officer reading
                 * "APPROVED, score 80" would have engaged a supplier whose insurance lapsed.
                 */
                $verdict = $this->suppliers->mayEngage($companyId, $assessment->uuid);

                return (array) $assessment + [
                    'complete' => $state['complete'],
                    'missing' => $state['missing'],
                    'expired' => $state['expired'],
                    'evidence_score' => $state['score'],
                    'may_engage' => $verdict['allowed'],
                    'engage_reasons' => $verdict['reasons'],
                    // CON-5: whether this assessment is tied to a Supplier Management record, which
                    // is what lets the contractor's safety file and their commercial record be read
                    // against each other. Nothing ever set it, because `link()` had no caller.
                    'supplier_id' => $assessment->supplier_id === null
                        ? null : (int) $assessment->supplier_id,
                ];
            })->values()->all();
    }
}
