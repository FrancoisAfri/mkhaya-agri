<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRejectedNotification extends Notification
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
            ->subject("Leave Rejected — {$this->request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your {$lt?->name} leave request has been **rejected**.")
            ->line("**Period:** {$this->request->start_date->format('d M Y')} to {$this->request->end_date->format('d M Y')}")
            ->line("**Reason:** " . ($this->request->rejection_reason ?? 'No reason provided'))
            ->line("If you disagree with this decision, please contact HR.")
            ->action('View Request', url("/leave-management/my-applications/{$this->request->id}"))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $lt = $this->request->leaveType;
        return [
            'type'             => 'leave_rejected',
            'request_number'   => $this->request->request_number,
            'request_id'       => $this->request->id,
            'rejection_reason' => $this->request->rejection_reason,
            'message'          => "{$lt?->name} leave ({$this->request->request_number}) was rejected.",
            'url'              => "/leave-management/my-applications/{$this->request->id}",
        ];
    }
}
