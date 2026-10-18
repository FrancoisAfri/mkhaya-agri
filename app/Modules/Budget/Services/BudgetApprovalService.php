<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetApprovalAction;
use App\Modules\Budget\Models\BudgetApprovalDelegation;
use App\Modules\Budget\Models\BudgetApprovalFlow;
use App\Modules\Budget\Models\BudgetApprovalRequest;
use App\Modules\Budget\Models\BudgetApprovalStep;
use App\Modules\Budget\Models\BudgetOwner;
use App\Modules\Budget\Notifications\BudgetApprovalDecidedNotification;
use App\Modules\Budget\Notifications\BudgetApprovalRequestedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * The engine behind every budget decision that moves money.
 *
 * What this replaces was one button. A budget page showed Approve and Reject to anybody holding
 * `budgets.approve`, and clicking Approve activated the allocation — alone, immediately, with no
 * record of who else should have looked at it and nothing anywhere that a second person could find.
 * Adjustments and overspend overrides worked the same way, which mattered more: those are the two
 * things that change the number after the budget is live.
 *
 * What this adds:
 *
 *   routing        the flow says who, by rule. No flow configured means a single implicit step of
 *                  "anyone who may approve budgets" — which is who could decide it before, so a
 *                  tenant that never opens the designer is no worse off than it was.
 *   a queue        every pending decision is a row somebody can find, in one inbox, with how long
 *                  it has been waiting.
 *   notification   the approver is told. Its absence is what made the old flow theoretical.
 *   a record       who decided, when, and what they said, in a table nothing updates.
 *
 * Separation of duties is the default: the person who requested an increase cannot approve it
 * unless a step was deliberately configured to allow it. That switch exists because a two-person
 * business genuinely needs it, and a rule people cannot satisfy is a rule they work around.
 */
