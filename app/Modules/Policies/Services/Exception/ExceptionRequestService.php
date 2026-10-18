<?php

namespace App\Modules\Policies\Services\Exception;

use App\Modules\Policies\Models\PolicyException;
use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyRule;
use App\Core\User\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ExceptionRequestService
 * 
 * Manage policy exception requests:
 * - Create exception requests
 * - Route for approval
 * - Approve/reject workflow
 * - Auto-expiry handling
 * - Re-evaluation after expiry
 */
class ExceptionRequestService
{
    /**
     * Create exception request
     * 
     * @param int $userId
     * @param int $policyId
     * @param int $ruleId
     * @param string $reason
     * @param \Carbon\Carbon $startDate
     * @param \Carbon\Carbon|null $endDate
     * @param array|null $evidence
     * @return PolicyException
     */
    public function createRequest(
        int $userId,
        int $policyId,
        int $ruleId,
        string $reason,
        Carbon $startDate,
        ?Carbon $endDate = null,
        ?array $evidence = null
    ): PolicyException {
        $durationDays = $endDate ? $startDate->diffInDays($endDate) : null;

        return PolicyException::create([
            'user_id' => $userId,
            'policy_id' => $policyId,
            'rule_id' => $ruleId,
            'reason' => $reason,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'evidence' => $evidence,
            'duration_days' => $durationDays,
            'status' => 'draft',
        ]);
    }

    /**
     * Submit exception request for approval
     * 
     * @param int $exceptionId
     * @return PolicyException
     */
    public function submitForApproval(int $exceptionId): PolicyException
    {
        $exception = PolicyException::findOrFail($exceptionId);
        $exception->update(['status' => 'submitted']);

        // Notify approver
        $this->notifyApprover($exception);

        return $exception;
    }

    /**
     * Approve exception request
     * 
     * @param int $exceptionId
     * @param int $approverId
     * @param string|null $notes
     * @return PolicyException
     */
    public function approve(
        int $exceptionId,
        int $approverId,
        ?string $notes = null
    ): PolicyException {
        $exception = PolicyException::findOrFail($exceptionId);

        $exception->update([
            'status' => 'approved',
            'approved_by' => $approverId,
            'approved_at' => now(),
            'approval_notes' => $notes,
        ]);

        // Notify user
        $this->notifyUserApproved($exception);

        return $exception;
    }

    /**
     * Reject exception request
     * 
     * @param int $exceptionId
     * @param int $rejectorId
     * @param string $reason
     * @return PolicyException
     */
    public function reject(
        int $exceptionId,
        int $rejectorId,
        string $reason
    ): PolicyException {
        $exception = PolicyException::findOrFail($exceptionId);

        $exception->update([
            'status' => 'rejected',
            'approved_by' => $rejectorId,
            'rejection_reason' => $reason,
        ]);

        // Notify user
        $this->notifyUserRejected($exception);

        return $exception;
    }

    /**
     * Escalate exception to policy owner
     * 
     * @param int $exceptionId
     * @param string|null $reason
     * @return PolicyException
     */
    public function escalate(int $exceptionId, ?string $reason = null): PolicyException
    {
        $exception = PolicyException::findOrFail($exceptionId);

        $exception->update([
            'status' => 'escalated',
            'approval_notes' => $reason,
        ]);

        // Notify policy owner
        $this->notifyPolicyOwner($exception);

        return $exception;
    }

    /**
     * Check if exception applies to action
     * 
     * @param int $userId
     * @param int $policyId
     * @param int $ruleId
     * @return bool
     */
    public function hasValidException(int $userId, int $policyId, int $ruleId): bool
    {
        $exception = PolicyException::where('user_id', $userId)
            ->where('policy_id', $policyId)
            ->where('rule_id', $ruleId)
            ->where('status', 'approved')
            ->first();

        if (!$exception) {
            return false;
        }

        return $exception->isValid();
    }

    /**
     * Get exception for user/policy/rule
     * 
     * @param int $userId
     * @param int $policyId
     * @param int $ruleId
     * @return PolicyException|null
     */
    public function getValidException(int $userId, int $policyId, int $ruleId): ?PolicyException
    {
        $exception = PolicyException::where('user_id', $userId)
            ->where('policy_id', $policyId)
            ->where('rule_id', $ruleId)
            ->where('status', 'approved')
            ->first();

        if ($exception && $exception->isValid()) {
            return $exception;
        }

        return null;
    }

    /**
     * Record exception usage (when it's applied to bypass a violation)
     * 
     * @param int $exceptionId
     * @param int $violationId
     */
    public function recordUsage(int $exceptionId, int $violationId): void
    {
        $exception = PolicyException::findOrFail($exceptionId);
        $exception->increment('usage_count');
        $exception->increment('violation_count');

        // Tag violation with exception used
        PolicyViolation::findOrFail($violationId)->update([
            'exception_id' => $exceptionId,
        ]);
    }

    /**
     * Auto-expire exceptions that have passed end date
     */
    public function autoExpireExceptions(): int
    {
        $expired = PolicyException::where('status', 'approved')
            ->where('end_date', '<', Carbon::today())
            ->where('expired_at', null)
            ->update([
                'status' => 'expired',
                'expired_at' => now(),
            ]);

        return $expired;
    }

