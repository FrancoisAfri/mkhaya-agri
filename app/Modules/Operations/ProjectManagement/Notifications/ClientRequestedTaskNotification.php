<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Task;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The client has asked for something.
 *
 * A request raised on a board nobody refreshed is the same as the email it replaced, so the people
 * who run the job are told rather than left to notice. It goes to the project's own members, not to
 * everybody on the tenant — a notice that reaches people with no connection to the job is one they
 * learn to filter, and then they filter the ones that mattered too.
 */
class ClientRequestedTaskNotification extends Notification
{
    public function __construct(
        public readonly Task $task,
        public readonly ?string $client = null,
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
        return route('project-management.board', [
            $this->task->project?->uuid,
            'task' => $this->task->uuid,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('project_management.portal.request_mail_subject', [
                'project' => $this->task->project?->code ?: $this->task->project?->name,
            ]))
            ->greeting(__('project_management.portal.request_mail_greeting'))
            ->line(__('project_management.portal.request_mail_intro', [
                'name'    => $this->client ?: __('project_management.tasks.someone'),
                'project' => $this->task->project?->name,
            ]))
            ->line('**' . $this->label() . '**');

        if ($this->task->description) {
            $mail->line($this->task->description);
        }

        return $mail->action(__('project_management.portal.request_mail_action'), $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pm_client_requested_task',
            'message' => __('project_management.portal.request_inapp', [
                'name' => $this->client ?: __('project_management.tasks.someone'),
                'task' => $this->label(),
            ]),
            'task'    => $this->task->uuid,
            'project' => $this->task->project?->uuid,
            'url'     => $this->url(),
        ];
    }
}
