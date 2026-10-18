<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\ApprovalAction;
use App\Modules\Operations\ProjectManagement\Models\ApprovalFlow;
use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\ApprovalStep;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Notifications\ApprovalDecidedNotification;
use App\Modules\Operations\ProjectManagement\Notifications\ApprovalRequestedNotification;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * The engine behind "submit for approval" (EPIC-090).
 *
 * Before this existed the button set a status and stopped. Somebody eventually opened the Delivery
 * page, saw a submitted timesheet, and approved it — or did not, and nobody found out. That is not
 * an approval process; it is a hope, and it is not one you can show an auditor.
 *
 * What this adds:
 *
 *   routing        the flow says who, by rule. No flow configured means a single implicit step of
 *                  "anyone who can update the project", which is what the module did before — so
 *                  a tenant that never opens the designer is no worse off than they were.
 *   a queue        every pending decision is a row somebody can find, in one inbox, with how long
 *                  it has been waiting.
 *   notification   the approver is told. This is the part whose absence made the old flow
 *                  theoretical.
 *   a record       who decided, when, and what they said, in a table nothing updates.
 *
 * Separation of duties is the default: the person who submitted cannot approve their own step
 * unless the step was deliberately configured to allow it. That switch exists because a two-person
 * business genuinely does need it, and a rule people cannot satisfy is a rule they work around.
 */
