<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\ClaimApprovalDelegation;
use App\Modules\Finance\Claims\Services\ClaimsApprovalDelegationService;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsDelegationController extends Controller
{
    use ClaimsAuthTrait;

    public function __construct(
        private readonly ClaimsApprovalDelegationService $delegationService,
    ) {}

    public function index(): Response
    {
        abort_unless($this->isClaimsApprover(), 403);

        $userId = auth()->id();

        $activeDelegation = $this->delegationService->activeDelegation($userId);

        $auditLog = $this->delegationService->auditLog($userId)->map(function ($d) use ($userId) {
            $isOutbound = $d->delegator_id === $userId;
            return [
                'id'          => $d->id,
                'direction'   => $isOutbound ? 'outbound' : 'inbound',
                'other_party' => $isOutbound ? $this->personName($d->delegate_id) : $this->personName($d->delegator_id),
                'start_date'  => $d->start_date->format('d M Y'),
                'end_date'    => $d->end_date->format('d M Y'),
                'reason'      => $d->reason,
                'is_active'   => $d->is_active,
                'revoked_at'  => $d->revoked_at?->format('d M Y H:i'),
            ];
        });

        $users = DB::table('users as u')
            ->join('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('u.status', 1)
            ->where('u.id', '!=', $userId)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['u.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'u.email'])
            ->toArray();

        return Inertia::render('Modules/Finance/Claims/Approvals/Delegations', [
            'activeDelegation' => $activeDelegation ? [
                'id'         => $activeDelegation->id,
                'delegate'   => $this->personName($activeDelegation->delegate_id),
                'start_date' => $activeDelegation->start_date->format('d M Y'),
                'end_date'   => $activeDelegation->end_date->format('d M Y'),
                'reason'     => $activeDelegation->reason,
            ] : null,
            'auditLog' => $auditLog,
            'users'    => $users,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isClaimsApprover(), 403);

        $data = $request->validate([
            'delegate_id' => 'required|exists:users,id|different:' . auth()->id(),
            'start_date'  => 'required|date|after_or_equal:today',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'reason'      => 'nullable|string|max:300',
        ]);

        $delegation = $this->delegationService->delegate(
            delegatorId: auth()->id(),
            delegateId:  $data['delegate_id'],
            startDate:   $data['start_date'],
            endDate:     $data['end_date'],
            reason:      $data['reason'] ?? null,
            createdBy:   auth()->id(),
        );

        activity('claims')
            ->causedBy(auth()->user())
            ->performedOn($delegation)
            ->withProperties([
                'delegate'   => $this->personName($data['delegate_id']),
                'start_date' => $data['start_date'],
                'end_date'   => $data['end_date'],
            ])
            ->event('delegation_created')
            ->log("Approval authority delegated to {$this->personName($data['delegate_id'])} from {$data['start_date']} to {$data['end_date']}.");

        return back()->with('success', 'Approval delegation created successfully.');
    }

    public function revoke(ClaimApprovalDelegation $delegation): RedirectResponse
    {
        abort_unless(
            $delegation->delegator_id === auth()->id() || $this->isClaimsAdmin(),
            403
        );

        $delegateName = $this->personName($delegation->delegate_id);
        $this->delegationService->revoke($delegation->id, auth()->id());

        activity('claims')
            ->causedBy(auth()->user())
            ->performedOn($delegation)
            ->withProperties(['delegate' => $delegateName])
            ->event('delegation_revoked')
            ->log("Approval delegation to {$delegateName} revoked.");

        return back()->with('success', 'Delegation revoked.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function personName(int $userId): string
    {
        $name = DB::table('hr_people as p')
            ->where('p.user_id', $userId)
            ->selectRaw("TRIM(p.first_name || ' ' || p.surname) as name")
            ->value('name');

        if ($name) return $name;

        return DB::table('users')->where('id', $userId)->value('email') ?? "User #{$userId}";
    }
}