class BudgetApprovalService
{
    /**
     * Route something for approval.
     *
     * Returns null when no routing applies — an amount below the threshold somebody set. The caller
     * treats null as "approved on submission", which is what a threshold is *for*: a company that
     * does not want a R500 correction crossing three desks says so here rather than in code.
     *
     * @param  array{label?:string, amount?:float|null, currency?:?string, note?:?string}  $context
     */
    public function submit(string $subjectType, object $subject, ?Budget $budget, array $context = []): ?BudgetApprovalRequest
    {
        $amount = $context['amount'] ?? null;

        // A flow with no steps approves nothing and blocks everything, so it does not count.
        $designed = $this->flowsFor($subjectType)
            ->filter(fn (BudgetApprovalFlow $f) => $f->steps->isNotEmpty())
            ->filter(fn (BudgetApprovalFlow $f) => $f->coversBudget($budget));

        $flow = null;

        if ($designed->isNotEmpty()) {
            // The more narrowly written flow wins: somebody wrote "capital above R1m" precisely
            // because it is not meant to be handled like everything else.
            $flow = $designed
                ->sortByDesc(fn (BudgetApprovalFlow $f) => $f->specificity())
                ->first(fn (BudgetApprovalFlow $f) => $f->routes($amount));

            // Designed, and this one falls under the bar somebody set.
            if ($flow === null) {
                return null;
            }
        }

        return DB::transaction(function () use ($subjectType, $subject, $budget, $context, $amount, $flow) {
            // Anything already waiting on this subject is superseded: re-submitting a corrected
            // adjustment must not leave two live requests for the same money.
            BudgetApprovalRequest::pending()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subject->getKey())
                ->get()
                ->each(fn (BudgetApprovalRequest $r) => $this->cancel($r, __('budget_management.approvals.superseded')));

            $request = BudgetApprovalRequest::create([
                'flow_id'          => $flow?->id,
                'budget_id'        => $budget?->id,
                'subject_type'     => $subjectType,
                'subject_id'       => $subject->getKey(),
                'subject_uuid'     => $subject->getAttribute('uuid'),
                'subject_label'    => $context['label'] ?? null,
                'amount'           => $amount,
                'currency'         => $context['currency'] ?? $budget?->currency,
                'status'           => 'pending',
                'current_position' => 1,
                'requested_by'     => Auth::id(),
                'note'             => $context['note'] ?? null,
            ]);

            $this->notifyApprovers($request);

            return $request;
        });
    }

    /**
     * Record an approval on the current step, and advance or finish.
     *
     * The step is only satisfied once `min_approvals` distinct people have approved it — a
     * two-signature rule that one person could satisfy by clicking twice is not a two-signature
     * rule, which is what the partial unique index on the actions table enforces underneath.
     */
    public function approve(BudgetApprovalRequest $request, ?string $comment = null): BudgetApprovalRequest
    {
        $this->assertActionable($request);

        $step = $request->currentStep();

        if (! $this->canAct($request, $step)) {
            throw new InvalidArgumentException(__('budget_management.approvals.not_yours'));
        }

        return DB::transaction(function () use ($request, $step, $comment) {
            $this->recordAction($request, $step, 'approved', $comment);

            $approvals = BudgetApprovalAction::where('request_id', $request->id)
                ->where('position', $request->current_position)
                ->where('decision', 'approved')
                ->count();

            if ($approvals < ($step?->min_approvals ?? 1)) {
                // Still short of the required signatures; the request stays where it is and the
                // other approvers keep their task.
                return $request->fresh();
            }

            $next = $this->stepAt($request, $request->current_position + 1);

            if ($next) {
                $request->update(['current_position' => $next->position]);
                $this->notifyApprovers($request->fresh());

                return $request->fresh();
            }

            $request->update(['status' => 'approved', 'decided_at' => now()]);

            $this->notifyRequester($request->fresh());

            return $request->fresh();
        });
    }

    public function reject(BudgetApprovalRequest $request, string $reason): BudgetApprovalRequest
    {
        $this->assertActionable($request);

        if (trim($reason) === '') {
            // A rejection without a reason is one the submitter cannot act on, so they resubmit the
            // same thing and the loop repeats.
            throw new InvalidArgumentException(__('budget_management.approvals.reason_required'));
        }

        $step = $request->currentStep();

        if (! $this->canAct($request, $step)) {
            throw new InvalidArgumentException(__('budget_management.approvals.not_yours'));
        }

        return DB::transaction(function () use ($request, $step, $reason) {
            $this->recordAction($request, $step, 'rejected', $reason);

            // One rejection ends it, at whatever rung. Continuing up the ladder after somebody has
            // said no would be asking their senior to overrule them silently.
            $request->update(['status' => 'rejected', 'decided_at' => now(), 'decision_reason' => $reason]);

            $this->notifyRequester($request->fresh());

            return $request->fresh();
        });
    }

    public function cancel(BudgetApprovalRequest $request, string $reason = ''): BudgetApprovalRequest
    {
        if ($request->status !== 'pending') {
            return $request;
        }

        $this->recordAction($request, $request->currentStep(), 'cancelled', $reason);

        $request->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_reason' => $reason]);

        return $request->fresh();
    }

    /**
     * Everything waiting on this person, oldest first so the thing that has waited longest is on top.
     *
     * @return \Illuminate\Support\Collection<int, BudgetApprovalRequest>
     */
    public function inboxFor(?object $user = null): \Illuminate\Support\Collection
    {
        $user ??= Auth::user();

        if (! $user) {
            return collect();
        }

        return BudgetApprovalRequest::pending()
            ->with(['flow.steps', 'budget:id,code,name,currency,expenditure_type'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (BudgetApprovalRequest $r) => $this->canAct($r, $r->currentStep(), $user))
            ->values();
    }

    /** What the person submitted and is still waiting on — the other half of the inbox page. */
    public function submittedBy(?object $user = null): Collection
    {
        $user ??= Auth::user();

        return BudgetApprovalRequest::query()
            ->where('requested_by', $user?->id)
            ->with(['flow', 'budget:id,code,name,currency'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    /** How many decisions are waiting on this person — for the badge, without building the list. */
    public function pendingCountFor(?object $user = null): int
    {
        return $this->inboxFor($user)->count();
    }

    /**
     * May this person decide this request right now?
     *
     * Public because the inbox, the badge count, the escalation command and the controller all need
     * the same answer, and four copies of an authorisation rule is three too many.
     */
    public function canAct(BudgetApprovalRequest $request, ?BudgetApprovalStep $step, ?object $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user || $request->status !== 'pending') {
            return false;
        }

        // Already decided this rung: their signature counts once (the unique index agrees).
        $alreadyActed = BudgetApprovalAction::where('request_id', $request->id)
            ->where('position', $request->current_position)
            ->where('user_id', $user->id)
            ->whereIn('decision', ['approved', 'rejected'])
            ->exists();

        if ($alreadyActed) {
            return false;
        }

        // Separation of duties, unless the step deliberately waives it.
        if ((int) $request->requested_by === (int) $user->id && ! ($step?->allow_self_approval ?? false)) {
            return false;
        }

        if ($this->satisfiesStep($request, $step, $user)) {
            return true;
        }

        // Nobody named on the step, but somebody may be holding it for whoever is. Checked last so
        // a delegation costs a query only when it is the thing that decides.
        return $this->holdsDelegationFor($request, $step, $user);
    }

    /**
     * Does this person satisfy the step in their own right?
     *
     * Separate from `canAct` so delegation can ask the same question about the person who is away —
     * one rule for "is this your approval", used twice.
     */
    private function satisfiesStep(BudgetApprovalRequest $request, ?BudgetApprovalStep $step, object $user): bool
    {
        if ($step === null) {
            // No designed flow: the module's previous behaviour, stated explicitly.
            return $user->can('approve', Budget::class);
        }

        return match ($step->approver_type) {
            'role'         => method_exists($user, 'hasRole') && $user->hasRole($step->approver_ref),
            'user'         => (int) $step->approver_ref === (int) $user->id,
            'budget_owner' => $this->ownsBudget($request, $user),
            'manager'      => $this->isManagerOf($request->requested_by, $user),
            default        => false,
        };
    }

    /**
     * Is this person standing in for somebody who could have decided it?
     *
     * A delegation adds an approver rather than moving the right, so the person who is away can
     * still act if they log in — which is what people actually do.
     */
    private function holdsDelegationFor(BudgetApprovalRequest $request, ?BudgetApprovalStep $step, object $user): bool
    {
        $delegations = BudgetApprovalDelegation::inForce()->where('to_user_id', $user->id)->get();

        if ($delegations->isEmpty()) {
            return false;
        }

        $userClass = $this->userClass();

        foreach ($delegations as $delegation) {
            if (! $delegation->covers($request)) {
                continue;
            }

            // The delegation is only worth anything if the person who granted it could have
            // decided this themselves. Otherwise anybody could be handed an approval right that
            // was never theirs to give.
            $principal = $userClass::find($delegation->from_user_id);

            if (! $principal) {
                continue;
            }

            // Separation of duties still applies to the principal: standing in for the person who
            // submitted it does not make it approvable.
            if ((int) $request->requested_by === (int) $principal->id && ! ($step?->allow_self_approval ?? false)) {
                continue;
            }

            if ($this->satisfiesStep($request, $step, $principal)) {
                return true;
            }
        }

        return false;
    }

    /** Flows a tenant has designed, for the setup page and the "what happens next" hint. */
    public function flowsFor(string $subjectType): Collection
    {
        return BudgetApprovalFlow::active()->where('subject_type', $subjectType)->with('steps')->get();
    }

    /**
     * Is anything about this subject still waiting on somebody?
     *
     * The pages use it to hide a Submit button that would only be refused by the unique index.
     */
    public function pendingFor(string $subjectType, int $subjectId): ?BudgetApprovalRequest
    {
        return BudgetApprovalRequest::pending()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function stepAt(BudgetApprovalRequest $request, int $position): ?BudgetApprovalStep
    {
        return $request->flow?->steps()->where('position', '>=', $position)->orderBy('position')->first();
    }

    private function assertActionable(BudgetApprovalRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException(__('budget_management.approvals.already_decided'));
        }
    }

    private function recordAction(BudgetApprovalRequest $request, ?BudgetApprovalStep $step, string $decision, ?string $comment): void
    {
        $user = Auth::user();

        BudgetApprovalAction::create([
            'request_id' => $request->id,
            'step_id'    => $step?->id,
            'position'   => $request->current_position,
            'user_id'    => $user?->id,
            'actor_name' => $this->nameOf($user),
            'decision'   => $decision,
            'comment'    => $comment ?: null,
            'acted_at'   => now(),
        ]);
    }

    private function ownsBudget(BudgetApprovalRequest $request, object $user): bool
    {
        if (! $request->budget_id) {
            return false;
        }

        return BudgetOwner::where('budget_id', $request->budget_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Line management, from HR when it is installed.
     *
     * With no HR module there is no manager to resolve, and this answers false rather than guessing
     * — a step configured as "the submitter's manager" on a tenant with no HR is a step nobody can
     * satisfy, which the designer warns about rather than this silently papering over.
     */
    private function isManagerOf(?int $submitterId, object $user): bool
    {
        if (! $submitterId) {
            return false;
        }

        $person = DB::table('hr_people')->where('user_id', $submitterId)->first(['manager_id', 'second_manager_id']);

        if (! $person) {
            return false;
        }

        // second_manager_id defaults to 0 rather than null, so `?:` and not `??`.
        $managerPersonId = $person->manager_id ?: $person->second_manager_id ?: null;

        if (! $managerPersonId) {
            return false;
        }

        return (int) DB::table('hr_people')->where('id', $managerPersonId)->value('user_id') === (int) $user->id;
    }

    /**
     * Tell the people whose decision it now is.
     *
     * Resolving "everyone with Finance Manager" to a list of users is deliberately done here and not
     * at submission time — a role's membership changes, and a notification list frozen at submission
     * would send tomorrow's approval to yesterday's team.
     */
    private function notifyApprovers(BudgetApprovalRequest $request): void
    {
        $recipients = $this->approversFor($request);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new BudgetApprovalRequestedNotification($request));
    }

    private function notifyRequester(BudgetApprovalRequest $request): void
    {
        if (! $request->requested_by) {
            return;
        }

        $user = $this->userClass()::find($request->requested_by);

        $user?->notify(new BudgetApprovalDecidedNotification($request));
    }

    /**
     * The people to tell.
     *
     * Narrowed in the query first and only then filtered by `canAct`. The filter is what decides —
     * one rule for "may act" and "gets told", so nobody is notified about a decision they cannot
     * make — but running it over every user in the tenant to find the three who hold a role is a
     * table scan per submission.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function approversFor(BudgetApprovalRequest $request): \Illuminate\Support\Collection
    {
        $step = $request->currentStep();
        $userClass = $this->userClass();

        $query = $userClass::query();

        match (true) {
            // No designed flow: whoever holds the grant to approve budgets, directly or by role.
            $step === null => $query->where(fn ($q) => $q
                ->whereHas('permissions', fn ($p) => $p->where('name', 'budgets.approve'))
                ->orWhereHas('roles.permissions', fn ($p) => $p->where('name', 'budgets.approve'))),
            $step->approver_type === 'user' => $query->whereKey($step->approver_ref),
            $step->approver_type === 'role' => $query->whereHas(
                'roles',
                fn ($q) => $q->where('name', $step->approver_ref)
            ),
            $step->approver_type === 'budget_owner' => $query->whereIn(
                'id',
                BudgetOwner::where('budget_id', $request->budget_id)->select('user_id')
            ),
            // The submitter's line manager: resolved by canAct, and the candidate set is small
            // enough only if we do not ask for it here. HR holds the answer.
            default => $query->whereIn(
                'id',
                DB::table('hr_people')->whereNotNull('user_id')->select('user_id')
            ),
        };

        $candidates = $query->limit(500)->get();

        // Whoever is standing in has to be told too. They are not in any of the candidate sets
        // above — that is the whole point of a delegation — so an inbox they can act on would
        // otherwise be one nobody mentions to them.
        $delegates = $userClass::query()
            ->whereIn('id', BudgetApprovalDelegation::inForce()->select('to_user_id'))
            ->get();

        return $candidates->concat($delegates)
            ->unique('id')
            ->filter(fn ($user) => $this->canAct($request, $step, $user))
            ->values();
    }

    /** @return class-string */
    private function userClass(): string
    {
        return config('auth.providers.users.model');
    }

    private function nameOf(?object $user): ?string
    {
        if (! $user) {
            return null;
        }

        // There is no users.name column on this platform; the person's name lives in HR.
        $person = $user->person ?? null;

        return $person
            ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) ?: $user->email
            : $user->email;
    }
}
