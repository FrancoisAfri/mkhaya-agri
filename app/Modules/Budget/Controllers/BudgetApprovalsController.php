<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetAdjustment;
use App\Modules\Budget\Models\BudgetApprovalDelegation;
use App\Modules\Budget\Models\BudgetApprovalFlow;
use App\Modules\Budget\Models\BudgetApprovalRequest;
use App\Modules\Budget\Models\BudgetOverride;
use App\Modules\Budget\Models\BudgetReservation;
use App\Modules\Budget\Services\BudgetApprovalService;
use App\Modules\Budget\Services\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The budget approvals inbox.
 *
 * One page for "what is waiting on me", across everything this module routes, plus what the person
 * submitted and is still waiting on. Both halves matter: a queue you cannot see is one nobody works,
 * and a submission you cannot track is one people chase by walking to a desk.
 *
 * Deciding is two acts, deliberately kept together in one request: the engine records the decision,
 * and then the subject is changed. An approval request that says "approved" beside an adjustment
 * still sitting at "pending" is two tables disagreeing about the same money, and the fund bar would
 * be built from the wrong one.
 */
class BudgetApprovalsController extends Controller
{
    public function __construct(
        private readonly BudgetApprovalService $approvals,
        private readonly BudgetService $budgets,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Budget::class);

        $userId = (int) Auth::id();

