<?php

namespace App\Modules\Disciplinary\Notifications;

use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisciplinaryHearingScheduledNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly DisciplinaryHearing $hearing,
        public readonly string $recipientRole, // 'employee' | 'chairperson' | 'hr_rep' | 'investigator' | 'attendee'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ref       = $this->hearing->hearing_number;
        $caseRef   = $this->hearing->case?->case_number ?? '—';
        $employee  = $this->hearing->case?->employeeName() ?? '—';
        $date      = $this->hearing->scheduled_at?->format('d M Y \a\t H:i') ?? '—';
        $venue     = $this->hearing->venue ?? ucfirst($this->hearing->venue_type ?? 'TBC');
        $type      = ucfirst($this->hearing->hearing_type ?? 'formal');

        $roleMessages = [
            'employee'     => "A {$type} disciplinary hearing has been scheduled for your case **{$caseRef}**.",
            'chairperson'  => "You have been appointed as **Chairperson** for hearing **{$ref}** (Case {$caseRef}).",
            'hr_rep'       => "You have been assigned as **HR Representative** for hearing **{$ref}** (Case {$caseRef}).",
            'investigator' => "A hearing has been scheduled for the case you investigated: **{$caseRef}**.",
            'attendee'     => "You are invited to attend disciplinary hearing **{$ref}** for case **{$caseRef}**.",
        ];

        $intro = $roleMessages[$this->recipientRole] ?? "A disciplinary hearing has been scheduled: **{$ref}**.";

        return (new MailMessage)
            ->greeting('Hello,')
            ->subject("Hearing Scheduled — {$ref}")
            ->line($intro)
            ->line("**Employee:** {$employee}")
            ->line("**Date & Time:** {$date}")
            ->line("**Venue:** {$venue}")
            ->line("**Type:** {$type}")
            ->action('View Hearing', route('disciplinary.hearings.show', $this->hearing))
            ->line('Please review the hearing details and prepare accordingly.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => 'disciplinary_hearing_scheduled',
            'message'        => "Hearing {$this->hearing->hearing_number} scheduled for case {$this->hearing->case?->case_number} on " . ($this->hearing->scheduled_at?->format('d M Y H:i') ?? '—') . '.',
            'hearing_id'     => $this->hearing->id,
            'hearing_number' => $this->hearing->hearing_number,
            'case_id'        => $this->hearing->case_id,
            'case_number'    => $this->hearing->case?->case_number,
            'scheduled_at'   => $this->hearing->scheduled_at?->toIso8601String(),
            'recipient_role' => $this->recipientRole,
            'url'            => route('disciplinary.hearings.show', $this->hearing),
        ];
    }
}
