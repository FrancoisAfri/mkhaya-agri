<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Modules\Workflow\Services\AssigneeResolver;
use App\Modules\Workflow\Services\Presenter;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Support\WorkflowPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Out-of-office delegation (PRS §8.4): dated, scoped by category, audited, and never wider than the delegator's own right to act. */
class WorkflowDelegationController extends WorkflowController
{
    public function __construct(private readonly Presenter $present, private readonly WorkflowAudit $audit)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        $user = $request->user();
        $manage = $this->can(WorkflowPermissions::MANAGE);
        $rows = WorkflowDelegation::query()->when(! $manage, fn ($q) => $q->where(fn ($w) => $w->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)))
            ->orderByDesc('starts_at')->limit(200)->get();

        return Inertia::render('Modules/Workflow/Delegations', [
            'delegations' => $rows->map(fn ($d) => ['uuid' => $d->uuid, 'from' => $this->present->name($d->from_user_id), 'to' => $this->present->name($d->to_user_id),
                'from_me' => $d->from_user_id === $user->id, 'to_me' => $d->to_user_id === $user->id, 'starts_at' => $d->starts_at->toIso8601String(), 'ends_at' => $d->ends_at->toIso8601String(),
                'categories' => $d->categories ?? [], 'reason' => $d->reason, 'status' => $d->status === 'active' && $d->ends_at->isPast() ? 'expired' : $d->status]),
            'people' => $this->people(),
            'categories' => app(\App\Modules\Workflow\Support\WorkflowSettings::class)->categories(),
            'can' => ['manage' => $manage],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $d = $request->validate(['from_user_id' => ['nullable', 'integer'], 'to_user_id' => ['required', 'integer'], 'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'], 'categories' => ['array'],
            'categories.*' => ['string', \Illuminate\Validation\Rule::in(app(\App\Modules\Workflow\Support\WorkflowSettings::class)->categories())], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $user = $request->user();
        $from = (int) ($d['from_user_id'] ?? $user->id);
        if ($from !== $user->id && ! $this->can(WorkflowPermissions::MANAGE)) {
            $this->allow(WorkflowPermissions::MANAGE, 'delegation.create_for_other');
        }
        if ($from === (int) $d['to_user_id']) {
            return back()->with('error', 'Choose someone else to act for you.');
        }
        if (! app(AssigneeResolver::class)->active([(int) $d['to_user_id'], $from], $user->company_id) || count(app(AssigneeResolver::class)->active([(int) $d['to_user_id'], $from], $user->company_id)) < 2) {
            return back()->with('error', 'Both people must be active users of this company.');
        }
        if (now()->parse($d['ends_at'])->diffInDays(now()->parse($d['starts_at'])) > 366) {
            return back()->with('error', 'A delegation can last at most a year.');
        }
        $delegation = WorkflowDelegation::create(['from_user_id' => $from, 'to_user_id' => (int) $d['to_user_id'], 'starts_at' => $d['starts_at'], 'ends_at' => $d['ends_at'],
            'categories' => $d['categories'] ?? [], 'reason' => $d['reason'], 'created_by' => $user->id]);
        $this->audit->record('delegation.created', $delegation, ['reason' => $d['reason'], 'after' => ['from' => $from, 'to' => (int) $d['to_user_id'], 'until' => $d['ends_at'], 'categories' => $d['categories'] ?? []]]);

        return back()->with('success', 'Delegation set. It ends on its own at the end date.');
    }

    public function revoke(Request $request, WorkflowDelegation $delegation): RedirectResponse
    {
        $this->allow(WorkflowPermissions::VIEW);
        $user = $request->user();
        if ($delegation->from_user_id !== $user->id && ! $this->can(WorkflowPermissions::MANAGE)) {
            abort(403, 'Only the person who delegated, or a process manager, can end it.');
        }
        $delegation->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->audit->record('delegation.revoked', $delegation, []);

        return back()->with('success', 'Delegation ended.');
    }
}
