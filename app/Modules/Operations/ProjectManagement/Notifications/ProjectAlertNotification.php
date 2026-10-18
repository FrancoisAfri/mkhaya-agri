<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One message per project per run, however many things are wrong with it (§27).
 *
 * Batched deliberately: three separate emails about the same project arriving together is how
 * people build a filter rule, and then they miss the fourth one that mattered.
 */
class ProjectAlertNotification extends Notification
{
    /** @param list<array{kind:string,title:string,detail:string}> $alerts */
    public function __construct(
        public readonly Project $project,
        public readonly array $alerts,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('project_management.alerts.mail_subject', [
                'code'  => $this->project->code,
                'count' => count($this->alerts),
            ]))
            ->greeting(__('project_management.alerts.mail_greeting'))
            ->line(__('project_management.alerts.mail_intro', ['name' => $this->project->name]));

        foreach ($this->alerts as $alert) {
            $mail->line('**' . $alert['title'] . '** — ' . $alert['detail']);
        }

        return $mail->action(
            __('project_management.alerts.mail_action'),
            route('project-management.projects.show', $this->project->uuid),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pm_project_alert',
            'message' => __('project_management.alerts.inapp', [
                'code'  => $this->project->code,
                'count' => count($this->alerts),
            ]),
            'project' => $this->project->uuid,
            'alerts'  => array_column($this->alerts, 'kind'),
            'url'     => route('project-management.projects.show', $this->project->uuid),
        ];
    }
}
