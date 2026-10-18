<?php

namespace App\Modules\HR\Leave\Notifications;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayLeaveAllocatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly float  $days,
        private readonly Carbon $birthday,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $person     = $notifiable->person;
        $name       = $person ? $person->first_name : $notifiable->email;
        $days       = number_format($this->days, $this->days == floor($this->days) ? 0 : 1);
        $birthdayFmt = $this->birthday->format('d F');

        return (new MailMessage)
            ->subject('Your Birthday Leave Has Been Loaded 🎁')
            ->greeting("Hello {$name},")
            ->line("Your birthday is coming up on **{$birthdayFmt}** — we hope you have something special planned!")
            ->line("As a gift from us, **{$days} day(s) of Birthday Leave** have been added to your leave balance ahead of your big day.")
            ->line('You can use them on your birthday or at any time. Check your balance below.')
            ->action('View My Leave Balance', url('/leave-management/my-dashboard'))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $days        = number_format($this->days, $this->days == floor($this->days) ? 0 : 1);
        $birthdayFmt = $this->birthday->format('d F');

        return [
            'type'    => 'birthday_leave_allocated',
            'message' => "Your {$days} day(s) of Birthday Leave have been loaded ahead of your birthday on {$birthdayFmt}.",
            'url'     => '/leave-management/my-dashboard',
        ];
    }
}
