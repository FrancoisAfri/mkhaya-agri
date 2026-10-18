<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Support\Rules\ConditionMatcher;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Selects and runs approval chains (§5.2), for deviations, procurement plans and tender
 * recommendations alike.
 *
 * Selection is data-driven: a chain declares the conditions under which it applies — value band,
 * department, category, deviation type, risk level — and the most specific active match wins, with
 * `specificity` and then id breaking ties so the same request always routes the same way. An
 * approval route that varies between two identical requests cannot be defended at audit, so
 * determinism here is a correctness requirement rather than a nicety.
 *
 * Two protections are enforced in code rather than left to configuration, because no tenant would
 * knowingly configure them away and every tenant would eventually be embarrassed by them:
 *
 *  - **Separation of duties.** The person who raised a request cannot approve any step of it, and
 *    nobody can approve the same request twice at different levels. A two-level chain signed twice
 *    by one person is one level of approval wearing a disguise.
 *
 *  - **A chain must have a step.** If selection yields a chain whose steps have all been
 *    condition-skipped, the request is refused rather than auto-approved. Silent auto-approval of
 *    the highest-risk transaction in procurement is the worst available failure mode.
 */
class ApprovalRouter
{
    public function __construct(
        private readonly ConditionMatcher $conditions,
        private readonly AuditLedger $audit,
    ) {}

    /** The chain that would be used, without starting anything — for showing the route up front. */
    public function selectChain(string $appliesTo, RuleSubject $subject): ?object
    {
        $chains = DB::table('procgov_approval_chains')
            ->where('company_id', $subject->companyId)
            ->where('applies_to', $appliesTo)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get();

        $matching = $chains
            ->filter(fn ($chain) => $this->conditions->matches($this->decode($chain->conditions), $subject))
            ->sortByDesc(fn ($chain) => [
                (int) $chain->specificity ?: $this->conditions->specificity($this->decode($chain->conditions)),
                (int) $chain->id,
            ])
            ->values();

        // An explicit default is the fallback, not "the first chain we happened to find".
        return $matching->first() ?? $chains->firstWhere('is_default', true);
    }

