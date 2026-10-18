<?php

namespace App\Modules\Policies\Services\Enforcement;

use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\Models\PolicyViolation;
use App\Modules\Policies\Notifications\PolicyViolationNotification;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;

/**
 * NotificationDispatcher
 * 
 * Sends notifications about policy violations to relevant parties.
 * 
 * Notifications sent to:
 * - User who triggered violation (if not blocked)
 * - Manager (if escalation)
 * - Policy owner (if critical)
 * - Compliance team (if high severity)
 * 
 * Notification types:
 * - Direct notification via mail/SMS/in-app
 * - Notification in approval workflow
 * - Escalation alerts to managers
 * - Audit/summary reports
 */
class NotificationDispatcher
{
    /**
     * Dispatch notifications for a violation
     * 
     * @param EvaluationResultDTO $result
     * @param PolicyViolation $violation
     * @param int $severityScore
     * @param array $explanation
     */
    public function dispatch(
        EvaluationResultDTO $result,
        PolicyViolation $violation,
        int $severityScore,
        array $explanation
    ): void {
        // Notify user if action was allowed (warn/escalate/log_only)
        if ($result->outcome !== 'block') {
            $this->notifyUser($result, $violation, $severityScore);
        }

        // Escalate to manager if required
        if ($result->outcome === 'escalate') {
            $this->notifyManager($result, $violation, $severityScore);
        }

        // Notify policy owner if critical
        if ($severityScore >= 85) {
            $this->notifyPolicyOwner($result, $violation, $severityScore);
        }

        // Notify compliance team if high severity
        if ($severityScore >= 70) {
            $this->notifyComplianceTeam($result, $violation, $severityScore);
        }
    }

    /**
     * Notify the user who triggered the violation
     * 
     * @param EvaluationResultDTO $result
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    private function notifyUser(
        EvaluationResultDTO $result,
        PolicyViolation $violation,
        int $severityScore
    ): void {
        $user = User::find($result->event->actorId);
        if (!$user) {
            return;
        }

        $title = match ($result->outcome) {
            'warn' => 'Policy Warning',
            'escalate' => 'Action Requires Approval',
            'log_only' => 'Policy Notification',
            default => 'Policy Notice',
        };

        $message = match ($result->outcome) {
            'warn' => "Your {$result->event->action} action triggered a policy warning. "
                . "It has been allowed but flagged for review.",
            'escalate' => "Your {$result->event->action} action requires manager approval. "
                . "It will be reviewed and you'll be notified of the decision.",
            'log_only' => "Your {$result->event->action} action has been noted for audit purposes.",
            default => "Your action has been evaluated against company policies.",
        };

        // Send notification
        Notification::send($user, new PolicyViolationNotification(
            violation: $violation,
            title: $title,
            message: $message,
            severity_score: $severityScore,
            recipient_type: 'user'
        ));

        // Log notification event
        $this->logNotification($user->id, 'user', $violation, $result->outcome);
    }

    /**
     * Escalate to user's manager for approval
     * 
     * @param EvaluationResultDTO $result
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    private function notifyManager(
        EvaluationResultDTO $result,
        PolicyViolation $violation,
        int $severityScore
    ): void {
        $user = User::find($result->event->actorId);
        if (!$user) {
            return;
        }

        // Find manager
        $manager = $this->findManager($user);
        if (!$manager) {
            return;
        }

        $title = "Action Requires Your Approval";
        $message = "{$user->name} has triggered a policy that requires manager approval. "
            . "Rule: {$result->rule->name}. Please review and decide.";

        // Send notification to manager
        Notification::send($manager, new PolicyViolationNotification(
            violation: $violation,
            title: $title,
            message: $message,
            severity_score: $severityScore,
            recipient_type: 'manager',
            action_required: true
        ));

        // Log notification event
        $this->logNotification($manager->id, 'manager', $violation, 'escalation');
    }

    /**
     * Notify policy owner of critical violations
     * 
     * @param EvaluationResultDTO $result
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    private function notifyPolicyOwner(
        EvaluationResultDTO $result,
        PolicyViolation $violation,
        int $severityScore
    ): void {
        $owner = $result->rule->policy->owner;
        if (!$owner) {
            return;
        }

        $title = "Critical Policy Violation";
        $message = "A critical violation of your policy '{$result->rule->policy->name}' has been detected. "
            . "Rule: {$result->rule->name}. User: {$violation->user?->name}. "
            . "Severity Score: $severityScore/100. "
            . "Please review and take appropriate action.";

        // Send notification to policy owner
        Notification::send($owner, new PolicyViolationNotification(
            violation: $violation,
            title: $title,
            message: $message,
            severity_score: $severityScore,
            recipient_type: 'policy_owner',
            action_required: true
        ));

        // Log notification event
        $this->logNotification($owner->id, 'policy_owner', $violation, 'critical');
    }

    /**
     * Notify compliance/HR team of high-severity violations
     * 
     * @param EvaluationResultDTO $result
     * @param PolicyViolation $violation
     * @param int $severityScore
     */
    private function notifyComplianceTeam(
        EvaluationResultDTO $result,
        PolicyViolation $violation,
        int $severityScore
    ): void {
        // Get compliance team (users with compliance role)
        $complianceUsers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['compliance', 'hr', 'super admin']);
        })->get();

        if ($complianceUsers->isEmpty()) {
            return;
        }

        $title = "High-Severity Policy Violation Alert";
        $message = "A high-severity violation (score: $severityScore/100) has been recorded. "
            . "Policy: {$result->rule->policy->name}. "
            . "Rule: {$result->rule->name}. "
            . "User: {$violation->user?->name}. "
            . "Module: {$result->event->module}. "
            . "Action: {$result->event->action}.";

        // Send broadcast notification to compliance team
        Notification::send($complianceUsers, new PolicyViolationNotification(
            violation: $violation,
            title: $title,
            message: $message,
            severity_score: $severityScore,
            recipient_type: 'compliance_team'
        ));

        // Log notification event
        foreach ($complianceUsers as $user) {
            $this->logNotification($user->id, 'compliance_team', $violation, 'alert');
        }
    }

    /**
     * Find user's manager/supervisor
     * 
     * @param User $user
     * @return User|null
     */
    private function findManager(User $user): ?User
    {
        // Try to find manager from user relationship
        if ($user->manager_id) {
            return User::find($user->manager_id);
        }

        // Try to find manager via department
        if ($user->department_id) {
            return User::where('department_id', $user->department_id)
                ->whereHas('roles', function ($q) {
                    $q->whereIn('name', ['manager', 'supervisor']);
                })
                ->first();
        }

        return null;
    }

    /**
     * Log notification event for audit trail
     * 
     * @param int $userId
     * @param string $recipient_type
     * @param PolicyViolation $violation
     * @param string $notification_type
     */
    private function logNotification(
        int $userId,
        string $recipient_type,
        PolicyViolation $violation,
        string $notification_type
    ): void {
        // Create audit log
        DB::table('policy_violation_notifications')->insert([
            'violation_id' => $violation->id,
            'recipient_id' => $userId,
            'recipient_type' => $recipient_type,
            'notification_type' => $notification_type,
            'sent_at' => now(),
            'status' => 'sent',
            'created_at' => now(),
        ]);
    }
}
