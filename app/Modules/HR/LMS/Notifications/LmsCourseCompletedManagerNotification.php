<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LmsCourseCompletedManagerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly LmsEnrollment $enrollment,
        public readonly string        $employeeName,
    ) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $course = $this->enrollment->course;
        $score  = $this->enrollment->score;

        $mail = (new MailMessage)
            ->subject("{$this->employeeName} completed \"{$course->title}\"")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->employeeName}** has completed the course **{$course->title}**.");

        if ($score !== null) {
            $mail->line("Score achieved: **{$score}%**");
        }

        return $mail
            ->action('View LMS Dashboard', url('/lms/admin/dashboard'))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $course = $this->enrollment->course;

        return [
            'type'          => 'lms_course_completed_manager',
            'course_id'     => $course->id,
            'course_uuid'   => $course->uuid,
            'course_title'  => $course->title,
            'employee_name' => $this->employeeName,
            'score'         => $this->enrollment->score,
            'completed_at'  => $this->enrollment->completed_at?->toDateTimeString(),
            'message'       => "{$this->employeeName} completed \"{$course->title}\"." . ($this->enrollment->score !== null ? " Score: {$this->enrollment->score}%." : ''),
            'url'           => '/lms/admin/dashboard',
        ];
    }
}
