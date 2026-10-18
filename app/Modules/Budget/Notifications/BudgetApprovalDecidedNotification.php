<?php

namespace App\Modules\Budget\Notifications;

use App\Modules\Budget\Models\BudgetApprovalRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The answer, back to whoever asked.
 *
 * A rejection carries its reason, because the alternative is the submitter resubmitting the same
 * thing and the loop repeating until somebody picks up a phone.
 */
class BudgetApprovalDecidedNotification extends Notification
{
    public function __construct(public readonly BudgetApprovalRequest $request)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('budget_management.approvals.mail_decided_subject', [
                'label'  => $this->request->subject_label ?: '—',
                'status' => $this->statusLabel(),
            ]))
            ->greeting(__('budget_management.approvals.mail_greeting'))
            ->line(__('budget_management.approvals.mail_decided_line', [
                'label'  => $this->request->subject_label ?: '—',
                'status' => mb_strtolower($this->statusLabel()),
            ]));

        if ($this->request->decision_reason) {
            $mail->line(__('budget_management.approvals.mail_reason', ['reason' => $this->request->decision_reason]));
        }

        return $mail->action(
            __('budget_management.approvals.mail_action'),
            route('budgets.approvals.index', ['tab' => 'mine']),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'budget_approval_decided',
            'message'    => __('budget_management.approvals.inapp_decided', [
                'label'  => $this->request->subject_label ?: '—',
                'status' => mb_strtolower($this->statusLabel()),
            ]),
            'request_id' => $this->request->uuid,
            'status'     => $this->request->status,
            'reason'     => $this->request->decision_reason,
            'url'        => route('budgets.approvals.index', ['tab' => 'mine']),
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->request->status) {
            'approved'  => __('budget_management.approvals.status_approved'),
            'rejected'  => __('budget_management.approvals.status_rejected'),
            'cancelled' => __('budget_management.approvals.status_cancelled'),
            default     => __('budget_management.approvals.status_pending'),
        };
    }
}
