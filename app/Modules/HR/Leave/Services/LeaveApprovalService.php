<?php

namespace App\Modules\HR\Leave\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Models\LeaveApprovalLevel;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Notifications\LeaveEscalatedNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveApprovalService
{
    public function __construct(
        private readonly ApproverDelegationService $delegationService,
    ) {}

    // ── Chain generation ──────────────────────────────────────────────────────

    /**
     * Build the approval chain for a newly submitted leave request.
     *
     * Handles:
     * - 4-level chain (Manager → Dept Head → HR → Payroll)
     * - Conditional approvals: levels skipped when not required by config
     * - Fallback managers: second_manager_id → HR person when primary is null
     * - Parallel approvals: all_managers_to_approve creates a parallel group
     * - Delegation: approver_id is resolved through active delegations
     * - SLA: sla_due_at computed from number_of_days_until_escalation config
     */
    public function generateApprovalChain(LeaveRequest $request): void
    {
        $config  = LeaveConfiguration::first();
        $slaDays = (int) ($config?->number_of_days_until_escalation ?? 3);
        $levels  = [];

        // ── Level 1: Manager (with optional parallel for all_managers_to_approve) ──
        if ($config?->require_managers_approval) {
            $managers = $this->resolveManagers($request->employee_id);

            if (count($managers) > 1 && $config?->all_managers_to_approve) {
                // Parallel group: all managers must approve
                foreach ($managers as $managerId) {
                    $levels[] = $this->levelRow(
                        $request, 1, 'Manager', $managerId, $slaDays,
                        parallelGroup: 1, isDelegated: $managerId !== $this->rawManager($request->employee_id),
                    );
                }
            } else {
                $primary = $managers[0] ?? null;
                $levels[] = $this->levelRow(
                    $request, 1, 'Manager', $primary, $slaDays,
                    isDelegated: $primary !== null && $primary !== $this->rawManager($request->employee_id),
                );
            }
        }

        // ── Level 2: Department Head ──────────────────────────────────────────
        if ($config?->require_department_head_approval) {
            $deptHead = $this->resolveDepartmentHead($request->employee_id, $config);
            $levels[] = $this->levelRow($request, 2, 'Department Head', $deptHead, $slaDays);
        }

        // ── Level 3: HR Manager ───────────────────────────────────────────────
        if ($config?->require_hr_approval) {
            $hrUserId = $this->resolveHrUser($config);
            $levels[] = $this->levelRow($request, 3, 'HR Manager', $hrUserId, $slaDays);
        }

        // ── Level 4: Payroll ──────────────────────────────────────────────────
        if ($config?->require_payroll_approval) {
            $levels[] = $this->levelRow($request, 4, 'Payroll Officer', null, $slaDays);
        }

        foreach ($levels as $level) {
            LeaveApprovalLevel::create($level);
        }

        // Point the request at the first pending level
        $first = collect($levels)->first();
        if ($first) {
            $request->update([
                'current_approver_id'    => $first['approver_id'],
                'current_approval_level' => $first['level'],
                'status'                 => LeaveRequestStatus::PendingManager->value,
            ]);
        }
    }

    /**
     * Build the approval chain for a leave request a supervisor lodged ON BEHALF of
     * an employee (e.g. a non-login cleaner). Uses the per-company on-behalf policy
     * instead of the employee's own manager chain:
     *   hr                 → HR approves (default, Option A)
     *   supervisor_manager → the supervisor's own manager approves (Option B)
     *   hr_then_final      → HR, then Payroll as the final approval (Option C)
     *   auto               → approved immediately (opt-in only)
     */
    public function generateOnBehalfChain(LeaveRequest $request, string $policy, int $supervisorUserId): void
    {
        $config  = LeaveConfiguration::first();
        $slaDays = (int) ($config?->number_of_days_until_escalation ?? 3);

        if ($policy === 'auto') {
            $request->update([
                'status'                 => LeaveRequestStatus::Approved->value,
                'approved_at'            => now(),
                'current_approver_id'    => null,
                'current_approval_level' => null,
            ]);
            return;
        }

        $levels      = [];
        $firstStatus = LeaveRequestStatus::PendingHr->value;

        if ($policy === 'supervisor_manager') {
            $supervisorHrId = DB::table('hr_people')->where('user_id', $supervisorUserId)->value('id');
            $managerUserId  = $supervisorHrId ? ($this->resolveManagers((int) $supervisorHrId)[0] ?? null) : null;
            $levels[]    = $this->levelRow($request, 1, "Supervisor's Manager", $managerUserId ?: $this->resolveHrUser($config), $slaDays);
            $firstStatus = LeaveRequestStatus::PendingManager->value;
        } elseif ($policy === 'hr_then_final') {
            $levels[] = $this->levelRow($request, 3, 'HR Manager', $this->resolveHrUser($config), $slaDays);
            $levels[] = $this->levelRow($request, 4, 'Payroll Officer', null, $slaDays); // Payroll = final approval
        } else { // 'hr' — default (Option A)
            $levels[] = $this->levelRow($request, 3, 'HR Manager', $this->resolveHrUser($config), $slaDays);
        }

        foreach ($levels as $level) {
            LeaveApprovalLevel::create($level);
        }

        $first = collect($levels)->first();
        if ($first) {
            $request->update([
                'current_approver_id'    => $first['approver_id'],
                'current_approval_level' => $first['level'],
                'status'                 => $firstStatus,
            ]);
        }
    }

