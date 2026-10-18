<?php

namespace App\Modules\Policies\Notifications;

use App\Modules\Policies\Models\PolicyViolation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * PolicyViolationNotification
 * 
 * Base notification for policy violations sent to users, managers, and compliance teams.
 * Supports multiple channels: mail, database, SMS, Slack, etc.
 */
class PolicyViolationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var PolicyViolation
     */
    protected PolicyViolation $violation;

    /**
     * @var string
     */
    protected string $title;

    /**
     * @var string
     */
    protected string $message;

    /**
     * @var int
     */
    protected int $severity_score;

    /**
     * @var string
     */
    protected string $recipient_type;

    /**
     * @var bool
     */
    protected bool $action_required;

    /**
     * Create a new notification instance
     */
    public function __construct(
        PolicyViolation $violation,
        string $title,
        string $message,
        int $severity_score,
        string $recipient_type = 'user',
        bool $action_required = false
    ) {
        $this->violation = $violation;
        $this->title = $title;
        $this->message = $message;
        $this->severity_score = $severity_score;
        $this->recipient_type = $recipient_type;
        $this->action_required = $action_required;
    }

    /**
     * Get the notification's delivery channels
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Different recipients use different channels
        return match ($this->recipient_type) {
            'manager', 'policy_owner', 'compliance_team' => ['mail', 'database'],
            'user' => ['database', 'mail'],
            default => ['database'],
        };
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(object $notifiable): MailMessage
    {
        $severity_label = match (true) {
            $this->severity_score >= 85 => 'Critical',
            $this->severity_score >= 70 => 'High',
            $this->severity_score >= 40 => 'Medium',
            default => 'Low',
        };

        $severity_color = match (true) {
            $this->severity_score >= 85 => '#DC2626', // red
            $this->severity_score >= 70 => '#EA580C', // orange
            $this->severity_score >= 40 => '#F59E0B', // yellow
            default => '#10B981', // green
        };

        $mail = new MailMessage();
        $mail->subject($this->title)
            ->line("**Severity:** <span style=\"color: {$severity_color}; font-weight: bold;\">{$severity_label} ({$this->severity_score}/100)</span>")
            ->line($this->message);

        // Add action button if required
        if ($this->action_required) {
            $mail->action('Review & Respond', $this->getActionUrl());
        }

        // Add violation link
        $mail->action('View Details', $this->getViolationUrl());

        // Add footer
        $mail->line("---")
            ->line("Violation ID: {$this->violation->id}")
            ->line("Recorded: {$this->violation->created_at->format('M d, Y H:i')}");

        return $mail;
    }

    /**
     * Get the array representation of the notification
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $type = match ($this->recipient_type) {
            'manager'         => 'policy_violation_escalation',
            'policy_owner'    => 'policy_violation_critical',
            'compliance_team' => 'policy_violation_alert',
            default           => 'policy_violation',
        };

        return [
            'type'            => $type,
            'title'           => $this->title,
            'message'         => $this->message,
            'severity_score'  => $this->severity_score,
            'severity_label'  => $this->getSeverityLabel(),
            'violation_id'    => $this->violation->id,
            'policy_id'       => $this->violation->policy_id,
            'rule_id'         => $this->violation->rule_id,
            'user_id'         => $this->violation->user_id,
            'recipient_type'  => $this->recipient_type,
            'action_required' => $this->action_required,
            'url'             => $this->action_required ? $this->getActionUrl() : $this->getViolationUrl(),
        ];
    }

    /**
     * Get severity label based on score
     *
     * @return string
     */
    private function getSeverityLabel(): string
    {
        return match (true) {
            $this->severity_score >= 85 => 'Critical',
            $this->severity_score >= 70 => 'High',
            $this->severity_score >= 40 => 'Medium',
            default => 'Low',
        };
    }

    /**
     * Get action URL for manager/policy owner approvals
     *
     * @return string
     */
    private function getActionUrl(): string
    {
        // There are no dedicated approve/review routes — both actions happen on the
        // violation detail page. Fall back safely so a notification never 500s.
        return in_array($this->recipient_type, ['manager', 'policy_owner'], true)
            ? $this->getViolationUrl()
            : '';
    }

    /**
     * Get violation details URL
     *
     * @return string
     */
    private function getViolationUrl(): string
    {
        // Point at the real policy-violations view; guard against the route being
        // absent (e.g. module gated) so a notification never crashes the caller.
        return \Illuminate\Support\Facades\Route::has('policy-violations.show')
            ? route('policy-violations.show', ['violation' => $this->violation->id])
            : url('/policy-violations/' . $this->violation->id);
    }
}
