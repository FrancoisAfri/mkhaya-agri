<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody else marked your task done.
 *
 * A card can be dragged into Done by anybody who may edit the project, and until now the person
 * answerable for that work found out by happening to look at the board. That is the case worth
 * telling somebody about: work signed off on their behalf, by a name they can query.
 *
 * Email and in-app only. Nothing here is urgent enough to justify a text message — a completion is
 * information, not an interruption — and reserving SMS for the things that are is what stops people
 * muting the channel entirely.
 */
class TaskCompletedNotification extends Notification
{
    public function __construct(
        public readonly Task $task,
        /** Who moved it. Named, because "your task was completed" invites the question. */
        public readonly ?string $completedBy = null,
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
        // Straight to the drawer, open, on the board the task lives on — not to a project page the
        // recipient then has to search. The board reads ?task= and opens it.
        return route('project-management.board', [
            $this->task->project?->uuid,
            'task' => $this->task->uuid,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('project_management.tasks.done_mail_subject', ['task' => $this->label()]))
            ->greeting(__('project_management.tasks.done_mail_greeting'))
            ->line(__('project_management.tasks.done_mail_intro', [
                'task' => $this->label(),
                'name' => $this->completedBy ?: __('project_management.tasks.someone'),
            ]))
            ->line(__('project_management.tasks.done_mail_hint'))
            ->action(__('project_management.tasks.done_mail_action'), $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pm_task_completed',
            'message' => __('project_management.tasks.done_inapp', [
                'task' => $this->label(),
                'name' => $this->completedBy ?: __('project_management.tasks.someone'),
            ]),
            'task'    => $this->task->uuid,
            'project' => $this->task->project?->uuid,
            'url'     => $this->url(),
        ];
    }
}
