<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrainingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected LmsComplianceAssignment $assignment,
        protected int $daysRelative  // positive = days until due, negative = days overdue
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $course   = $this->assignment->course;
        $dueDate  = $this->assignment->due_date?->format('d M Y');
        $isOverdue = $this->daysRelative < 0;

        $mail = (new MailMessage)
            ->subject($isOverdue
                ? "Overdue: \"{$course?->title}\" training is past due"
                : "Reminder: Complete \"{$course?->title}\" training"
            )
            ->greeting("Hello {$notifiable->name},");

        if ($isOverdue) {
            $overdueDays = abs($this->daysRelative);
            $mail->line("Your mandatory training **\"{$course?->title}\"** was due on **{$dueDate}** and is now **{$overdueDays} day(s) overdue**.")
                 ->line('Please complete this training immediately to remain compliant.')
                 ->action('Complete Training Now', url(route('lms.courses.show', $this->assignment->course_id)));
        } else {
            $mail->line("You have mandatory training due in **{$this->daysRelative} day(s)**.")
                 ->line("Course: **\"{$course?->title}\"**")
                 ->line("Due Date: **{$dueDate}**")
                 ->action('Start Training', url(route('lms.courses.show', $this->assignment->course_id)));
        }

        return $mail->line('If you have any questions, please contact your HR team.');
    }

    public function toArray(object $notifiable): array
    {
        $isOverdue = $this->daysRelative < 0;
        return [
            'type'          => 'training_reminder',
            'assignment_id' => $this->assignment->id,
            'course_id'     => $this->assignment->course_id,
            'course_title'  => $this->assignment->course?->title,
            'due_date'      => $this->assignment->due_date?->toDateString(),
            'days_relative' => $this->daysRelative,
            'is_overdue'    => $isOverdue,
            'message'       => $isOverdue
                ? "Your \"{$this->assignment->course?->title}\" training is " . abs($this->daysRelative) . ' day(s) overdue.'
                : "Your \"{$this->assignment->course?->title}\" training is due in {$this->daysRelative} day(s).",
        ];
    }
}
