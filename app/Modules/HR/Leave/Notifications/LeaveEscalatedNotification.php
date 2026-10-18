<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveEscalatedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly LeaveRequest $request) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $employee = $this->request->employee;
        $name     = trim(($employee?->first_name ?? '') . ' ' . ($employee?->surname ?? '')) ?: "Employee #{$this->request->employee_id}";
        $lt       = $this->request->leaveType;

        return (new MailMessage)
            ->subject("ESCALATED: Leave Request Awaiting Action — {$this->request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A leave request has been **escalated** and requires your immediate attention.")
            ->line("**Employee:** {$name}")
            ->line("**Leave Type:** {$lt?->name}")
            ->line("**Period:** {$this->request->start_date?->format('d M Y')} to {$this->request->end_date?->format('d M Y')}")
            ->line("**Submitted:** {$this->request->submitted_at?->format('d M Y H:i')}")
            ->line("**Escalation Count:** {$this->request->escalation_count}")
            ->action('Action Now', url("/leave-management/approvals"))
            ->line("Please action this request urgently to avoid further escalation.")
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $employee = $this->request->employee;
        $name     = trim(($employee?->first_name ?? '') . ' ' . ($employee?->surname ?? '')) ?: "Employee #{$this->request->employee_id}";
        return [
            'type'             => 'leave_escalated',
            'request_number'   => $this->request->request_number,
            'request_id'       => $this->request->id,
            'escalation_count' => $this->request->escalation_count,
            'message'          => "{$name}'s leave ({$this->request->request_number}) has been escalated — action required.",
            'url'              => '/leave-management/approvals',
        ];
    }
}
