<?php

namespace App\Modules\HR\Leave\Notifications;

use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveMedicalCertificateRequiredNotification extends Notification
{
    use Queueable;

    public int $tries    = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly LeaveRequest $request,
        public readonly array        $reasons = [],
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Action Required: Medical Certificate — {$this->request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A medical certificate is required for your sick leave request **{$this->request->request_number}**.")
            ->line("**Period:** {$this->request->start_date->format('d M Y')} to {$this->request->end_date->format('d M Y')}");

        if (! empty($this->reasons)) {
            $mail->line("**Reason(s):**");
            foreach ($this->reasons as $reason) {
                $mail->line("- {$reason}");
            }
        }

        return $mail
            ->action('Upload Certificate', url("/leave-management/my-applications/{$this->request->id}"))
            ->line("Please upload a valid medical certificate at your earliest convenience to avoid your request being placed on hold.")
            ->line("BCEA §23: A medical certificate issued by a registered medical practitioner is required for absences of more than two consecutive days, on a Monday or Friday, or when your 8-week rolling total exceeds 6 days.")
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        return [
            'type'           => 'leave_medical_required',
            'request_number' => $this->request->request_number,
            'request_id'     => $this->request->id,
            'reasons'        => $this->reasons,
            'message'        => "Medical certificate required for request {$this->request->request_number}.",
            'url'            => "/leave-management/my-applications/{$this->request->id}",
        ];
    }
}
