<?php

namespace App\Modules\HR\Recruitment\Notifications;

use App\Modules\HR\Recruitment\Models\Vacancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InternalJobOpportunityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Vacancy $vacancy)
    {
        $this->onQueue('notifications');
    }

    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = route('recruitment.vacancies.show', $this->vacancy);

        return (new MailMessage)
            ->subject("New Internal Opportunity: {$this->vacancy->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("An exciting internal opportunity has been posted that you may be interested in.")
            ->line("**Position:** {$this->vacancy->title}")
            ->line("**Department:** " . ($this->vacancy->department ?? 'Not specified'))
            ->line("**Type:** {$this->vacancy->job_type->label()}")
            ->when($this->vacancy->internal_deadline, fn (MailMessage $mail) =>
                $mail->line("**Internal Deadline:** {$this->vacancy->internal_deadline->format('d M Y')}")
            )
            ->action('View Opportunity', $url)
            ->line('This opportunity is available to internal employees. Apply before the deadline to be considered.')
            ->salutation('Regards, HR Team');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'vacancy_id'      => $this->vacancy->id,
            'vacancy_number'  => $this->vacancy->vacancy_number,
            'title'           => $this->vacancy->title,
            'department'      => $this->vacancy->department,
            'application_url' => route('recruitment.vacancies.show', $this->vacancy),
        ];
    }
}
