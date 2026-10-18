<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use App\Modules\HR\Leave\Contracts\LeaveRequestRepositoryInterface;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Enums\LeaveRequestStatus;
use App\Modules\HR\Leave\Jobs\ProcessLeaveAiExplanationJob;
use App\Modules\HR\Leave\Jobs\SyncLeaveToTimeAttendanceJob;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Models\ParentalLeavePool;
use App\Modules\HR\Leave\Notifications\LeaveApprovedNotification;
use App\Modules\HR\Leave\Notifications\LeaveMedicalCertificateRequiredNotification;
use App\Modules\HR\Leave\Notifications\LeaveRejectedNotification;
use App\Modules\HR\Leave\Notifications\LeaveSubmittedEmployeeNotification;
use App\Modules\HR\Leave\Notifications\LeaveSubmittedNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    public function __construct(
        private readonly LeaveRequestRepositoryInterface $repo,
        private readonly BceaLeaveValidator              $bceaValidator,
        private readonly PolicyRuleEvaluator             $policyEvaluator,
        private readonly LeavePolicyGuard                $policyGuard,
        private readonly WorkingDaysCalculator           $daysCalculator,
        private readonly LeaveBalanceService             $balanceService,
        private readonly LeaveApprovalService            $approvalService,
        private readonly LeaveAnomalyDetector            $anomalyDetector,
        private readonly SickLeaveCertificateRule        $sickCertRule,
    ) {}

    /**
     * Full leave request submission pipeline:
     * BCEA → Policy → WorkingDays → Balance → Persist → ApprovalChain → AI → Notifications
     */
    public function submit(SubmitLeaveRequestDTO $dto): LeaveRequest
    {
        $employee  = HRPerson::findOrFail($dto->employeeId);
        $leaveType = LeaveType::findOrFail($dto->leaveTypeId);
        $cycle     = LeaveCycle::active();

        // ── 1. BCEA statutory validation ────────────────────────────────────
        $bceaResult = $this->bceaValidator->validate($employee, $leaveType, $dto);
        if (! $bceaResult['valid']) {
            throw ValidationException::withMessages(['bcea' => $bceaResult['errors']]);
        }

        // ── 2. Leave-specific policy rules ──────────────────────────────────
        $policyResult = $this->policyEvaluator->evaluate($employee, $leaveType, $dto);
        if (! $policyResult['valid']) {
            throw ValidationException::withMessages(['policy' => $policyResult['errors']]);
        }

        // ── 3. Working days / hours calculation ─────────────────────────────
        $workingDays  = $this->daysCalculator->calculate($dto->startDate, $dto->endDate);
        $workingHours = $dto->requestMode === 'hourly' && $dto->startTime && $dto->endTime
            ? $this->daysCalculator->calculateHours($dto->startDate, $dto->endDate, $dto->startTime, $dto->endTime)
            : $workingDays * 8;

        // ── 4. Sick leave certificate check ─────────────────────────────────
        if ($leaveType->statutory_type === 'sick') {
            $certCheck = $this->sickCertRule->isCertificateRequired($employee, $dto->startDate, $dto->endDate);
            // Store requirement in bceaResult so it persists on the request and surfaces in the UI.
            // We do not block submission — the employee must upload the certificate after approval.
            $bceaResult['certificate_required'] = $certCheck['required'];
            $bceaResult['certificate_reasons']  = $certCheck['reasons'];
        }

        // ── 4a. Parental leave pool validation ──────────────────────────────
        if (in_array($leaveType->statutory_type, ['maternity', 'paternity', 'parental'])) {
            $this->validateParentalLeavePool($employee, $leaveType, $workingDays, $dto);
        }

        // ── 5. Global policy engine check ───────────────────────────────────
        $currentBalance = $this->balanceService->getBalance($employee->id, $leaveType->id);
        $this->policyGuard->forSubmission(
            employee:            $employee,
            leaveType:           $leaveType,
            dto:                 $dto,
            workingDays:         $workingDays,
            workingHours:        $workingHours,
            currentBalanceHours: $currentBalance,
            actorUserId:         Auth::id() ?? $employee->user_id,
        );

        // A company that routes leave through the workflow engine gets the engine's approval path
        // instead of the leave approval levels; every check above still applies.
        $workflows = app(\App\Modules\Workflow\Services\Workflows::class);
        $viaWorkflow = $workflows->handles('hr.leave', $employee->company_id ?? Auth::user()?->company_id);

        // ── 6. DB transaction: persist request + reserve balance ─────────────
        $request = DB::transaction(function () use (
            $dto, $cycle, $workingDays, $workingHours, $bceaResult, $viaWorkflow
        ) {
            $request = $this->repo->create(array_merge($dto->toArray(), [
                'leave_cycle_id'         => $cycle?->id,
                'working_days'           => $workingDays,
                'working_hours'          => $workingHours,
                'hours_reserved'         => $workingHours,
                'status'                 => LeaveRequestStatus::Pending->value,
                'submitted_at'           => now(),
                'bcea_validated_at'      => now(),
                'bcea_validation_result' => $bceaResult,
            ]));

            // Balance reservation — overtime earns leave (credited on approval), so it
            // must NOT reserve/deduct anything at submission.
            if (! $request->is_overtime) {
                $this->balanceService->reserve($request);
            }

            // Generate approval chain. On-behalf requests (a supervisor lodging leave
            // for an employee) route via the per-company on-behalf policy instead of
            // the employee's own manager chain.
            if ($viaWorkflow) {
                // The workflow decides who approves; the request waits as pending.
            } elseif ($dto->onBehalf) {
                $policy = (string) \App\Models\AppSetting::get('leave_onbehalf_approval_policy', 'hr');
                $this->approvalService->generateOnBehalfChain($request, $policy, (int) ($dto->submittedBy ?? 0));
            } else {
                $this->approvalService->generateApprovalChain($request);
            }

            return $request;
        });

        // Refresh after the transaction so current_approver_id and status reflect DB state
        $request->refresh();
        if ($viaWorkflow) {
            $workflows->emit('hr.leave.submitted', 'hr.leave', (string) $request->id, $employee->user_id ? (int) $employee->user_id : Auth::id(), [], 'leave:' . $request->id);
        }

        // ── 7. Anomaly detection (async / non-blocking) ──────────────────────
        dispatch(fn() => $this->anomalyDetector->analyseRequest($request))->afterResponse();

        // ── 8. AI explanation (queued) ───────────────────────────────────────
        ProcessLeaveAiExplanationJob::dispatch($request)->afterResponse();

        // ── 9. Notifications ─────────────────────────────────────────────────

        $notifConfig  = LeaveConfiguration::first();
        $employeeUser = $this->resolveEmployeeUser($dto->employeeId);

        // 9a. Confirmation to the employee (always sent)
        $this->notifySilently($employeeUser, new LeaveSubmittedEmployeeNotification($request));

        // 9b. Notify approver (controlled by notify_hr_with_application)
        // current_approver_id is a users.id FK set by generateApprovalChain()
        if ($request->current_approver_id && ($notifConfig?->notify_hr_with_application ?? true)) {
            $approverUser = \App\Core\User\Models\User::find($request->current_approver_id);
            $this->notifySilently($approverUser, new LeaveSubmittedNotification($request));
        }

        // 9c. Medical certificate required (sick leave)
        $certRequired = $bceaResult['certificate_required'] ?? false;
        $certReasons  = $bceaResult['certificate_reasons']  ?? [];
        if ($certRequired) {
            $this->notifySilently($employeeUser, new LeaveMedicalCertificateRequiredNotification($request, $certReasons));
        }

        return $request->fresh(['employee', 'leaveType', 'approvalLevels']);
    }

    public function approve(LeaveRequest $request, ?string $comment = null): LeaveRequest
    {
        $approverId = Auth::id();

        $this->policyGuard->forApproval($request, $approverId);

        $request = $this->approvalService->approve($request, $approverId, $comment);

        if ($request->status === LeaveRequestStatus::Approved) {
            // Overtime CREDITS annual leave; ordinary leave CONSUMES the reserved balance.
            if ($request->is_overtime && (LeaveConfiguration::first()?->allow_annual_leave_credit ?? true)) {
                $this->balanceService->overtimeCredit($request);
            } elseif (! $request->is_overtime) {
                $this->balanceService->consume($request);
            }
            SyncLeaveToTimeAttendanceJob::dispatch($request);

            if (LeaveConfiguration::first()?->notify_employee_about_applications_submitted ?? true) {
                $employeeUser = $this->resolveEmployeeUser($request->employee_id);
                $this->notifySilently($employeeUser, new LeaveApprovedNotification($request));
            }
        }

        return $request;
    }

    /**
     * The workflow engine's decision on a request it routed (Workflow & Automation). Idempotent.
     * Approval consumes the reservation (or credits overtime), syncs Time & Attendance and tells the
     * employee, exactly as the last approval level does; rejection releases the reservation.
     */
    public function finaliseByWorkflow(LeaveRequest $request, string $outcome, ?int $actorId, ?string $comment): LeaveRequest
    {
        $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
        $target = ['approved' => LeaveRequestStatus::Approved, 'rejected' => LeaveRequestStatus::Rejected, 'cancelled' => LeaveRequestStatus::Cancelled][$outcome] ?? null;
        if (! $target) {
            throw ValidationException::withMessages(['outcome' => ['Unknown workflow outcome.']]);
        }
        if ($request->status === $target) {
            return $request;
        }
        if ($target === LeaveRequestStatus::Cancelled) {
            return $this->cancel($request, $comment ?: 'Cancelled through the workflow.', adminOverride: true);
        }
        if (! $request->status->isPending()) {
            throw ValidationException::withMessages(['status' => ["Leave request {$request->request_number} is {$request->status->value}; the workflow outcome cannot be applied."]]);
        }

        DB::transaction(function () use ($request, $target, $comment) {
            if ($target === LeaveRequestStatus::Approved) {
                $request->update(['status' => LeaveRequestStatus::Approved->value, 'approved_at' => now(), 'current_approver_id' => null]);
                if ($request->is_overtime && (LeaveConfiguration::first()?->allow_annual_leave_credit ?? true)) {
                    $this->balanceService->overtimeCredit($request);
                } elseif (! $request->is_overtime) {
                    $this->balanceService->consume($request);
                }
            } else {
                $request->update(['status' => LeaveRequestStatus::Rejected->value, 'rejected_at' => now(), 'rejection_reason' => $comment, 'current_approver_id' => null]);
                if (! $request->is_overtime) {
                    $this->balanceService->release($request);
                }
            }
        });

        $employeeUser = $this->resolveEmployeeUser($request->employee_id);
        if ($target === LeaveRequestStatus::Approved) {
            SyncLeaveToTimeAttendanceJob::dispatch($request);
            if (LeaveConfiguration::first()?->notify_employee_about_applications_submitted ?? true) {
                $this->notifySilently($employeeUser, new LeaveApprovedNotification($request));
            }
        } elseif (LeaveConfiguration::first()?->notify_on_reject ?? true) {
            $this->notifySilently($employeeUser, new LeaveRejectedNotification($request));
        }

        return $request->fresh();
    }

    public function reject(LeaveRequest $request, string $reason): LeaveRequest
    {
        $approverId = Auth::id();

        $this->policyGuard->forRejection($request, $reason, $approverId);

        $request = $this->approvalService->reject($request, $approverId, $reason);

        // Overtime reserved nothing, so there is nothing to release.
        if (! $request->is_overtime) {
            $this->balanceService->release($request);
        }

        if (LeaveConfiguration::first()?->notify_on_reject ?? true) {
            $employeeUser = $this->resolveEmployeeUser($request->employee_id);
            $this->notifySilently($employeeUser, new LeaveRejectedNotification($request));
        }

        return $request;
    }

    public function policyOverride(LeaveRequest $request, int $hrUserId, string $overrideReason): LeaveRequest
    {
        $request = $this->approvalService->policyOverride($request, $hrUserId, $overrideReason);

        $employeeUser = $this->resolveEmployeeUser($request->employee_id);
        $this->notifySilently($employeeUser, new \App\Modules\HR\Leave\Notifications\LeaveApprovedNotification($request));

        activity()
            ->useLog('LeaveRequest')
            ->causedBy(\App\Core\User\Models\User::find($hrUserId))
            ->performedOn($request)
            ->withProperties(['override_reason' => $overrideReason])
            ->log("Policy override applied to leave request #{$request->request_number}");

        return $request;
    }

    public function cancel(LeaveRequest $request, string $reason, bool $adminOverride = false): LeaveRequest
    {
        // Admin override allows cancelling approved leave; only block if already cancelled/rejected.
        $isBlocked = $adminOverride
            ? in_array($request->status, [LeaveRequestStatus::Cancelled, LeaveRequestStatus::Rejected])
            : $request->status->isTerminal();

        if ($isBlocked) {
            throw ValidationException::withMessages(['cancel' => ['This request cannot be cancelled in its current state.']]);
        }

        if (!$adminOverride) {
            $this->policyGuard->forCancellation($request, $reason, Auth::id());
        }

        $wasApproved = $request->status === LeaveRequestStatus::Approved;

        DB::transaction(function () use ($request, $reason, $wasApproved) {
            $request->update([
                'status'                => LeaveRequestStatus::Cancelled->value,
                'cancelled_at'          => now(),
                'cancelled_by_user_id'  => Auth::id(),
                'cancellation_reason'   => $reason,
            ]);

            // Overtime credited annual leave on approval — reverse that credit; a pending
            // overtime request reserved nothing, so there is nothing to undo.
            if ($request->is_overtime) {
                if ($wasApproved) {
                    $this->balanceService->overtimeReverse($request);
                }
            } elseif ($wasApproved) {
                // Approved leaves are debited twice (reservation + consumption) — reverse both.
                $this->balanceService->releaseApproved($request);
            } else {
                // Pending/other leaves only have the reservation deduction.
                $this->balanceService->release($request);
            }
        });

        return $request->fresh();
    }

    private function validateParentalLeavePool(
        HRPerson             $employee,
        LeaveType            $leaveType,
        float                $workingDays,
        SubmitLeaveRequestDTO $dto,
    ): void {
        $pool = ParentalLeavePool::where(function ($q) use ($employee) {
            $q->where('primary_parent_id', $employee->id)
              ->orWhere('secondary_parent_id', $employee->id);
        })
        ->where('status', 'approved')
        ->latest()
        ->first();

        if (! $pool) {
            throw ValidationException::withMessages([
                'parental' => ['No approved parental leave pool found. Please contact HR to register your parental leave pool before applying.'],
            ]);
        }

        $requestedHours = $workingDays * 8;
        $isPrimary      = (int) $pool->primary_parent_id === $employee->id;

        if ($isPrimary) {
            // Lock period check (e.g. primary parent must take leave within X weeks of birth)
            if ($pool->isPrimaryLocked()) {
                throw ValidationException::withMessages([
                    'parental' => ["Your parental leave pool is locked until {$pool->primary_lock_until->format('d M Y')}. Leave cannot be taken outside this window."],
                ]);
            }

            $remainingHours = (float) $pool->primary_allocated_hours - (float) $pool->primary_used_hours;
            if ($requestedHours > $remainingHours) {
                throw ValidationException::withMessages([
                    'parental' => [sprintf(
                        'Insufficient parental leave pool balance for primary parent. Remaining: %s day(s), Requested: %s day(s).',
                        round($remainingHours / 8, 1),
                        $workingDays
                    )],
                ]);
            }
        } else {
            // Secondary parent
            $remainingHours = (float) $pool->secondary_allocated_hours - (float) $pool->secondary_used_hours;
            if ($requestedHours > $remainingHours) {
                throw ValidationException::withMessages([
                    'parental' => [sprintf(
                        'Insufficient parental leave pool balance for secondary parent. Remaining: %s day(s), Requested: %s day(s).',
                        round($remainingHours / 8, 1),
                        $workingDays
                    )],
                ]);
            }

            // Concurrent leave check — secondary cannot be on leave simultaneously if pool disallows it
            if (! $pool->concurrent_leave_allowed) {
                $primaryCurrentlyOnLeave = LeaveRequest::where('employee_id', $pool->primary_parent_id)
                    ->pending()
                    ->where('start_date', '<=', $dto->endDate)
                    ->where('end_date',   '>=', $dto->startDate)
                    ->exists();

                if (! $primaryCurrentlyOnLeave) {
                    $primaryCurrentlyOnLeave = LeaveRequest::where('employee_id', $pool->primary_parent_id)
                        ->where('status', 'approved')
                        ->where('start_date', '<=', $dto->endDate)
                        ->where('end_date',   '>=', $dto->startDate)
                        ->exists();
                }

                if ($primaryCurrentlyOnLeave) {
                    throw ValidationException::withMessages([
                        'parental' => ['Concurrent parental leave is not permitted on this pool. The primary parent has an overlapping leave request in the same period.'],
                    ]);
                }
            }
        }
    }

    private function resolveEmployeeUser(int $hrPersonId): ?\App\Core\User\Models\User
    {
        $userId = DB::table('hr_people')->where('id', $hrPersonId)->value('user_id');
        return $userId ? \App\Core\User\Models\User::find($userId) : null;
    }

    /**
     * Send a notification without crashing the request if mail delivery fails.
     * Database channel always runs first (set in via()), so the in-app notification
     * is always created even when the mail server is unavailable.
     */
    private function notifySilently(?\App\Core\User\Models\User $user, \Illuminate\Notifications\Notification $notification): void
    {
        if (! $user) {
            return;
        }
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Leave notification mail failed', [
                'user_id'   => $user->id,
                'class'     => get_class($notification),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
