<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly LeaveRequest $request) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $lt = $this->request->leaveType;

        return (new MailMessage)
            ->subject("Leave Approved — {$this->request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your {$lt?->name} leave request has been **approved**.")
            ->line("**Period:** {$this->request->start_date->format('d M Y')} to {$this->request->end_date->format('d M Y')}")
            ->line("**Working Days:** {$this->request->working_days}")
            ->action('View Request', url("/leave-management/my-applications/{$this->request->id}"))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $lt = $this->request->leaveType;
        return [
            'type'           => 'leave_approved',
            'request_number' => $this->request->request_number,
            'request_id'     => $this->request->id,
            'message'        => "{$lt?->name} leave ({$this->request->request_number}) has been approved.",
            'url'            => "/leave-management/my-applications/{$this->request->id}",
        ];
    }
}
