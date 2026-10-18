<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Nudges an approver who has left a leave request sitting in their queue. */
class LeavePendingApprovalReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly LeaveRequest $request,
        public readonly int $daysWaiting,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $employee = $this->request->employee;
        $name = $employee ? trim($employee->first_name . ' ' . $employee->surname) : 'An employee';

        return (new MailMessage())
            ->subject('Leave approval still outstanding — ' . $this->request->request_number)
            ->greeting('Hello')
            ->line("{$name} submitted a leave request {$this->daysWaiting} day(s) ago and it is still waiting for your decision.")
            ->line('Reference: ' . $this->request->request_number)
            ->line('Dates: ' . $this->request->start_date . ' to ' . $this->request->end_date)
            ->action('Review the request', url('/leave-management/approvals'))
            ->line('Requests left unattended are escalated automatically.');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'            => 'leave_pending_reminder',
            'leave_request_id'=> $this->request->id,
            'request_number'  => $this->request->request_number,
            'days_waiting'    => $this->daysWaiting,
            'message'         => "Leave request {$this->request->request_number} has been waiting {$this->daysWaiting} day(s) for your approval.",
            'url'             => '/leave-management/approvals',
        ];
    }
}