        return Inertia::render('Modules/Budget/Approvals/Index', [
            'inbox' => $this->approvals->inboxFor()
                ->map(fn (BudgetApprovalRequest $r) => $this->present($r, true))->all(),
            'mine'  => $this->approvals->submittedBy()
                ->map(fn (BudgetApprovalRequest $r) => $this->present($r, false))->all(),
            'tab'   => in_array($request->query('tab'), ['mine', 'cover'], true) ? $request->query('tab') : 'inbox',
            'types' => BudgetApprovalFlow::SUBJECT_TYPES,

            // Who is holding this person's approvals, and whose they are holding. Both halves,
            // because "why am I seeing this?" and "who is covering me?" are the two questions a
            // delegation provokes and neither is answerable from the queue alone.
            'delegations' => [
                'given'    => $this->delegations(BudgetApprovalDelegation::where('from_user_id', $userId)),
                'received' => $this->delegations(BudgetApprovalDelegation::where('to_user_id', $userId)),
            ],
            'people' => $this->people($userId),
            'currentUserId' => $userId,
            'canManageOthers' => $request->user()->can('update', Budget::class),
        ]);
    }

    public function approve(Request $request, string $uuid): RedirectResponse
    {
        $approval = BudgetApprovalRequest::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);

        try {
            $decided = $this->approvals->approve($approval, $data['comment'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        // Only once the ladder has actually been climbed does the subject change. An intermediate
        // signature approves that rung, not the thing.
        if ($decided->status === 'approved') {
            $this->applyApproval($decided);
        }

        return back()->with('success', __('budget_management.flash.approval_recorded'));
    }

    public function reject(Request $request, string $uuid): RedirectResponse
    {
        $approval = BudgetApprovalRequest::where('uuid', $uuid)->firstOrFail();

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        try {
            $decided = $this->approvals->reject($approval, $data['reason']);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        $this->applyRejection($decided, $data['reason']);

        return back()->with('success', __('budget_management.flash.approval_rejected'));
    }

    /** Withdraw your own submission — the polite alternative to asking somebody to reject it. */
    public function cancel(string $uuid): RedirectResponse
    {
        $approval = BudgetApprovalRequest::where('uuid', $uuid)->firstOrFail();

        abort_unless((int) $approval->requested_by === (int) Auth::id(), 403);

        $this->approvals->cancel($approval, __('budget_management.approvals.withdrawn_by_submitter'));
        $this->applyWithdrawal($approval->fresh());

        return back()->with('success', __('budget_management.flash.approval_withdrawn'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Carry a finished decision back to the thing that was decided.
     *
     * Was public, on the stated grounds that the controllers which submit for approval call these
     * when no flow is configured. They do not, and no longer did when this was written — nothing
     * outside this class reaches them. A public controller method that is not a route is a method
     * the framework will happily expose the moment somebody adds one by name.
     */
    private function applyApproval(BudgetApprovalRequest $approval): void
    {
        match ($approval->subject_type) {
            'budget' => $this->withSubject(Budget::class, $approval,
                fn (Budget $b) => $this->budgets->approveBudget($b, Auth::id())),
            'adjustment' => $this->withSubject(BudgetAdjustment::class, $approval,
                fn (BudgetAdjustment $a) => $a->status === 'pending'
                    ? $this->budgets->approveAdjustment($a, Auth::id())
                    : null),
            'override' => $this->withSubject(BudgetOverride::class, $approval,
                fn (BudgetOverride $o) => $o->status === 'pending'
                    ? $this->budgets->approveBudgetOverride($o, Auth::id())
                    : null),
            'reservation' => $this->withSubject(BudgetReservation::class, $approval,
                fn (BudgetReservation $r) => $r->update(['status' => 'active'])),
            default => null,
        };
    }

    private function applyRejection(BudgetApprovalRequest $approval, string $reason): void
    {
        match ($approval->subject_type) {
            'budget' => $this->withSubject(Budget::class, $approval,
                fn (Budget $b) => $this->budgets->rejectBudget($b, Auth::id(), $reason)),
            'adjustment' => $this->withSubject(BudgetAdjustment::class, $approval,
                fn (BudgetAdjustment $a) => $a->update([
                    'status' => 'rejected', 'approved_by' => Auth::id(), 'acted_at' => now(),
                    'reason' => $a->reason ?: $reason,
                ])),
            'override' => $this->withSubject(BudgetOverride::class, $approval,
                fn (BudgetOverride $o) => $o->update([
                    'status' => 'rejected', 'approved_by' => Auth::id(), 'acted_at' => now(),
                ])),
            // Released rather than deleted: the hold is gone, and the reason it existed is kept.
            'reservation' => $this->withSubject(BudgetReservation::class, $approval,
                fn (BudgetReservation $r) => $r->update(['status' => 'released', 'released_at' => now()])),
            default => null,
        };
    }

    /**
     * Withdrawal is not rejection, and the difference shows on the record.
     *
     * A budget somebody pulled back goes to draft so they can correct it and resubmit; one an
     * approver turned down goes to rejected, which is a decision about the work rather than a
     * change of mind about submitting it. Adjustments and overrides have no draft state, so they
     * are closed with the withdrawal as the reason rather than left pending in a queue that no
     * longer contains them.
     */
    private function applyWithdrawal(BudgetApprovalRequest $approval): void
    {
        $reason = __('budget_management.approvals.withdrawn_by_submitter');

        match ($approval->subject_type) {
            'budget' => $this->withSubject(Budget::class, $approval,
                fn (Budget $b) => $b->update(['status' => 'draft'])),
            'adjustment' => $this->withSubject(BudgetAdjustment::class, $approval,
                fn (BudgetAdjustment $a) => $a->update([
                    'status' => 'rejected', 'approved_by' => Auth::id(), 'acted_at' => now(),
                    'reason' => $a->reason ?: $reason,
                ])),
            'override' => $this->withSubject(BudgetOverride::class, $approval,
                fn (BudgetOverride $o) => $o->update([
                    'status' => 'rejected', 'approved_by' => Auth::id(), 'acted_at' => now(),
                ])),
            'reservation' => $this->withSubject(BudgetReservation::class, $approval,
                fn (BudgetReservation $r) => $r->update(['status' => 'released', 'released_at' => now()])),
            default => null,
        };
    }

    /**
     * @param  class-string  $model
     */
    private function withSubject(string $model, BudgetApprovalRequest $approval, callable $apply): void
    {
        $subject = $model::find($approval->subject_id);

        if (! $subject) {
            // The thing was deleted while it sat in the queue. The decision is still a fact worth
            // keeping, so nothing is unwound — there is simply nothing left to apply it to.
            return;
        }

        try {
            $apply($subject);
        } catch (\InvalidArgumentException|\RuntimeException) {
            // Already in the target state, usually because two approvers acted at once, or a
            // transfer whose target has since gone. The decision stands and is visible on the
            // trail; the money was not moved, which is the safe half of the two.
        }
    }

    /**
     * Delegations as the page reads them, live ones first.
     *
     * Expired ones are kept and shown, greyed, because "who was covering me in March" is the
     * question an auditor asks about a decision somebody does not recognise.
     *
     * @return array<int, array<string, mixed>>
     */
    private function delegations($query): array
    {
        $labels = $this->userLabels();

        return $query->orderByDesc('starts_on')->limit(25)->get()
            ->map(fn (BudgetApprovalDelegation $d) => [
                'id'        => $d->uuid,
                'from'      => $labels[(int) $d->from_user_id] ?? "#{$d->from_user_id}",
                'to'        => $labels[(int) $d->to_user_id] ?? "#{$d->to_user_id}",
                'from_id'   => (int) $d->from_user_id,
                'starts_on' => $d->starts_on?->toDateString(),
                'ends_on'   => $d->ends_on?->toDateString(),
                'subject'   => $d->subject_type,
                'max_amount' => $d->max_amount === null ? null : (float) $d->max_amount,
                'reason'    => $d->reason,
                'in_force'  => $d->is_active
                    && $d->starts_on?->isPast()
                    && ($d->ends_on === null || $d->ends_on->endOfDay()->isFuture()),
            ])->all();
    }

    /** @return array<int, array{id:int,label:string}> */
    private function people(int $exceptUserId): array
    {
        return collect($this->userLabels())
            ->except($exceptUserId)
            ->map(fn ($label, $id) => ['id' => (int) $id, 'label' => $label])
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * User id => the name a colleague would recognise.
     *
     * There is no users.name column on this platform; the person's name lives in HR, and a
     * delegation that reads "#41 is covering #17" is one nobody can check.
     *
     * @return array<int, string>
     */
    private function userLabels(): array
    {
        static $labels = null;

        if ($labels !== null) {
            return $labels;
        }

        $userClass = config('auth.providers.users.model');

        return $labels = $userClass::query()
            ->with('person:id,user_id,first_name,surname')
            ->limit(500)
            ->get()
            ->mapWithKeys(function ($user) {
                $person = $user->person;
                $name = $person ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) : '';

                return [(int) $user->id => $name !== '' ? $name : (string) $user->email];
            })
            ->all();
    }

    /** @return array<string,mixed> */
    private function present(BudgetApprovalRequest $r, bool $actionable): array
    {
        $step = $r->currentStep();

        return [
            'id'         => $r->uuid,
            'subject'    => $r->subject_type,
            'label'      => $r->subject_label,
            'budget'     => $r->budget ? ['id' => $r->budget->id, 'code' => $r->budget->code, 'name' => $r->budget->name] : null,
            'amount'     => $r->amount !== null ? (float) $r->amount : null,
            'currency'   => $r->currency,
            'status'     => $r->status,
            'note'       => $r->note,
            'reason'     => $r->decision_reason,
            'step'       => $step?->name,
            'position'   => $r->current_position,
            'steps'      => $r->flow?->steps->count() ?? 1,
            'flow'       => $r->flow?->name,
            'waiting'    => $r->waitingHours(),
            'created_at' => $r->created_at?->toDateTimeString(),
            'can_act'    => $actionable,
            'can_cancel' => $r->status === 'pending' && (int) $r->requested_by === (int) Auth::id(),
        ];
    }
}
