<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveSubmittedNotification extends Notification
{
    use Queueable;

    public int $tries   = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly LeaveRequest $request) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $employee = $this->request->employee;
        $lt       = $this->request->leaveType;
        $name     = trim(($employee?->first_name ?? '') . ' ' . ($employee?->surname ?? '')) ?: "Employee #{$this->request->employee_id}";

        return (new MailMessage)
            ->subject("Leave Approval Required — {$this->request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$name} has submitted a {$lt?->name} leave request awaiting your approval.")
            ->line("**Period:** {$this->request->start_date->format('d M Y')} to {$this->request->end_date->format('d M Y')}")
            ->line("**Working Days:** {$this->request->working_days}")
            ->when($this->request->notes, fn($m) => $m->line("**Notes:** {$this->request->notes}"))
            ->action('Review Request', url("/leave-management/approvals"))
            ->line("Reference: {$this->request->request_number}")
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $employee = $this->request->employee;
        $name     = trim(($employee?->first_name ?? '') . ' ' . ($employee?->surname ?? '')) ?: "Employee #{$this->request->employee_id}";
        return [
            'type'           => 'leave_submitted',
            'request_number' => $this->request->request_number,
            'request_id'     => $this->request->id,
            'employee_id'    => $this->request->employee_id,
            'leave_type'     => $this->request->leaveType?->name,
            'start_date'     => $this->request->start_date?->format('Y-m-d'),
            'end_date'       => $this->request->end_date?->format('Y-m-d'),
            'message'        => "{$name} submitted a {$this->request->leaveType?->name} leave request ({$this->request->request_number}).",
            'url'            => '/leave-management/approvals',
        ];
    }
}