class ApprovalService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Route something for approval.
     *
     * Returns null when no routing applies — no active flow, or an amount below the threshold. The
     * caller treats null as "approved on submission", which is what a threshold is *for*: a company
     * that does not want R80 of screws crossing three desks says so here rather than in code.
     *
     * @param  array{label?:string, amount?:float|null, currency?:?string, note?:?string}  $context
     */
    public function submit(string $subjectType, object $subject, ?Project $project, array $context = []): ?ApprovalRequest
    {
        $amount = $context['amount'] ?? null;

        // A flow with no steps approves nothing and blocks everything, so it does not count as a
        // flow at all.
        $designed = $this->flowsFor($subjectType)
            ->filter(fn (ApprovalFlow $f) => $f->steps->isNotEmpty())
            ->filter(fn (ApprovalFlow $f) => $f->project_id === null || $f->project_id === $project?->id);

        if ($designed->isNotEmpty()) {
            // A flow written for one project beats the tenant-wide one: it is the more specific
            // statement, and somebody wrote it precisely because that job is different.
            $flow = $designed
                ->sortBy(fn (ApprovalFlow $f) => $f->project_id === null ? 1 : 0)
                ->first(fn (ApprovalFlow $f) => $f->routes($amount));

            // Designed, and this one falls under the bar somebody set. Skipping the queue is the
            // whole point of a threshold — a company that does not want R80 of screws crossing
            // three desks says so here rather than in code.
            if ($flow === null) {
                return null;
            }
        } else {
            $flow = null;

            if (! $this->hasDefaultRouting($subjectType)) {
                return null;
            }
        }

        return DB::transaction(function () use ($subjectType, $subject, $project, $context, $amount, $flow) {
            // Anything already waiting on this subject is superseded: re-submitting a corrected
            // timesheet must not leave two live requests for the same hours.
            ApprovalRequest::pending()
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subject->getKey())
                ->get()
                ->each(fn (ApprovalRequest $r) => $this->cancel($r, 'Superseded by a new submission.'));

            $request = ApprovalRequest::create([
                'flow_id'          => $flow?->id,
                'project_id'       => $project?->id,
                'subject_type'     => $subjectType,
                'subject_id'       => $subject->getKey(),
                'subject_uuid'     => $subject->getAttribute('uuid'),
                'subject_label'    => $context['label'] ?? null,
                'amount'           => $amount,
                'currency'         => $context['currency'] ?? $project?->currency,
                'status'           => 'pending',
                'current_position' => 1,
                'requested_by'     => Auth::id(),
                'note'             => $context['note'] ?? null,
            ]);

            $this->audit->recordAction($request, 'approval.requested', [
                'subject' => $subjectType,
                'flow'    => $flow?->name,
                'amount'  => $amount,
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
    public function approve(ApprovalRequest $request, ?string $comment = null): ApprovalRequest
    {
        $this->assertActionable($request);

        $user = Auth::user();
        $step = $request->currentStep();

        if (! $this->canAct($request, $step)) {
            throw new InvalidArgumentException('This approval is not yours to give.');
        }

        return DB::transaction(function () use ($request, $step, $comment, $user) {
            $this->recordAction($request, $step, 'approved', $comment);

            $approvals = ApprovalAction::where('request_id', $request->id)
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

            $this->audit->recordAction($request, 'approval.approved', ['by' => $user?->id]);
            $this->notifyRequester($request->fresh());

            return $request->fresh();
        });
    }

    public function reject(ApprovalRequest $request, string $reason): ApprovalRequest
    {
        $this->assertActionable($request);

        if (trim($reason) === '') {
            // A rejection without a reason is one the submitter cannot act on, so they resubmit
            // the same thing and the loop repeats.
            throw new InvalidArgumentException('A rejection has to say why.');
        }

        $step = $request->currentStep();

        if (! $this->canAct($request, $step)) {
            throw new InvalidArgumentException('This approval is not yours to give.');
        }

        return DB::transaction(function () use ($request, $step, $reason) {
            $this->recordAction($request, $step, 'rejected', $reason);

            // One rejection ends it, at whatever rung. Continuing up the ladder after somebody has
            // said no would be asking their senior to overrule them silently.
            $request->update(['status' => 'rejected', 'decided_at' => now(), 'decision_reason' => $reason]);

            $this->audit->recordAction($request, 'approval.rejected', ['reason' => $reason]);
            $this->notifyRequester($request->fresh());

            return $request->fresh();
        });
    }

    public function cancel(ApprovalRequest $request, string $reason = ''): ApprovalRequest
    {
        if ($request->status !== 'pending') {
            return $request;
        }

        $this->recordAction($request, $request->currentStep(), 'cancelled', $reason);

        $request->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_reason' => $reason]);

        return $request->fresh();
    }

    /**
     * Everything waiting on this person, newest last so the oldest thing is at the top.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function inboxFor(?object $user = null): Collection
    {
        $user ??= Auth::user();

        if (! $user) {
            return new Collection;
        }

        return ApprovalRequest::pending()
            ->with(['flow.steps', 'project:id,uuid,code,name'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (ApprovalRequest $r) => $this->canAct($r, $r->currentStep(), $user))
            ->values();
    }

    /** What the person submitted and is still waiting on — the other half of the inbox page. */
    public function submittedBy(?object $user = null): Collection
    {
        $user ??= Auth::user();

        return ApprovalRequest::query()
            ->where('requested_by', $user?->id)
            ->with(['flow', 'project:id,uuid,code,name'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    /**
     * May this person decide this request right now?
     *
     * Public because the inbox, the badge count and the controller all need the same answer, and
     * three copies of an authorisation rule is two too many.
     */
    public function canAct(ApprovalRequest $request, ?ApprovalStep $step, ?object $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user || $request->status !== 'pending') {
            return false;
        }

        // Already decided this rung: their signature is counted once (the unique index agrees).
        $alreadyActed = ApprovalAction::where('request_id', $request->id)
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

        if ($step === null) {
            // No designed flow: the module's previous behaviour, stated explicitly — whoever may
            // update the project may approve its submissions.
            return $request->project
                ? $user->can('update', $request->project)
                : $user->can('viewAny', Project::class);
        }

        return match ($step->approver_type) {
            'role'    => method_exists($user, 'hasRole') && $user->hasRole($step->approver_ref),
            'user'    => (int) $step->approver_ref === (int) $user->id,
            'pm_role' => $this->holdsProjectRole($request, $user, (string) $step->approver_ref),
            'manager' => $this->isManagerOf($request->requested_by, $user),
            default   => false,
        };
    }

    /** Flows a tenant has designed, for the setup page and for the "what happens next" hint. */
    public function flowsFor(string $subjectType): Collection
    {
        return ApprovalFlow::active()->where('subject_type', $subjectType)->with('steps')->get();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * With no flow designed, timesheets and expenses still queue for whoever runs the project.
     *
     * Without this, switching the engine on would silently auto-approve every timesheet on every
     * tenant that has not opened the designer — a change of behaviour nobody asked for, in the
     * direction of less control.
     */
    private function hasDefaultRouting(string $subjectType): bool
    {
        // A progress claim is deliberately absent. Timesheets and expenses fall back to "whoever
        // runs the project" because the alternative is auto-approving them; a payment certificate
        // is a different order of money, and it waits to be certified by a named person rather
        // than routed to a default.
        return in_array($subjectType, ['timesheet', 'expense'], true);
    }

    private function stepAt(ApprovalRequest $request, int $position): ?ApprovalStep
    {
        return $request->flow?->steps()->where('position', '>=', $position)->orderBy('position')->first();
    }

    private function assertActionable(ApprovalRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException('That request has already been decided.');
        }
    }

    private function recordAction(ApprovalRequest $request, ?ApprovalStep $step, string $decision, ?string $comment): void
    {
        $user = Auth::user();

        ApprovalAction::create([
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

    private function holdsProjectRole(ApprovalRequest $request, object $user, string $roleKey): bool
    {
        if (! $request->project_id) {
            return false;
        }

        return ProjectMember::where('project_id', $request->project_id)
            ->where('user_id', $user->id)
            ->where('role_key', $roleKey)
            ->active()
            ->exists();
    }

    /**
     * Line management, from HR when it is installed.
     *
     * With no HR module there is no manager to resolve, and this answers false rather than
     * guessing — a step configured as "the submitter's manager" on a tenant with no HR is a step
     * nobody can satisfy, which the designer warns about rather than this silently papering over.
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
     * Resolving "everyone with Finance Manager" to a list of users is deliberately done here and
     * not at submission time — a role's membership changes, and a notification list frozen at
     * submission would send tomorrow's approval to yesterday's team.
     */
    private function notifyApprovers(ApprovalRequest $request): void
    {
        $recipients = $this->approversFor($request);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ApprovalRequestedNotification($request));
    }

    private function notifyRequester(ApprovalRequest $request): void
    {
        if (! $request->requested_by) {
            return;
        }

        $user = $this->userClass()::find($request->requested_by);

        $user?->notify(new ApprovalDecidedNotification($request));
    }

    /**
     * The people to tell.
     *
     * Narrowed in the query first and only then filtered by `canAct`. The filter is what decides —
     * one rule for "may act" and "gets told", so nobody is ever notified about a decision they
     * cannot make — but running it over every user in the tenant to find the three who hold a role
     * is a table scan per submission, and timesheets are submitted in batches on a Friday.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function approversFor(ApprovalRequest $request): \Illuminate\Support\Collection
    {
        $step = $request->currentStep();
        $userClass = $this->userClass();

        $query = $userClass::query();

        match (true) {
            // No designed flow: whoever holds the grant to run projects, plus anybody on this one.
            // Both, because a contracts manager who approves the hours is often not a member of
            // the project, and a project manager who is may hold the grant only through it.
            $step === null => $query
                ->where(fn ($q) => $q
                    ->whereHas('permissions', fn ($p) => $p->where('name', RoleCatalog::UPDATE_PROJECTS))
                    ->orWhereHas('roles.permissions', fn ($p) => $p->where('name', RoleCatalog::UPDATE_PROJECTS))
                    ->orWhereIn('id', ProjectMember::where('project_id', $request->project_id)
                        ->active()->whereNotNull('user_id')->select('user_id'))),
            $step->approver_type === 'user' => $query->whereKey($step->approver_ref),
            $step->approver_type === 'role' => $query->whereHas(
                'roles',
                fn ($q) => $q->where('name', $step->approver_ref)
            ),
            $step->approver_type === 'pm_role' => $query->whereIn(
                'id',
                ProjectMember::where('project_id', $request->project_id)
                    ->where('role_key', $step->approver_ref)
                    ->active()->whereNotNull('user_id')->select('user_id')
            ),
            // The submitter's line manager: resolved by canAct, and the candidate set is small
            // enough only if we do not ask for it here. HR holds the answer.
            default => $query->whereIn(
                'id',
                DB::table('hr_people')->whereNotNull('user_id')->select('user_id')
            ),
        };

        return $query->limit(500)->get()
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
