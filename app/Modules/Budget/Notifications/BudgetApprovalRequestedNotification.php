<?php

namespace App\Modules\Budget\Notifications;

use App\Modules\Budget\Models\BudgetApprovalRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A budget decision is waiting for this person.
 *
 * The notification is the part whose absence made the old Approve button theoretical: a status
 * change nobody is told about is a queue nobody works. It carries the number, because an approver
 * deciding whether to open the page wants to know whether it is R4,000 or R4,000,000.
 */
class BudgetApprovalRequestedNotification extends Notification
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
        $subject = $this->subjectLabel();

        $mail = (new MailMessage)
            ->subject(__('budget_management.approvals.mail_requested_subject', [
                'subject' => $subject,
                'label'   => $this->request->subject_label ?: '—',
            ]))
            ->greeting(__('budget_management.approvals.mail_greeting'))
            ->line(__('budget_management.approvals.mail_requested_line', [
                'subject' => mb_strtolower($subject),
                'label'   => $this->request->subject_label ?: '—',
                'budget'  => $this->request->budget?->name ?: '—',
            ]));

        if ($this->request->amount !== null) {
            $mail->line(__('budget_management.approvals.mail_amount', [
                'amount'   => number_format((float) $this->request->amount, 2),
                'currency' => $this->request->currency ?: '',
            ]));
        }

        if ($this->request->note) {
            $mail->line($this->request->note);
        }

        return $mail->action(
            __('budget_management.approvals.mail_action'),
            route('budgets.approvals.index'),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'budget_approval_requested',
            'message'    => __('budget_management.approvals.inapp_requested', [
                'subject' => mb_strtolower($this->subjectLabel()),
                'label'   => $this->request->subject_label ?: '—',
            ]),
            'request_id' => $this->request->uuid,
            'subject'    => $this->request->subject_type,
            'amount'     => $this->request->amount,
            'currency'   => $this->request->currency,
            'url'        => route('budgets.approvals.index'),
        ];
    }

    /**
     * The subject's name, spelled out rather than built by concatenation.
     *
     * A key assembled from a variable is one no search finds, and the translation check can only
     * guard keys it can see.
     */
    private function subjectLabel(): string
    {
        return match ($this->request->subject_type) {
            'budget'      => __('budget_management.approvals.subject_budget'),
            'adjustment'  => __('budget_management.approvals.subject_adjustment'),
            'override'    => __('budget_management.approvals.subject_override'),
            'reservation' => __('budget_management.approvals.subject_reservation'),
            default       => $this->request->subject_type,
        };
    }
}
