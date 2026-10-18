<?php

namespace App\Modules\Disciplinary\Notifications;

use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisciplinarySuspensionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DisciplinarySuspension $suspension,
        public readonly string $recipientRole, // 'employee' | 'hr' | 'manager'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type      = ucfirst(str_replace('_', ' ', $this->suspension->suspension_type));
        $start     = $this->suspension->start_date?->format('d M Y') ?? '—';
        $end       = $this->suspension->end_date?->format('d M Y') ?? 'Until further notice';
        $days      = $this->suspension->suspension_days ? "{$this->suspension->suspension_days} day(s)" : 'Indefinite';
        $caseRef   = $this->suspension->case?->case_number ?? '—';

        if ($this->recipientRole === 'employee') {
            return (new MailMessage)
                ->greeting('Dear Employee,')
                ->subject("Notice of {$type} Suspension")
                ->line("You have been placed on a **{$type} suspension** effective **{$start}**.")
                ->line("**Suspension Period:** {$start} to {$end} ({$days})")
                ->line("**Case Reference:** {$caseRef}")
                ->line("**Reason:** {$this->suspension->reason}")
                ->line($this->suspension->suspension_type === 'unpaid'
                    ? 'Please note this is an **unpaid** suspension. Your salary will be adjusted accordingly.'
                    : 'Your salary will continue during this suspension period.')
                ->line('Please contact HR if you have any questions regarding this matter.')
                ->line('You are expected to return on **' . ($this->suspension->expected_return_date?->format('d M Y') ?? $end) . '** unless notified otherwise.');
        }

        $employeeName = $this->suspension->employee?->person?->full_name
            ?? $this->suspension->employee?->email
            ?? 'Employee';

        $label = $this->recipientRole === 'manager' ? 'direct report' : 'an employee';

        return (new MailMessage)
            ->greeting('Hello,')
            ->subject("Suspension Issued — {$caseRef}")
            ->line("A **{$type} suspension** has been issued for your {$label}: **{$employeeName}**.")
            ->line("**Case Reference:** {$caseRef}")
            ->line("**Period:** {$start} to {$end} ({$days})")
            ->line("**Reason:** {$this->suspension->reason}")
            ->action('View Suspension', route('disciplinary.progressive-discipline.suspensions.index'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'             => 'disciplinary_suspension',
            'message'          => "Suspension issued: {$this->suspension->suspension_type} from {$this->suspension->start_date?->format('d M Y')}.",
            'suspension_id'    => $this->suspension->id,
            'suspension_type'  => $this->suspension->suspension_type,
            'case_id'          => $this->suspension->case_id,
            'case_number'      => $this->suspension->case?->case_number,
            'start_date'       => $this->suspension->start_date?->toDateString(),
            'end_date'         => $this->suspension->end_date?->toDateString(),
            'recipient_role'   => $this->recipientRole,
            'url'              => route('disciplinary.progressive-discipline.suspensions.index'),
        ];
    }
}
