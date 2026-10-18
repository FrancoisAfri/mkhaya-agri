<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaProbationReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaProbationStartedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly PaProbationReview $review) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type  = ucwords(str_replace('_', '-', $this->review->probation_type));
        $start = $this->review->start_date?->format('d M Y');
        $end   = $this->review->end_date?->format('d M Y');

        return (new MailMessage)
            ->greeting('Hello,')
            ->subject("Probation Period Started ({$type})")
            ->line("Your {$type} probation period has been created.")
            ->line("Period: {$start} – {$end}")
            ->action('View My Dashboard', route('performance.my-dashboard'));
    }

    public function toArray(object $notifiable): array
    {
        $type  = ucwords(str_replace('_', '-', $this->review->probation_type));
        $start = $this->review->start_date?->format('d M Y');
        $end   = $this->review->end_date?->format('d M Y');

        return [
            'type'      => 'pa_probation_started',
            'message'   => "Your {$type} probation period has started ({$start} – {$end}).",
            'review_id' => $this->review->id,
            'url'       => route('performance.my-dashboard'),
        ];
    }
}
