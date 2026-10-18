<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Services\ClaimsApprovalDelegationService;
use App\Modules\Finance\Claims\Services\ClaimsService;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsApprovalController extends Controller
{
    use ClaimsAuthTrait;

    public function __construct(
        private readonly ClaimsService $service,
        private readonly ClaimsApprovalDelegationService $delegationService,
    ) {}

    public function index(): Response
    {
        abort_unless($this->isClaimsApprover(), 403);

        $userId = auth()->id();

        // Include claims where I am the assigned approver, OR someone who delegated to me is
        $delegatingToMe = $this->delegationService->delegatingTo($userId);
        $approverIds    = array_merge([$userId], $delegatingToMe);

        $with = ['employee.person', 'category', 'policyViolations', 'riskScore', 'items.attachments'];

        $query = Claim::with($with)
            ->whereIn('status', ['submitted', 'under_review'])
            ->where('employee_id', '!=', $userId)
            ->whereIn('current_approver_id', $approverIds);

        if ($this->isClaimsAdmin()) {
            $query = Claim::with($with)
                ->whereIn('status', ['submitted', 'under_review'])
                ->where('employee_id', '!=', $userId);
        }

        $pending = $query->latest()->get()->map(fn($c) => array_merge(
            $this->claimSummary($c),
            $this->receiptSummary($c),
        ));

        $myDelegation = $this->delegationService->activeDelegation($userId);

        return Inertia::render('Modules/Finance/Claims/Approvals/Index', [
            'pendingClaims'      => $pending,
            'isAdmin'            => $this->isClaimsAdmin(),
            'canSeeDashboard'    => $this->canViewClaimsDashboard(),
            'myDelegation'  => $myDelegation ? [
                'id'         => $myDelegation->id,
                'delegate'   => $this->resolvePersonName($myDelegation->delegate_id),
                'start_date' => $myDelegation->start_date->format('d M Y'),
                'end_date'   => $myDelegation->end_date->format('d M Y'),
            ] : null,
        ]);
    }

    public function approve(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($this->canApproveClaim($claim), 403);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->approveClaim($claim, $data);

        activity('Claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('approved')
            ->withProperties(['reference_number' => $claim->reference_number, 'total_amount' => $claim->total_amount])
            ->log("Claim {$claim->reference_number} approved.");

        return back()->with('success', "Claim {$claim->reference_number} approved.");
    }

    public function reject(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($this->canApproveClaim($claim), 403);

        $data = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->service->rejectClaim($claim, $data);

        activity('Claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('rejected')
            ->withProperties(['reference_number' => $claim->reference_number, 'reason' => $data['reason']])
            ->log("Claim {$claim->reference_number} rejected.");

        return back()->with('success', "Claim {$claim->reference_number} rejected.");
    }

    public function pay(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($this->isClaimsAdmin(), 403);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $this->service->payClaim($claim, $data);

        activity('Claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('paid')
            ->withProperties(['reference_number' => $claim->reference_number, 'total_amount' => $claim->total_amount])
            ->log("Claim {$claim->reference_number} marked as paid.");

        return back()->with('success', "Claim {$claim->reference_number} marked as paid.");
    }

    private function resolvePersonName(int $userId): string
    {
        $name = \Illuminate\Support\Facades\DB::table('hr_people as p')
            ->where('p.user_id', $userId)
            ->selectRaw("TRIM(p.first_name || ' ' || p.surname) as name")
            ->value('name');

        return $name ?: (\Illuminate\Support\Facades\DB::table('users')->where('id', $userId)->value('email') ?? "User #{$userId}");
    }

    private function claimSummary(Claim $c): array
    {
        return [
            'id'               => $c->id,
            'uuid'             => $c->uuid,
            'reference_number' => $c->reference_number,
            'employee'         => $c->employeeName(),
            'title'            => $c->title,
            'category'         => $c->category?->name ?? '—',
            'is_travel'        => (bool) ($c->category?->is_travel),
            'total_amount'     => (float) $c->total_amount,
            'currency'         => $c->currency,
            'status'           => $c->status,
            'risk_level'       => $c->risk_level,
            'risk_score'       => $c->risk_score ? (float) $c->risk_score : null,
            'violations'       => $c->policyViolations->count(),
            'has_blocks'       => $c->policyViolations->where('severity', 'block')->where('exception_approved', false)->count() > 0,
            'claim_date'       => $c->claim_date->format('d M Y'),
            'submitted_at'     => $c->submitted_at?->format('d M Y'),
            'age_days'         => $c->age_in_days,
        ];
    }

    private function receiptSummary(Claim $c): array
    {
        $requiresReceipt = (bool) ($c->category?->requires_receipt) && ! ($c->category?->is_travel);

        $lineItems = $c->items->map(fn($i) => [
            'id'               => $i->id,
            'description'      => $i->description,
            'amount'           => (float) $i->amount,
            'receipt_required' => $i->receipt_required,
            'receipt_uploaded' => $i->receipt_uploaded,
            'receipts'         => $i->attachments
                ->where('attachment_type', 'receipt')
                ->map(fn($a) => [
                    'id'           => $a->id,
                    'name'         => $a->original_name,
                    'mime'         => $a->mime,
                    'download_url' => $a->uuid ? route('claims.attachments.download', $a->uuid) : null,
                ])->values(),
        ]);

        $missingCount = $lineItems->where('receipt_required', true)->where('receipt_uploaded', false)->count();

        return [
            'receipts_required'     => $requiresReceipt,
            'all_receipts_uploaded' => $missingCount === 0,
            'missing_receipts'      => $missingCount,
            'line_items'            => $lineItems,
        ];
    }
}
