<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * You have been put on a task.
 *
 * Work was handed to people silently: an assignment showed up on a board they had no reason to be
 * looking at that morning, and the first anybody knew about it was being asked why it had not been
 * started. Being given work is the clearest case there is for telling somebody.
 *
 * Email and in-app, no SMS. Reserving the text channel for what is genuinely urgent is what stops
 * people muting it — and an assignment is information, not an interruption.
 */
class TaskAssignedNotification extends Notification
{
    public function __construct(
        public readonly Task $task,
        /** Who handed it over. Named, because "you have a new task" invites exactly that question. */
        public readonly ?string $assignedBy = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function label(): string
    {
        return trim(($this->task->code ? $this->task->code . ' · ' : '') . $this->task->name);
    }

    private function url(): string
    {
        // Straight to the drawer, open, on the board the task lives on — not a project page the
        // recipient then has to search through.
        return route('project-management.board', [
            $this->task->project?->uuid,
            'task' => $this->task->uuid,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('project_management.tasks.assigned_mail_subject', ['task' => $this->label()]))
            ->greeting(__('project_management.tasks.assigned_mail_greeting'))
            ->line(__('project_management.tasks.assigned_mail_intro', [
                'task' => $this->label(),
                'name' => $this->assignedBy ?: __('project_management.tasks.someone'),
            ]));

        // Only when there is one. A due date is the single most useful thing in this message, and
        // inventing "no date" wording for the common undated case would just add noise.
        if ($this->task->planned_end) {
            $mail->line(__('project_management.tasks.assigned_mail_due', [
                'date' => $this->task->planned_end->toFormattedDateString(),
            ]));
        }

        return $mail->action(__('project_management.tasks.assigned_mail_action'), $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pm_task_assigned',
            'message' => __('project_management.tasks.assigned_inapp', [
                'task' => $this->label(),
                'name' => $this->assignedBy ?: __('project_management.tasks.someone'),
            ]),
            'task'    => $this->task->uuid,
            'project' => $this->task->project?->uuid,
            'url'     => $this->url(),
        ];
    }
}
