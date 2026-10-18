<?php

namespace App\Modules\Disciplinary\Notifications;

use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisciplinarySuspensionEndedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DisciplinarySuspension $suspension,
        public readonly string $returnDate,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $caseRef      = $this->suspension->case?->case_number ?? '—';
        $returnFormatted = \Illuminate\Support\Carbon::parse($this->returnDate)->format('d M Y');
        $employeeName = $this->suspension->employee?->person?->full_name
            ?? $this->suspension->employee?->email
            ?? 'Employee';

        $isManager = $notifiable->id !== $this->suspension->employee_id;

        if ($isManager) {
            return (new MailMessage)
                ->greeting('Hello,')
                ->subject("Suspension Ended — {$employeeName} Returning")
                ->line("The suspension for your direct report **{$employeeName}** (Case {$caseRef}) has ended.")
                ->line("**Return Date:** {$returnFormatted}")
                ->line('Their system access has been restored.')
                ->action('View Suspensions', route('disciplinary.progressive-discipline.suspensions.index'));
        }

        return (new MailMessage)
            ->greeting('Dear Employee,')
            ->subject('Your Suspension Has Ended')
            ->line("Your suspension in relation to case **{$caseRef}** has been officially ended.")
            ->line("**Your return date:** {$returnFormatted}")
            ->line('Your system access has been restored. Please report to your manager upon return.')
            ->line('If you have any questions, please contact HR.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'disciplinary_suspension_ended',
            'message'        => "Suspension ended. Return date: " . \Illuminate\Support\Carbon::parse($this->returnDate)->format('d M Y') . '.',
            'suspension_id'  => $this->suspension->id,
            'case_id'        => $this->suspension->case_id,
            'case_number'    => $this->suspension->case?->case_number,
            'return_date'    => $this->returnDate,
            'url'            => route('disciplinary.progressive-discipline.suspensions.index'),
        ];
    }
}
