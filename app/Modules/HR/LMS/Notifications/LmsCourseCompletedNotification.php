<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LmsCourseCompletedNotification extends Notification implements ShouldQueue
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
        $score  = $this->enrollment->score;

        $mail = (new MailMessage)
            ->subject("Course Completed: \"{$course->title}\"")
            ->greeting("Well done, {$notifiable->name}!")
            ->line("You have successfully completed **{$course->title}**.");

        if ($score !== null) {
            $mail->line("Your score: **{$score}%**");
        }

        return $mail
            ->action('View Course', url("/lms/courses/{$course->uuid}"))
            ->line('Your progress has been recorded.')
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $course = $this->enrollment->course;

        return [
            'type'         => 'lms_course_completed',
            'course_id'    => $course->id,
            'course_uuid'  => $course->uuid,
            'course_title' => $course->title,
            'score'        => $this->enrollment->score,
            'completed_at' => $this->enrollment->completed_at?->toDateTimeString(),
            'message'      => "You completed \"{$course->title}\"." . ($this->enrollment->score !== null ? " Score: {$this->enrollment->score}%." : ''),
            'url'          => "/lms/courses/{$course->uuid}",
        ];
    }
}
