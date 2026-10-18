<?php

namespace App\Modules\HR\Leave\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayWishNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly bool  $birthdayLeaveEnabled,
        private readonly float $birthdayLeaveDays = 0,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $person = $notifiable->person;
        $name   = $person ? $person->first_name : $notifiable->email;

        $mail = (new MailMessage)
            ->subject('Happy Birthday! 🎂')
            ->greeting("Happy Birthday, {$name}!")
            ->line('Wishing you a wonderful birthday filled with joy and celebration.')
            ->line('From all of us at Mkhaya HR — we hope your day is as special as you are.');

        if ($this->birthdayLeaveEnabled && $this->birthdayLeaveDays > 0) {
            $days = number_format($this->birthdayLeaveDays, $this->birthdayLeaveDays == floor($this->birthdayLeaveDays) ? 0 : 1);
            $mail->line("Your **{$days} day(s) of birthday leave** have already been loaded onto your balance — enjoy the time off!")
                 ->action('View My Leave Balance', url('/leave-management/my-dashboard'));
        }

        return $mail->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $message = 'Wishing you a very happy birthday! 🎂';

        if ($this->birthdayLeaveEnabled && $this->birthdayLeaveDays > 0) {
            $days    = number_format($this->birthdayLeaveDays, $this->birthdayLeaveDays == floor($this->birthdayLeaveDays) ? 0 : 1);
            $message .= " Your {$days} day(s) of birthday leave are ready to use.";
        }

        return [
            'type'    => 'birthday_wish',
            'message' => $message,
            'url'     => '/leave-management/my-dashboard',
        ];
    }
}
