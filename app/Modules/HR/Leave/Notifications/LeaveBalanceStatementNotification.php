<?php

namespace App\Modules\HR\Leave\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The employee's periodic leave balance statement. */
class LeaveBalanceStatementNotification extends Notification
{
    use Queueable;

    /** @param array<int, array{leave_type: string, balance: float}> $balances */
    public function __construct(
        public readonly string $employeeName,
        public readonly array $balances,
        public readonly float $hoursPerDay = 8.0,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject('Your leave balances — ' . now()->format('F Y'))
            ->greeting('Hello ' . $this->employeeName)
            ->line('Here are your leave balances as at ' . now()->format('j F Y') . ':');

        foreach ($this->balances as $row) {
            $days = $this->hoursPerDay > 0 ? round($row['balance'] / $this->hoursPerDay, 2) : 0;
            $mail->line('• ' . $row['leave_type'] . ': ' . $days . ' day(s)');
        }

        return $mail
            ->action('View my balances', url('/leave-management/balances'))
            ->line('Balances update as leave is approved and as new leave accrues.');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'     => 'leave_balance_statement',
            'balances' => $this->balances,
            'message'  => 'Your leave balance statement for ' . now()->format('F Y') . ' is ready.',
            'url'      => '/leave-management/balances',
        ];
    }
}
