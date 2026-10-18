<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something is waiting for this person's decision.
 *
 * The notification is the part whose absence made "submit for approval" theoretical: a status
 * change nobody is told about is a queue nobody works. It carries the number, because an approver
 * deciding whether to open the page wants to know whether it is R400 or R400,000.
 */
class ApprovalRequestedNotification extends Notification
{
    public function __construct(public readonly ApprovalRequest $request)
    {
    }

    /**
     * Email and in-app always; SMS only above the flow's own threshold.
     *
     * The threshold is the tenant's own statement of what counts as significant on this flow, so
     * using it here means the text message goes out for exactly the approvals that company already
     * said were worth stopping for — rather than for every one, which is how a paid channel becomes
     * one nobody reads.
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];

        if ($this->isSignificant()) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    private function isSignificant(): bool
    {
        $threshold = $this->request->flow?->threshold;

        return $threshold !== null
            && $this->request->amount !== null
            && (float) $this->request->amount >= (float) $threshold;
    }

    /** Short by design: SMS is billed per segment, and the detail is one tap away. */
    public function toSms(object $notifiable): string
    {
        return __('project_management.approvals.sms_requested', [
            'subject' => mb_strtolower($this->subjectLabel()),
            'label'   => $this->request->subject_label ?: '—',
            'amount'  => $this->request->amount !== null
                ? trim(($this->request->currency ?: '') . ' ' . number_format((float) $this->request->amount, 2))
                : '',
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->subjectLabel();

        $mail = (new MailMessage)
            ->subject(__('project_management.approvals.mail_requested_subject', [
                'subject' => $subject,
                'label'   => $this->request->subject_label ?: '—',
            ]))
            ->greeting(__('project_management.approvals.mail_greeting'))
            ->line(__('project_management.approvals.mail_requested_line', [
                'subject' => mb_strtolower($subject),
                'label'   => $this->request->subject_label ?: '—',
                'project' => $this->request->project?->name ?: '—',
            ]));

        if ($this->request->amount !== null) {
            $mail->line(__('project_management.approvals.mail_amount', [
                'amount' => number_format((float) $this->request->amount, 2),
                'currency' => $this->request->currency ?: '',
            ]));
        }

        if ($this->request->note) {
            $mail->line($this->request->note);
        }

        return $mail->action(
            __('project_management.approvals.mail_action'),
            route('project-management.approvals.index'),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'pm_approval_requested',
            'message'    => __('project_management.approvals.inapp_requested', [
                'subject' => mb_strtolower($this->subjectLabel()),
                'label'   => $this->request->subject_label ?: '—',
            ]),
            'request_id' => $this->request->uuid,
            'subject'    => $this->request->subject_type,
            'amount'     => $this->request->amount,
            'currency'   => $this->request->currency,
            'url'        => route('project-management.approvals.index'),
        ];
    }

    /**
     * The subject's name, spelled out rather than built by concatenation.
     *
     * A key assembled from a variable is one no search finds, and the translation test can only
     * guard keys it can see — which is the whole reason that test exists.
     */
    private function subjectLabel(): string
    {
        return match ($this->request->subject_type) {
            'timesheet'     => __('project_management.approvals.subject_timesheet'),
            'expense'       => __('project_management.approvals.subject_expense'),
            'document'      => __('project_management.approvals.subject_document'),
            'project_stage' => __('project_management.approvals.subject_project_stage'),
            'contract'      => __('project_management.approvals.subject_contract'),
            default         => $this->request->subject_type,
        };
    }
}
