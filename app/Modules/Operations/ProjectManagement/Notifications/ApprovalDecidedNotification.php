<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The answer, back to whoever asked.
 *
 * A rejection carries its reason in the notification rather than only on the record. Somebody who
 * has to open a page to find out what was wrong with their timesheet mostly resubmits the same
 * timesheet.
 */
class ApprovalDecidedNotification extends Notification
{
    public function __construct(public readonly ApprovalRequest $request)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->request->status === 'approved';

        $mail = (new MailMessage)
            ->subject(__('project_management.approvals.mail_decided_subject', [
                'status' => $this->statusLabel(),
                'label'  => $this->request->subject_label ?: '—',
            ]))
            ->line(__('project_management.approvals.mail_decided_line', [
                'subject' => mb_strtolower($this->subjectLabel()),
                'label'   => $this->request->subject_label ?: '—',
                'status'  => mb_strtolower($this->statusLabel()),
            ]));

        if (! $approved) {
            $mail->error();

            if ($this->request->decision_reason) {
                $mail->line(__('project_management.approvals.mail_reason', [
                    'reason' => $this->request->decision_reason,
                ]));
            }
        }

        return $mail->action(
            __('project_management.approvals.mail_action_mine'),
            route('project-management.approvals.index', ['tab' => 'mine']),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'pm_approval_decided',
            'message'    => __('project_management.approvals.inapp_decided', [
                'label'  => $this->request->subject_label ?: '—',
                'status' => mb_strtolower($this->statusLabel()),
            ]),
            'request_id' => $this->request->uuid,
            'status'     => $this->request->status,
            'reason'     => $this->request->decision_reason,
            'url'        => route('project-management.approvals.index', ['tab' => 'mine']),
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
            default         => $this->request->subject_type,
        };
    }

    /** As above: the four statuses named, so the strings stay findable. */
    private function statusLabel(): string
    {
        return match ($this->request->status) {
            'approved'  => __('project_management.approvals.status_approved'),
            'rejected'  => __('project_management.approvals.status_rejected'),
            'cancelled' => __('project_management.approvals.status_cancelled'),
            default     => __('project_management.approvals.status_pending'),
        };
    }
}
