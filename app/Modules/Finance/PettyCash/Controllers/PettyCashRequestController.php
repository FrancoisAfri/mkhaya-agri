<?php

namespace App\Modules\Finance\PettyCash\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use App\Modules\Finance\PettyCash\Models\PettyCashPolicyViolation;
use App\Modules\Finance\PettyCash\Services\PettyCashAIService;
use App\Modules\Finance\PettyCash\Services\PettyCashPolicyGuard;
use App\Modules\Finance\PettyCash\Services\PettyCashService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PettyCashRequestController extends Controller
{
    public function __construct(
        private PettyCashService   $service,
        private PettyCashPolicyGuard $policyGuard,
        private PettyCashAIService   $aiService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = PettyCashRequest::with(['requestor', 'float', 'custodian'])
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('float_id'), fn($q) => $q->where('float_id', $request->float_id))
            ->when($request->filled('search'), fn($q) => $q->where(function ($s) use ($request) {
                $s->where('reference_number', 'ilike', "%{$request->search}%")
                  ->orWhere('purpose', 'ilike', "%{$request->search}%");
            }))
            ->orderByDesc('created_at');

        // Non-admins only see their own requests or requests for their float
        if (!$user->hasRole(['super admin', 'administrator', 'finance manager'])) {
            $custodianFloatIds = PettyCashFloat::where('custodian_id', $user->id)->pluck('id');
            $query->where(function ($q) use ($user, $custodianFloatIds) {
                $q->where('requestor_id', $user->id)
                  ->orWhereIn('float_id', $custodianFloatIds);
            });
        }

        $requests = $query->paginate(25)->withQueryString();

        return Inertia::render('Modules/Finance/PettyCash/Requests/Index', [
            'requests' => $requests->through(fn($r) => [
                'id'               => $r->id,
                'uuid'             => $r->uuid,
                'reference_number' => $r->reference_number,
                'requestor'        => $r->requestor?->name,
                'custodian'        => $r->custodian?->name,
                'float_name'       => $r->float?->name,
                'purpose'          => $r->purpose,
                'category'         => $r->category,
                'amount_requested' => $r->amount_requested,
                'amount_issued'    => $r->amount_issued,
                'status'           => $r->status,
                'risk_level'       => $r->risk_level,
                'receipt_submitted'=> $r->receipt_submitted,
                'receipt_overdue'  => $r->isReceiptOverdue(),
                'requested_at'     => $r->requested_at?->toISOString(),
                'issued_at'        => $r->issued_at?->toISOString(),
            ]),
            'filters'       => $request->only('status', 'float_id', 'search'),
            'floats'        => PettyCashFloat::where('status', 'active')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Modules/Finance/PettyCash/Requests/Create', [
            'floats' => PettyCashFloat::where('status', 'active')
                ->get(['id', 'uuid', 'name', 'current_balance', 'float_limit'])
                ->map(fn($f) => [
                    'id'              => $f->id,
                    'name'            => $f->name,
                    'current_balance' => $f->current_balance,
                    'float_limit'     => $f->float_limit,
                ]),
            'categories' => [
                ['value' => 'office_supplies', 'label' => 'Office Supplies'],
                ['value' => 'travel',          'label' => 'Travel & Transport'],
                ['value' => 'maintenance',     'label' => 'Maintenance & Repairs'],
                ['value' => 'refreshments',    'label' => 'Refreshments & Catering'],
                ['value' => 'postage',         'label' => 'Postage & Courier'],
                ['value' => 'printing',        'label' => 'Printing & Stationery'],
                ['value' => 'other',           'label' => 'Other'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'float_id'         => 'required|exists:petty_cash_floats,id',
            'purpose'          => 'required|string|max:500',
            'category'         => 'nullable|string|max:100',
            'amount_requested' => 'required|numeric|min:0.01|max:99999',
            'notes'            => 'nullable|string',
        ]);

        $pcRequest = $this->service->requestPettyCash($data, $request->user()->id);

        // Policy guard throws ValidationException if any rule is 'block'.
        // Warnings and escalations are returned for local tracking.
        $policyResult = $this->policyGuard->forRequestSubmitted($pcRequest, $request->user()->id);

        foreach ($policyResult['warnings'] as $warning) {
            PettyCashPolicyViolation::create([
                'request_id'            => $pcRequest->id,
                'float_id'              => $pcRequest->float_id,
                'violation_type'        => 'policy_warning',
                'violation_description' => $warning,
                'severity'              => 'medium',
                'policy_rule'           => $warning,
                'status'                => 'open',
                'detected_at'           => now(),
            ]);
        }

        if ($policyResult['requires_escalation']) {
            PettyCashPolicyViolation::create([
                'request_id'            => $pcRequest->id,
                'float_id'              => $pcRequest->float_id,
                'violation_type'        => 'escalation_required',
                'violation_description' => 'Request flagged for investigation by policy engine.',
                'severity'              => 'high',
                'status'                => 'escalated',
                'detected_at'           => now(),
            ]);
        }

        $riskResult = $this->aiService->calculatePettyCashRiskScore($pcRequest);
        $pcRequest->update([
            'risk_score' => $riskResult['score'],
            'risk_level' => $riskResult['level'],
            'ai_flags'   => $riskResult['flags'],
        ]);

        return redirect()->route('petty-cash.requests.show', $pcRequest->uuid)
            ->with('success', 'Petty cash request submitted.');
    }

    public function show(PettyCashRequest $pcRequest): Response
    {
        $pcRequest->load(['requestor', 'custodian', 'approver', 'float', 'attachments', 'approvals.approver', 'policyViolations']);

        return Inertia::render('Modules/Finance/PettyCash/Requests/Show', [
            'request' => [
                'id'               => $pcRequest->id,
                'uuid'             => $pcRequest->uuid,
                'reference_number' => $pcRequest->reference_number,
                'requestor'        => $pcRequest->requestor?->name,
                'custodian'        => $pcRequest->custodian?->name,
                'approver'         => $pcRequest->approver?->name,
                'float_name'       => $pcRequest->float?->name,
                'purpose'          => $pcRequest->purpose,
                'category'         => $pcRequest->category,
                'amount_requested' => $pcRequest->amount_requested,
                'amount_approved'  => $pcRequest->amount_approved,
                'amount_issued'    => $pcRequest->amount_issued,
                'amount_spent'     => $pcRequest->amount_spent,
                'amount_returned'  => $pcRequest->amount_returned,
                'status'           => $pcRequest->status,
                'rejection_reason' => $pcRequest->rejection_reason,
                'notes'            => $pcRequest->notes,
                'risk_score'       => $pcRequest->risk_score,
                'risk_level'       => $pcRequest->risk_level,
                'ai_flags'         => $pcRequest->ai_flags,
                'receipt_submitted'=> $pcRequest->receipt_submitted,
                'receipt_overdue'  => $pcRequest->isReceiptOverdue(),
                'receipt_due_at'   => $pcRequest->receipt_due_at?->toISOString(),
                'requested_at'     => $pcRequest->requested_at?->toISOString(),
                'approved_at'      => $pcRequest->approved_at?->toISOString(),
                'issued_at'        => $pcRequest->issued_at?->toISOString(),
                'settled_at'       => $pcRequest->settled_at?->toISOString(),
            ],
            'attachments'      => $pcRequest->attachments->map(fn($a) => [
                'id'            => $a->id,
                'original_name' => $a->original_name,
                'url'           => $a->url,
                'type'          => $a->attachment_type,
                'created_at'    => $a->created_at->toISOString(),
            ]),
            'approvals'        => $pcRequest->approvals->map(fn($a) => [
                'approver'  => $a->approver?->name,
                'action'    => $a->action,
                'comments'  => $a->comments,
                'acted_at'  => $a->acted_at->toISOString(),
            ]),
            'policyViolations' => $pcRequest->policyViolations->map(fn($v) => [
                'id'          => $v->id,
                'type'        => $v->violation_type,
                'description' => $v->violation_description,
                'severity'    => $v->severity,
                'status'      => $v->status,
            ]),
        ]);
    }

    public function approve(Request $request, PettyCashRequest $pcRequest): RedirectResponse
    {
        $data = $request->validate(['comments' => 'nullable|string']);

        $this->service->approvePettyCashRequest($pcRequest, $request->user()->id, $data['comments'] ?? null);

        activity('PettyCash')
            ->causedBy(Auth::user())
            ->performedOn($pcRequest)
            ->event('approved')
            ->withProperties(['reference_number' => $pcRequest->reference_number, 'amount_approved' => $pcRequest->amount_approved])
            ->log("Petty cash request {$pcRequest->reference_number} approved.");

        return back()->with('success', 'Request approved.');
    }

    public function reject(Request $request, PettyCashRequest $pcRequest): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->rejectPettyCashRequest($pcRequest, $request->user()->id, $data['reason']);

        activity('PettyCash')
            ->causedBy(Auth::user())
            ->performedOn($pcRequest)
            ->event('rejected')
            ->withProperties(['reference_number' => $pcRequest->reference_number, 'reason' => $data['reason']])
            ->log("Petty cash request {$pcRequest->reference_number} rejected.");

        return back()->with('success', 'Request rejected.');
    }

    public function issue(Request $request, PettyCashRequest $pcRequest): RedirectResponse
    {
        $this->service->issuePettyCash($pcRequest, $request->user()->id);

        activity('PettyCash')
            ->causedBy(Auth::user())
            ->performedOn($pcRequest)
            ->event('issued')
            ->withProperties(['reference_number' => $pcRequest->reference_number, 'amount_issued' => $pcRequest->amount_issued])
            ->log("Cash issued for request {$pcRequest->reference_number}.");

        return back()->with('success', 'Cash issued successfully.');
    }

    public function settle(Request $request, PettyCashRequest $pcRequest): RedirectResponse
    {
        $data = $request->validate([
            'amount_spent'    => 'required|numeric|min:0',
            'amount_returned' => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string',
        ]);

        $this->service->recordPettyCashSpend($pcRequest, $data);

        activity('PettyCash')
            ->causedBy(Auth::user())
            ->performedOn($pcRequest)
            ->event('settled')
            ->withProperties(['reference_number' => $pcRequest->reference_number, 'amount_spent' => $data['amount_spent'], 'amount_returned' => $data['amount_returned'] ?? 0])
            ->log("Petty cash request {$pcRequest->reference_number} settled.");

        return back()->with('success', 'Spend recorded and request settled.');
    }

    public function uploadReceipt(Request $request, PettyCashRequest $pcRequest): RedirectResponse
    {
        $request->validate(['receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120']);

        $this->service->uploadAttachment($pcRequest, $request->file('receipt'), $request->user()->id, 'receipt');

        $pcRequest->update(['receipt_submitted' => true]);

        return back()->with('success', 'Receipt uploaded.');
    }
}
