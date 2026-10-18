<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeavePayoutRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeavePayoutRequestedNotification extends Notification
{
    use Queueable;

    public int $tries    = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly LeavePayoutRecord $payout,
        public readonly string $employeeName,
        public readonly string $leaveTypeName,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $days = round((float) $this->payout->hours_paid_out / 8, 2);

        return (new MailMessage)
            ->subject("Leave Payout Request — {$this->employeeName}")
            ->greeting("Hello,")
            ->line("{$this->employeeName} has submitted a leave payout request.")
            ->line("**Leave Type:** {$this->leaveTypeName}")
            ->line("**Days Requested:** {$days}")
            ->line("**Date:** " . now()->format('d M Y'))
            ->line("The balance has been deducted pending your review. You can update the status below.")
            ->action('Review Payout Requests', url('/leave-management/payouts'))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $days = round((float) $this->payout->hours_paid_out / 8, 2);

        return [
            'type'         => 'leave_payout_requested',
            'payout_id'    => $this->payout->id,
            'employee'     => $this->employeeName,
            'leave_type'   => $this->leaveTypeName,
            'days'         => $days,
            'message'      => "{$this->employeeName} requested a {$days}d {$this->leaveTypeName} payout.",
            'url'          => '/leave-management/payouts',
        ];
    }
}
