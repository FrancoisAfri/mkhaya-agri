<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveSubmittedEmployeeNotification extends Notification
{
    use Queueable;

    public int $tries    = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly LeaveRequest $request) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $lt = $this->request->leaveType;

        return (new MailMessage)
            ->subject("Leave Application Received — {$this->request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your {$lt?->name} leave application has been received and is now pending approval.")
            ->line("**Reference:** {$this->request->request_number}")
            ->line("**Period:** {$this->request->start_date->format('d M Y')} to {$this->request->end_date->format('d M Y')}")
            ->line("**Working Days:** {$this->request->working_days}")
            ->action('Track Application', url("/leave-management/my-applications/{$this->request->id}"))
            ->line("You will be notified once a decision has been made.")
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $lt = $this->request->leaveType;
        return [
            'type'           => 'leave_submitted_employee',
            'request_number' => $this->request->request_number,
            'request_id'     => $this->request->id,
            'leave_type'     => $lt?->name,
            'start_date'     => $this->request->start_date?->format('Y-m-d'),
            'end_date'       => $this->request->end_date?->format('Y-m-d'),
            'message'        => "Your {$lt?->name} leave application ({$this->request->request_number}) is pending approval.",
            'url'            => "/leave-management/my-applications/{$this->request->id}",
        ];
    }
}
