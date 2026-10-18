<?php

namespace App\Modules\Disciplinary\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisciplinaryEscalationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $escalationType,   // 'overdue_investigation' | 'overdue_hearing' | 'policy_violation' | 'dismissal_recommended' | 'new_case'
        public readonly string $subject,          // human-readable subject
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly ?string $caseNumber = null,
        public readonly ?int    $subjectId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting('Hello,')
            ->subject($this->subject)
            ->line($this->body);

        if ($this->caseNumber) {
            $mail->line("**Case Reference:** {$this->caseNumber}");
        }

        if ($this->url) {
            $mail->action('View Details', $this->url);
        }

        $mail->line('This is an automated alert from the Disciplinary Management System.');

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'           => $this->escalationType,
            'message'        => $this->body,
            'subject'        => $this->subject,
            'case_number'    => $this->caseNumber,
            'subject_id'     => $this->subjectId,
            'url'            => $this->url,
        ];
    }
}