    /**
     * Instantiate a chain against a subject.
     *
     * @param  array{label?:string, record_id?:int, due_days?:int}  $options
     *
     * @throws \RuntimeException when no chain applies, or every step was skipped
     */
    public function start(string $appliesTo, RuleSubject $subject, array $options = []): object
    {
        $chain = $this->selectChain($appliesTo, $subject);

        if (! $chain) {
            throw new DomainRuleException(
                'No approval chain is configured for this item. An administrator must define one in '
                .'Procurement Governance settings before it can be submitted for approval.'
            );
        }

        $steps = DB::table('procgov_approval_chain_steps')
            ->where('chain_id', $chain->id)
            ->orderBy('step_no')
            ->get()
            ->filter(fn ($step) => $this->conditions->matches($this->decode($step->conditions), $subject))
            ->values();

        if ($steps->isEmpty()) {
            throw new DomainRuleException(
                "The approval chain '{$chain->name}' has no steps that apply to this item, so it "
                .'cannot be approved. An administrator must review the chain configuration.'
            );
        }

        return DB::transaction(function () use ($chain, $steps, $subject, $options) {
            $now = Carbon::now();
            $dueDays = $options['due_days'] ?? null;

            $requestId = DB::table('procgov_approval_requests')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $subject->companyId,
                'chain_id' => $chain->id,
                'chain_code' => $chain->code,
                'subject_type' => $subject->type,
                'subject_id' => $subject->id,
                'subject_reference' => $subject->reference,
                'subject_label' => Str::limit($options['label'] ?? ($subject->reference ?? $subject->type), 185, ''),
                'record_id' => $options['record_id'] ?? $subject->recordId,
                'amount' => $subject->amount,
                'status' => 'PENDING',
                'current_step' => 1,
                'total_steps' => $steps->count(),
                'requested_by' => auth()->id(),
                'requested_at' => $now,
                'due_at' => $dueDays ? $now->copy()->addDays((int) $dueDays) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Steps are renumbered 1..n after condition filtering. Keeping the chain's original
            // numbering would leave gaps, and "step 2 of 4" with no step 3 reads as a bug to the
            // person waiting for it.
            foreach ($steps as $index => $step) {
                $stepNo = $index + 1;
                $isFirst = $stepNo === 1;

                DB::table('procgov_approval_steps')->insert([
                    'company_id' => $subject->companyId,
                    'request_id' => $requestId,
                    'step_no' => $stepNo,
                    'name' => $step->name,
                    'approver_type' => $step->approver_type,
                    'approver_id' => $step->approver_id,
                    'approver_ref' => $step->approver_ref,
                    'approver_label' => $this->approverLabel($step, $subject),
                    'quorum' => max(1, (int) $step->quorum),
                    'status' => $isFirst ? 'ACTIVE' : 'PENDING',
                    'activated_at' => $isFirst ? $now : null,
                    'due_at' => $isFirst && $step->sla_hours
                        ? $now->copy()->addHours((int) $step->sla_hours)
                        : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->audit->record($subject->companyId, [
                'action' => 'approval_requested',
                'capability' => 'governance',
                'entity_type' => $subject->type,
                'entity_id' => $subject->id,
                'entity_reference' => $subject->reference,
                'record_id' => $options['record_id'] ?? $subject->recordId,
                'summary' => sprintf(
                    'Approval requested for %s via chain "%s" (%d level%s)',
                    $subject->reference ?: $subject->type.' #'.$subject->id,
                    $chain->name,
                    $steps->count(),
                    $steps->count() === 1 ? '' : 's',
                ),
            ]);

            return $this->find($requestId);
        });
    }

    /**
     * Record a decision on the active step.
     *
     * @param  string  $decision  APPROVED | REJECTED
     * @return object the refreshed request
     *
     * @throws \RuntimeException when the user may not act on it
     */
    public function decide(int $requestId, int $userId, string $decision, ?string $comment = null): object
    {
        return DB::transaction(function () use ($requestId, $userId, $decision, $comment) {
            $request = DB::table('procgov_approval_requests')->lockForUpdate()->find($requestId);

            if (! $request) {
                throw new DomainRuleException('Approval request not found.');
            }

            if ($request->status !== 'PENDING') {
                throw new DomainRuleException("This approval is already {$request->status} and cannot be changed.");
            }

            $step = DB::table('procgov_approval_steps')
                ->where('request_id', $requestId)
                ->where('step_no', $request->current_step)
                ->lockForUpdate()
                ->first();

            if (! $step || $step->status !== 'ACTIVE') {
                throw new DomainRuleException('There is no active approval step to decide on.');
            }

            $this->assertMayDecide($request, $step, $userId);

            $now = Carbon::now();

            if ($decision === 'REJECTED') {
                DB::table('procgov_approval_steps')->where('id', $step->id)->update([
                    'status' => 'REJECTED',
                    'decision' => 'REJECTED',
                    'decided_by' => $userId,
                    'decided_at' => $now,
                    'comment' => $comment,
                    'updated_at' => $now,
                ]);

                DB::table('procgov_approval_requests')->where('id', $requestId)->update([
                    'status' => 'REJECTED',
                    'completed_at' => $now,
                    'outcome_comment' => $comment,
                    'updated_at' => $now,
                ]);

                $this->recordDecision($request, $step, $userId, 'REJECTED', $comment);

                return $this->find($requestId);
            }

            $received = (int) $step->approvals_received + 1;

            // A quorum step needs several signatures before it advances — that is how a committee
            // decision differs from an individual's.
            if ($received < (int) $step->quorum) {
                DB::table('procgov_approval_steps')->where('id', $step->id)->update([
                    'approvals_received' => $received,
                    'updated_at' => $now,
                ]);

                $this->recordDecision($request, $step, $userId, 'APPROVED', $comment, true);

                return $this->find($requestId);
            }

            DB::table('procgov_approval_steps')->where('id', $step->id)->update([
                'status' => 'APPROVED',
                'decision' => 'APPROVED',
                'approvals_received' => $received,
                'decided_by' => $userId,
                'decided_at' => $now,
                'comment' => $comment,
                'updated_at' => $now,
            ]);

            $next = DB::table('procgov_approval_steps')
                ->where('request_id', $requestId)
                ->where('step_no', '>', $step->step_no)
                ->orderBy('step_no')
                ->first();

            if ($next) {
                $slaHours = DB::table('procgov_approval_chain_steps')
                    ->where('chain_id', $request->chain_id)
                    ->where('name', $next->name)
                    ->value('sla_hours');

                DB::table('procgov_approval_steps')->where('id', $next->id)->update([
                    'status' => 'ACTIVE',
                    'activated_at' => $now,
                    'due_at' => $slaHours ? $now->copy()->addHours((int) $slaHours) : null,
                    'updated_at' => $now,
                ]);

                DB::table('procgov_approval_requests')->where('id', $requestId)->update([
                    'current_step' => $next->step_no,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('procgov_approval_requests')->where('id', $requestId)->update([
                    'status' => 'APPROVED',
                    'completed_at' => $now,
                    'outcome_comment' => $comment,
                    'updated_at' => $now,
                ]);
            }

            $this->recordDecision($request, $step, $userId, 'APPROVED', $comment);

            return $this->find($requestId);
        });
    }

    /** Withdraw a pending approval — e.g. the subject was edited and must be re-submitted. */
    public function withdraw(int $requestId, ?string $reason = null): void
    {
        $request = DB::table('procgov_approval_requests')->find($requestId);

        if (! $request || $request->status !== 'PENDING') {
            return;
        }

        $now = Carbon::now();

        DB::table('procgov_approval_requests')->where('id', $requestId)->update([
            'status' => 'WITHDRAWN',
            'completed_at' => $now,
            'outcome_comment' => $reason,
            'updated_at' => $now,
        ]);

        DB::table('procgov_approval_steps')
            ->where('request_id', $requestId)
            ->whereIn('status', ['PENDING', 'ACTIVE'])
            ->update(['status' => 'SKIPPED', 'updated_at' => $now]);

        $this->audit->record((int) $request->company_id, [
            'action' => 'approval_withdrawn',
            'capability' => 'governance',
            'entity_type' => $request->subject_type,
            'entity_id' => (int) $request->subject_id,
            'entity_reference' => $request->subject_reference,
            'record_id' => $request->record_id,
            'comment' => $reason,
        ]);
    }

    /** Everyone who may act on the currently active step — used for notification and for the inbox. */
    public function currentApprovers(int $requestId): Collection
    {
        $request = DB::table('procgov_approval_requests')->find($requestId);

        if (! $request || $request->status !== 'PENDING') {
            return collect();
        }

        $step = DB::table('procgov_approval_steps')
            ->where('request_id', $requestId)
            ->where('step_no', $request->current_step)
            ->first();

        return $step ? $this->resolveApprovers($request, $step) : collect();
    }

    /** May this user decide the active step right now? */
    public function mayDecide(int $requestId, int $userId): bool
    {
        try {
            $request = DB::table('procgov_approval_requests')->find($requestId);
            $step = $request
                ? DB::table('procgov_approval_steps')
                    ->where('request_id', $requestId)
                    ->where('step_no', $request->current_step)
                    ->first()
                : null;

            if (! $request || ! $step) {
                return false;
            }

            $this->assertMayDecide($request, $step, $userId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function assertMayDecide(object $request, object $step, int $userId): void
    {
        if ((int) $request->requested_by === $userId) {
            throw new DomainRuleException(
                'You raised this request, so you cannot also approve it. Separation of duties '
                .'requires a different approver.'
            );
        }

        $alreadySigned = DB::table('procgov_approval_steps')
            ->where('request_id', $request->id)
            ->where('id', '!=', $step->id)
            ->where('decided_by', $userId)
            ->where('decision', 'APPROVED')
            ->exists();

        if ($alreadySigned) {
            throw new DomainRuleException(
                'You have already approved this request at another level. One person cannot provide '
                .'two levels of approval.'
            );
        }

        if (! $this->resolveApprovers($request, $step)->contains($userId)) {
            throw new DomainRuleException('This approval step is not assigned to you.');
        }
    }

    /** @return Collection<int,int> user ids */
    private function resolveApprovers(object $request, object $step): Collection
    {
        $companyId = (int) $request->company_id;

        return match ($step->approver_type) {
            'user' => collect(array_filter([$step->approver_id ? (int) $step->approver_id : null])),

            'role' => $this->usersWithRole($companyId, (string) $step->approver_ref),

            'permission' => $this->usersWithPermission($companyId, (string) $step->approver_ref),

            'unit_manager' => $this->unitManager($companyId, $request),

            'budget_owner' => $this->budgetOwners($request),

            'committee_chair' => $this->committeeChair($companyId, $request),

            default => collect(),
        };
    }

    private function usersWithRole(int $companyId, string $roleName): Collection
    {
        if ($roleName === '') {
            return collect();
        }

        return DB::table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->join('users as u', 'u.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', User::class)
            ->whereRaw('lower(r.name) = ?', [mb_strtolower($roleName)])
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->pluck('u.id')
            ->map(fn ($id) => (int) $id);
    }

    private function usersWithPermission(int $companyId, string $permission): Collection
    {
        if ($permission === '') {
            return collect();
        }

        $direct = DB::table('model_has_permissions as mhp')
            ->join('permissions as p', 'p.id', '=', 'mhp.permission_id')
            ->join('users as u', 'u.id', '=', 'mhp.model_id')
            ->where('mhp.model_type', User::class)
            ->where('p.name', $permission)
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->pluck('u.id');

        $viaRole = DB::table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->join('model_has_roles as mhr', 'mhr.role_id', '=', 'rhp.role_id')
            ->join('users as u', 'u.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', User::class)
            ->where('p.name', $permission)
            ->where('u.company_id', $companyId)
            ->whereNull('u.deleted_at')
            ->pluck('u.id');

        return $direct->merge($viaRole)->unique()->map(fn ($id) => (int) $id)->values();
    }

    /** The head of the department the subject belongs to — `company_units.user_id`. */
    private function unitManager(int $companyId, object $request): Collection
    {
        $unitId = $this->subjectUnitId($request);

        if (! $unitId) {
            return collect();
        }

        $managerId = DB::table('company_units')
            ->where('id', $unitId)
            ->where('company_id', $companyId)
            ->value('user_id');

        return collect(array_filter([$managerId ? (int) $managerId : null]));
    }

    private function budgetOwners(object $request): Collection
    {
        $budgetId = $this->subjectBudgetId($request);

        if (! $budgetId || ! Schema::hasTable('budget_owners')) {
            return collect();
        }

        return DB::table('budget_owners')
            ->where('budget_id', $budgetId)
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    private function committeeChair(int $companyId, object $request): Collection
    {
        if ($request->subject_type !== 'procgov_tender_reviews') {
            return collect();
        }

        $chairId = DB::table('procgov_tender_reviews as r')
            ->join('procgov_committees as c', 'c.id', '=', 'r.committee_id')
            ->where('r.id', $request->subject_id)
            ->where('r.company_id', $companyId)
            ->value('c.chairperson_id');

        return collect(array_filter([$chairId ? (int) $chairId : null]));
    }

    private function subjectUnitId(object $request): ?int
    {
        $column = match ($request->subject_type) {
            'procgov_deviations', 'procgov_plans', 'procgov_records', 'procgov_tender_reviews' => 'company_unit_id',
            default => null,
        };

        if (! $column) {
            return null;
        }

        $value = DB::table($request->subject_type)->where('id', $request->subject_id)->value($column);

        return $value ? (int) $value : null;
    }

    private function subjectBudgetId(object $request): ?int
    {
        $tablesWithBudget = ['procgov_plans', 'procgov_plan_items', 'procgov_tender_reviews'];

        if (! in_array($request->subject_type, $tablesWithBudget, true)) {
            return null;
        }

        $value = DB::table($request->subject_type)->where('id', $request->subject_id)->value('budget_id');

        return $value ? (int) $value : null;
    }

    private function approverLabel(object $step, RuleSubject $subject): ?string
    {
        return match ($step->approver_type) {
            'user' => 'Named approver',
            'role' => 'Role: '.$step->approver_ref,
            'permission' => 'Anyone with: '.$step->approver_ref,
            'unit_manager' => 'Department head',
            'budget_owner' => 'Budget owner',
            'committee_chair' => 'Committee chairperson',
            default => null,
        };
    }

    private function recordDecision(
        object $request,
        object $step,
        int $userId,
        string $decision,
        ?string $comment,
        bool $partial = false,
    ): void {
        $this->audit->record((int) $request->company_id, [
            'action' => $partial ? 'approval_signed' : 'approval_'.mb_strtolower($decision),
            'capability' => 'governance',
            'entity_type' => $request->subject_type,
            'entity_id' => (int) $request->subject_id,
            'entity_reference' => $request->subject_reference,
            'record_id' => $request->record_id,
            'decision' => $decision,
            'comment' => $comment,
            'summary' => sprintf(
                '%s "%s" on %s',
                $partial ? 'Signed approval step' : ucfirst(mb_strtolower($decision)).' at step',
                $step->name,
                $request->subject_reference ?: $request->subject_type.' #'.$request->subject_id,
            ),
        ]);
    }

    private function find(int $requestId): object
    {
        return DB::table('procgov_approval_requests')->find($requestId);
    }

    private function decode(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        if (is_array($json)) {
            return $json;
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
