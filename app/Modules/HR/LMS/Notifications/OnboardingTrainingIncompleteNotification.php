<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsOnboardingTrainingAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class OnboardingTrainingIncompleteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Collection $assignments,
        protected string     $employeeName,
        protected bool       $isHrAlert = false
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count    = $this->assignments->count();
        $blocking = $this->assignments->where('blocks_completion', true)->count();

        $mail = (new MailMessage)
            ->subject($this->isHrAlert
                ? "HR Alert: {$this->employeeName} has {$count} incomplete onboarding training(s)"
                : "Action Required: Complete your onboarding training"
            );

        if ($this->isHrAlert) {
            $mail->greeting("Hello,")
                 ->line("**{$this->employeeName}** has **{$count}** incomplete onboarding training assignment(s).")
                 ->line($blocking
                    ? "⚠️ **{$blocking}** of these are mandatory and will **block onboarding completion**."
                    : 'None of these are blocking onboarding completion currently.'
                 );
        } else {
            $greetingName = $notifiable->name ?? $this->employeeName;
            $mail->greeting("Hello {$greetingName},")
                 ->line("You have **{$count}** onboarding training course(s) that are due or overdue.")
                 ->line($blocking
                    ? "**{$blocking}** of these must be completed before your onboarding can be finalised."
                    : 'Please complete these at your earliest convenience.'
                 );
        }

        foreach ($this->assignments->take(5) as $a) {
            $status  = strtoupper($a->status);
            $due     = $a->due_date?->format('d M Y') ?? '—';
            $mail->line("• **{$a->course?->title}** — {$status} (due {$due})");
        }

        if ($count > 5) {
            $mail->line("…and " . ($count - 5) . " more.");
        }

        return $mail->action('View Training', url(route('lms.my-learning')))
                    ->line('If you have any questions, please contact HR.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => $this->isHrAlert ? 'onboarding_training_hr_alert' : 'onboarding_training_incomplete',
            'employee_name' => $this->employeeName,
            'count'         => $this->assignments->count(),
            'blocking_count'=> $this->assignments->where('blocks_completion', true)->count(),
            'assignment_ids'=> $this->assignments->pluck('id')->all(),
        ];
    }
}
