<?php

namespace App\Modules\HR\LMS\Notifications;

use App\Modules\HR\LMS\Models\LmsComplianceAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrainingEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected LmsComplianceAssignment $assignment,
        protected int $daysOverdue,
        protected string $employeeName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $course   = $this->assignment->course;
        $dueDate  = $this->assignment->due_date?->format('d M Y');

        return (new MailMessage)
            ->subject("Escalation: {$this->employeeName} has overdue mandatory training")
            ->greeting("Hello {$notifiable->name},")
            ->line("This is an escalation notice. The employee **{$this->employeeName}** has not completed their mandatory training.")
            ->line("Course: **\"{$course?->title}\"**")
            ->line("Due Date: **{$dueDate}** ({$this->daysOverdue} day(s) overdue)")
            ->action('View Compliance Dashboard', url(route('lms.admin.compliance')))
            ->line('Please follow up with this employee or waive the training if appropriate.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'training_escalation',
            'assignment_id' => $this->assignment->id,
            'course_id'     => $this->assignment->course_id,
            'course_title'  => $this->assignment->course?->title,
            'employee_name' => $this->employeeName,
            'days_overdue'  => $this->daysOverdue,
            'due_date'      => $this->assignment->due_date?->toDateString(),
            'message'       => "{$this->employeeName} is {$this->daysOverdue} day(s) overdue on \"{$this->assignment->course?->title}\".",
        ];
    }
}