    // ── Approve / Reject / Override ───────────────────────────────────────────

    public function approve(LeaveRequest $request, int $approverId, ?string $comment = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $approverId, $comment) {
            $currentLevel = LeaveApprovalLevel::where('leave_request_id', $request->id)
                ->where('level', $request->current_approval_level)
                ->where('status', 'pending')
                ->where(fn($q) => $q->where('approver_id', $approverId)->orWhereNull('approver_id'))
                ->firstOrFail();

            $currentLevel->update([
                'status'      => 'approved',
                'actioned_at' => now(),
                'comment'     => $comment,
                'approver_id' => $approverId,
            ]);

            // Parallel group: advance only when all members are approved
            if ($currentLevel->parallel_group !== null) {
                $pendingInGroup = LeaveApprovalLevel::where('leave_request_id', $request->id)
                    ->where('parallel_group', $currentLevel->parallel_group)
                    ->where('status', 'pending')
                    ->count();

                if ($pendingInGroup > 0) {
                    // Other parallel approvers still pending — don't advance yet
                    return $request->fresh();
                }
            }

            return $this->advanceToNextLevel($request);
        });
    }

    public function reject(LeaveRequest $request, int $approverId, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($request, $approverId, $reason) {
            LeaveApprovalLevel::where('leave_request_id', $request->id)
                ->where('level', $request->current_approval_level)
                ->update([
                    'status'      => 'rejected',
                    'actioned_at' => now(),
                    'comment'     => $reason,
                    'approver_id' => $approverId,
                ]);

            $request->update([
                'status'           => LeaveRequestStatus::Rejected->value,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            return $request->fresh();
        });
    }

    /**
     * HR policy override: forcefully approve a request that has BCEA/policy flags.
     * Skips all remaining pending levels and marks the override on the current level.
     */
    public function policyOverride(LeaveRequest $request, int $hrUserId, string $overrideReason): LeaveRequest
    {
        return DB::transaction(function () use ($request, $hrUserId, $overrideReason) {
            // Mark all remaining pending levels as skipped
            LeaveApprovalLevel::where('leave_request_id', $request->id)
                ->where('status', 'pending')
                ->update([
                    'status'          => 'skipped',
                    'actioned_at'     => now(),
                    'is_policy_override' => true,
                    'override_reason' => $overrideReason,
                    'approver_id'     => $hrUserId,
                ]);

            $request->update([
                'status'      => LeaveRequestStatus::Approved->value,
                'approved_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    // ── Escalations ───────────────────────────────────────────────────────────

    public function checkEscalations(int $escalationDays): void
    {
        $threshold = Carbon::now()->subDays($escalationDays);

        LeaveRequest::pending()
            ->where('submitted_at', '<=', $threshold)
            ->where('escalation_count', '<', 3)
            ->each(function (LeaveRequest $request) {
                $request->increment('escalation_count');
                $request->update([
                    'status'       => LeaveRequestStatus::Escalated->value,
                    'escalated_at' => now(),
                ]);

                // Mark the current pending approval level as escalated
                LeaveApprovalLevel::where('leave_request_id', $request->id)
                    ->where('level', $request->current_approval_level)
                    ->where('status', 'pending')
                    ->update([
                        'status'            => 'escalated',
                        'escalated_at'      => now(),
                        'escalation_reason' => 'Auto-escalated: approval SLA exceeded.',
                    ]);

                // Notify the current approver (controlled by notify_on_escalate)
                if ($request->current_approver_id && (LeaveConfiguration::first()?->notify_on_escalate ?? true)) {
                    $approver = User::find($request->current_approver_id);
                    $approver?->notify(new LeaveEscalatedNotification($request));
                }
            });
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function advanceToNextLevel(LeaveRequest $request): LeaveRequest
    {
        $next = LeaveApprovalLevel::where('leave_request_id', $request->id)
            ->where('level', '>', $request->current_approval_level)
            ->where('status', 'pending')
            ->where('is_required', true)
            ->orderBy('level')
            ->first();

        if ($next) {
            $nextStatus = match($next->level) {
                2       => LeaveRequestStatus::PendingDepartment,
                3       => LeaveRequestStatus::PendingHr,
                4       => LeaveRequestStatus::PendingPayroll,
                default => LeaveRequestStatus::PendingManager,
            };

            $request->update([
                'current_approval_level' => $next->level,
                'current_approver_id'    => $next->approver_id,
                'status'                 => $nextStatus->value,
            ]);
        } else {
            $request->update([
                'status'      => LeaveRequestStatus::Approved->value,
                'approved_at' => now(),
            ]);
        }

        return $request->fresh();
    }

    /**
     * Resolve the user IDs who should approve at manager level.
     * Falls back: primary manager → second_manager → HR person.
     * Each ID is resolved through active delegations.
     */
    private function resolveManagers(int $hrPersonId): array
    {
        $row = DB::table('hr_people')
            ->where('id', $hrPersonId)
            ->select('manager_id', 'second_manager_id')
            ->first();

        $candidates = array_filter([
            $this->managerUserId($row?->manager_id),
            $this->managerUserId($row?->second_manager_id),
        ]);

        // Resolve each through delegation
        return array_values(array_unique(array_map(
            fn($uid) => $this->delegationService->resolveApprover($uid),
            $candidates ?: [],
        )));
    }

    private function rawManager(int $hrPersonId): ?int
    {
        $managerId = DB::table('hr_people')->where('id', $hrPersonId)->value('manager_id');
        return $this->managerUserId($managerId);
    }

    private function managerUserId(?int $hrPersonId): ?int
    {
        if (! $hrPersonId) {
            return null;
        }
        return DB::table('hr_people')->where('id', $hrPersonId)->value('user_id');
    }

    private function resolveDepartmentHead(int $hrPersonId, ?object $config): ?int
    {
        // Dept head lookup via company_units membership — falls back to HR person
        $unitHead = DB::table('company_unit_members')
            ->join('hr_people', 'hr_people.id', '=', 'company_unit_members.hr_person_id')
            ->join('company_units', 'company_units.id', '=', 'company_unit_members.company_unit_id')
            ->where('company_units.type', 'department')
            ->where('company_unit_members.is_head', true)
            ->whereExists(function ($q) use ($hrPersonId) {
                $q->select(DB::raw(1))
                    ->from('company_unit_members as m2')
                    ->where('m2.hr_person_id', $hrPersonId)
                    ->whereColumn('m2.company_unit_id', 'company_units.id');
            })
            ->value('hr_people.user_id');

        $userId = $unitHead ?? $this->resolveHrUser($config);
        return $userId ? $this->delegationService->resolveApprover($userId) : null;
    }

    private function resolveHrUser(?object $config): ?int
    {
        // Prefer the leave config's explicit HR person…
        if ($config?->hr_person_id) {
            $userId = DB::table('hr_people')->where('id', $config->hr_person_id)->value('user_id');
            if ($userId) {
                return $this->delegationService->resolveApprover((int) $userId);
            }
        }

        // …otherwise fall back to the first HR Manager configured under settings/setup
        // (company_user_pivots type = 1, RLS-scoped to the tenant). This is what admins
        // expect to drive the "HR approves" routing, and it works per-tenant.
        $hrUserId = DB::table('company_user_pivots')->where('type', 1)->value('user_id');

        return $hrUserId ? $this->delegationService->resolveApprover((int) $hrUserId) : null;
    }

    private function levelRow(
        LeaveRequest $request,
        int          $level,
        string       $levelName,
        ?int         $approverId,
        int          $slaDays,
        ?int         $parallelGroup = null,
        bool         $isDelegated   = false,
    ): array {
        return [
            'leave_request_id' => $request->id,
            'level'            => $level,
            'level_name'       => $levelName,
            'approver_id'      => $approverId,
            'delegated_from_id' => null,
            'is_required'      => true,
            'status'           => 'pending',
            'parallel_group'   => $parallelGroup,
            'is_delegated'     => $isDelegated,
            'sla_due_at'       => $request->submitted_at
                ? Carbon::parse($request->submitted_at)->addWeekdays($slaDays)
                : null,
        ];
    }
}
