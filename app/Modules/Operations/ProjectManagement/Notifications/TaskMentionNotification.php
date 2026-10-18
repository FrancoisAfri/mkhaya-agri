<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskComment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Somebody named you in a comment.
 *
 * Being tagged already subscribed a person to the task, which meant they would hear about whatever
 * happened next — and never about the thing that named them. A question asked of somebody directly
 * and delivered nowhere is the most reliable way to not get an answer.
 */
class TaskMentionNotification extends Notification
{
    public function __construct(
        public readonly Task $task,
        public readonly TaskComment $comment,
        public readonly ?string $author = null,
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

    /** Enough to know whether it needs answering now, without reproducing the thread in an email. */
    private function excerpt(): string
    {
        return Str::limit(trim((string) $this->comment->body), 300);
    }

    private function url(): string
    {
        return route('project-management.board', [
            $this->task->project?->uuid,
            'task' => $this->task->uuid,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('project_management.tasks.mention_mail_subject', ['task' => $this->label()]))
            ->greeting(__('project_management.tasks.mention_mail_greeting'))
            ->line(__('project_management.tasks.mention_mail_intro', [
                'name' => $this->author ?: __('project_management.tasks.someone'),
                'task' => $this->label(),
            ]))
            ->line('> ' . $this->excerpt())
            ->action(__('project_management.tasks.mention_mail_action'), $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pm_task_mention',
            'message' => __('project_management.tasks.mention_inapp', [
                'name' => $this->author ?: __('project_management.tasks.someone'),
                'task' => $this->label(),
            ]),
            'excerpt' => $this->excerpt(),
            'task'    => $this->task->uuid,
            'project' => $this->task->project?->uuid,
            'url'     => $this->url(),
        ];
    }
}
