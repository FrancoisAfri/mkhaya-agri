<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LmsCourseEnrolledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LmsEnrollment $enrollment) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $course = $this->enrollment->course;

        return (new MailMessage)
            ->subject("You've been enrolled in \"{$course->title}\"")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have been enrolled in the course **{$course->title}**.")
            ->when($course->is_mandatory, fn ($m) => $m->line('This is a **mandatory** course. Please complete it as soon as possible.'))
            ->when($course->duration_mins, fn ($m) => $m->line("Estimated duration: {$course->duration_mins} minutes."))
            ->action('Start Learning', url("/lms/courses/{$course->uuid}"))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $course = $this->enrollment->course;

        return [
            'type'        => 'lms_course_enrolled',
            'course_id'   => $course->id,
            'course_uuid' => $course->uuid,
            'course_title'=> $course->title,
            'is_mandatory'=> $course->is_mandatory,
            'message'     => "You have been enrolled in \"{$course->title}\".",
            'url'         => "/lms/courses/{$course->uuid}",
        ];
    }
}