    /**
     * Send expiry notifications (7 days before)
     */
    public function sendExpiryNotifications(): int
    {
        $expiringIn7Days = PolicyException::where('status', 'approved')
            ->where('end_date', '>=', Carbon::today())
            ->where('end_date', '<=', Carbon::today()->addDays(7))
            ->where('expiry_notified_at', null)
            ->where('auto_notify_before_expiry', true)
            ->get();

        $count = 0;
        foreach ($expiringIn7Days as $exception) {
            $this->notifyUserExpiringsoon($exception);
            $exception->update(['expiry_notified_at' => now()]);
            $count++;
        }

        return $count;
    }

    /**
     * Re-evaluate user compliance after exception expires
     * 
     * @param int $exceptionId
     * @return array
     */
    public function reEvaluateAfterExpiry(int $exceptionId): array
    {
        $exception = PolicyException::findOrFail($exceptionId);

        if (!$exception->isExpired()) {
            return ['error' => 'Exception has not expired yet'];
        }

        // Check if user would violate the rule now
        $violations = PolicyViolation::where('user_id', $exception->user_id)
            ->where('policy_id', $exception->policy_id)
            ->where('rule_id', $exception->rule_id)
            ->where('created_at', '>=', $exception->start_date)
            ->where('created_at', '<=', $exception->end_date ?? now())
            ->count();

        return [
            'exception_id' => $exceptionId,
            'violations_during_exception' => $violations,
            'user_compliant' => $violations === 0,
            'recommendation' => $violations > 0 
                ? 'User repeatedly violated rule - monitor closely'
                : 'User complied after exception ended',
        ];
    }

    /**
     * Get user's pending exception requests
     * 
     * @param int $userId
     * @return mixed
     */
    public function getUserPendingExceptions(int $userId)
    {
        return PolicyException::where('user_id', $userId)
            ->whereIn('status', ['draft', 'submitted', 'escalated'])
            ->orderByDesc('created_at')
            ->with('policy', 'rule', 'approver')
            ->get();
    }

    /**
     * Get user's active exceptions
     * 
     * @param int $userId
     * @return mixed
     */
    public function getUserActiveExceptions(int $userId)
    {
        return PolicyException::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereRaw('? BETWEEN start_date AND IFNULL(end_date, ?)', [
                Carbon::today(),
                Carbon::today(),
            ])
            ->orderByDesc('start_date')
            ->with('policy', 'rule')
            ->get();
    }

    /**
     * Notify approver of pending exception
     * 
     * @param PolicyException $exception
     */
    private function notifyApprover(PolicyException $exception): void
    {
        $manager = $exception->user->manager;
        if (!$manager) {
            return;
        }

        // Send notification
        $this->sendNotification($manager, [
            'type' => 'exception_request',
            'message' => "{$exception->user->name} requested an exception for {$exception->policy->name}",
            'exception_id' => $exception->id,
        ]);
    }

    /**
     * Notify user of approval
     * 
     * @param PolicyException $exception
     */
    private function notifyUserApproved(PolicyException $exception): void
    {
        $this->sendNotification($exception->user, [
            'type' => 'exception_approved',
            'message' => "Your exception request for {$exception->policy->name} has been approved",
            'valid_until' => $exception->end_date?->format('M d, Y'),
        ]);
    }

    /**
     * Notify user of rejection
     * 
     * @param PolicyException $exception
     */
    private function notifyUserRejected(PolicyException $exception): void
    {
        $this->sendNotification($exception->user, [
            'type' => 'exception_rejected',
            'message' => "Your exception request was rejected",
            'reason' => $exception->rejection_reason,
        ]);
    }

    /**
     * Notify policy owner of escalation
     * 
     * @param PolicyException $exception
     */
    private function notifyPolicyOwner(PolicyException $exception): void
    {
        $owner = $exception->policy->owner;
        if (!$owner) {
            return;
        }

        $this->sendNotification($owner, [
            'type' => 'exception_escalated',
            'message' => "Exception request escalated for policy: {$exception->policy->name}",
            'user' => $exception->user->name,
            'exception_id' => $exception->id,
        ]);
    }

    /**
     * Notify user of upcoming expiry
     * 
     * @param PolicyException $exception
     */
    private function notifyUserExpiringSOon(PolicyException $exception): void
    {
        $daysRemaining = $exception->daysRemaining();

        $this->sendNotification($exception->user, [
            'type' => 'exception_expiring_soon',
            'message' => "Your exception for {$exception->policy->name} expires in {$daysRemaining} days",
            'expiry_date' => $exception->end_date?->format('M d, Y'),
        ]);
    }

    /**
     * Send notification (placeholder)
     * 
     * @param User $user
     * @param array $data
     */
    private function sendNotification(User $user, array $data): void
    {
        if (!$user->email) {
            return;
        }

        \Illuminate\Support\Facades\Mail::to($user->email)
            ->send(new \App\Modules\Policies\Mail\PolicyExceptionNotificationMail($user, $data));
    }
}
