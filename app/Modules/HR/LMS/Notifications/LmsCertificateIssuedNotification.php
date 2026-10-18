<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LmsCertificateIssuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LmsCertificate $certificate) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $course = $this->certificate->enrollment->course;

        $mail = (new MailMessage)
            ->subject("Certificate Issued: \"{$course->title}\"")
            ->greeting("Congratulations, {$notifiable->name}!")
            ->line("Your certificate for **{$course->title}** has been issued.")
            ->line("Certificate number: **{$this->certificate->certificate_number}**");

        if ($this->certificate->expires_at) {
            $mail->line("This certificate expires on **{$this->certificate->expires_at->format('d M Y')}**.");
        }

        return $mail
            ->action('View My Certificates', url('/lms/my-learning'))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $course = $this->certificate->enrollment->course;

        return [
            'type'               => 'lms_certificate_issued',
            'certificate_id'     => $this->certificate->id,
            'certificate_uuid'   => $this->certificate->uuid,
            'certificate_number' => $this->certificate->certificate_number,
            'course_id'          => $course->id,
            'course_uuid'        => $course->uuid,
            'course_title'       => $course->title,
            'issued_at'          => $this->certificate->issued_at?->toDateTimeString(),
            'expires_at'         => $this->certificate->expires_at?->toDateTimeString(),
            'message'            => "Certificate issued for \"{$course->title}\". Number: {$this->certificate->certificate_number}.",
            'url'                => '/lms/my-learning',
        ];
    }
}
