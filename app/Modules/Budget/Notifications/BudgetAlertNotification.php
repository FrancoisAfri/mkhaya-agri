<?php

namespace App\Modules\Budget\Notifications;

use App\Modules\Budget\Models\BudgetAlert;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A budget has crossed a line its owners asked to be told about.
 *
 * Sent once per alert, not once per sweep. The nightly job re-stamps a live alert rather than
 * raising a new one, so nobody gets the same warning for thirty consecutive mornings and learns to
 * filter the sender.
 */
class BudgetAlertNotification extends Notification
{
    public function __construct(public readonly BudgetAlert $alert)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $budget = $this->alert->budget;

        return (new MailMessage)
            ->subject(__('budget_management.alerts.mail_subject', [
                'severity' => $this->severityLabel(),
                'budget'   => $budget?->name ?: '—',
            ]))
            ->greeting(__('budget_management.approvals.mail_greeting'))
            ->line($this->alert->message ?: __('budget_management.alerts.mail_fallback'))
            ->line(__('budget_management.alerts.mail_context', [
                'code'      => $budget?->code ?: '—',
                'allocated' => number_format((float) ($budget?->allocated_amount ?? 0), 2),
                'currency'  => $budget?->currency ?: '',
            ]))
            ->action(__('budget_management.alerts.mail_action'), route('budgets.tracking.index'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'     => 'budget_alert',
            'message'  => $this->alert->message,
            'severity' => $this->alert->severity,
            'alert_id' => $this->alert->uuid,
            'budget'   => $this->alert->budget?->code,
            'url'      => route('budgets.tracking.index'),
        ];
    }

    private function severityLabel(): string
    {
        return match ($this->alert->severity) {
            'info'     => __('budget_management.alerts.severity_info'),
            'warning'  => __('budget_management.alerts.severity_warning'),
            'critical' => __('budget_management.alerts.severity_critical'),
            'block'    => __('budget_management.alerts.severity_block'),
            default    => $this->alert->severity,
        };
    }
}
