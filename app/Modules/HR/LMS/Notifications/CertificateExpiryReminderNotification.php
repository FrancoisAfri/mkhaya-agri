<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\EmployeeCertification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateExpiryReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected EmployeeCertification $employeeCert,
        protected int $daysUntilExpiry,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cert   = $this->employeeCert->certification;
        $course = $cert?->course;
        $expiry = $this->employeeCert->expires_at?->format('d M Y');
        $isExpired = $this->daysUntilExpiry <= 0;

        $mail = (new MailMessage)
            ->subject($isExpired
                ? "Your \"{$course?->title}\" certificate has expired"
                : "Your \"{$course?->title}\" certificate expires in {$this->daysUntilExpiry} day(s)"
            )
            ->greeting("Hello {$notifiable->name},");

        if ($isExpired) {
            $mail->line("Your **\"{$course?->title}\"** certificate expired on **{$expiry}**.")
                 ->line('Please complete the refresher training to renew your certification.');
        } else {
            $mail->line("Your **\"{$course?->title}\"** certificate will expire on **{$expiry}**.")
                 ->line("You have **{$this->daysUntilExpiry} day(s)** remaining before your certification lapses.");
        }

        if ($cert?->refresher_course_id) {
            $mail->action('Take Refresher Training', url(route('lms.courses.show', $cert->refresher_course_id)));
        }

        return $mail->line('Contact your HR team if you have questions.');
    }

    public function toArray(object $notifiable): array
    {
        $cert = $this->employeeCert->certification;
        return [
            'type'                    => 'certificate_expiry_reminder',
            'employee_certification_id' => $this->employeeCert->id,
            'certification_id'        => $this->employeeCert->certification_id,
            'course_title'            => $cert?->course?->title,
            'expires_at'              => $this->employeeCert->expires_at?->toDateString(),
            'days_until_expiry'       => $this->daysUntilExpiry,
            'is_expired'              => $this->daysUntilExpiry <= 0,
            'has_refresher'           => !is_null($cert?->refresher_course_id),
            'message'                 => $this->daysUntilExpiry <= 0
                ? "Your \"{$cert?->course?->title}\" certificate has expired."
                : "Your \"{$cert?->course?->title}\" certificate expires in {$this->daysUntilExpiry} day(s).",
        ];
    }
}
