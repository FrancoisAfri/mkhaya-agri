<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What this person owes today, and what they already owed.
 *
 * One message a day covering every task, rather than one per task. Somebody who has let a dozen
 * things slip is exactly the person who would receive a dozen separate emails, and a dozen emails
 * is how a mail rule gets written — after which the thirteenth, the one that mattered, is filed
 * unread with the rest.
 *
 * Due and overdue are kept apart inside the one message because they ask for different things: due
 * today is a plan for the day, overdue is a conversation somebody needs to have.
 */
class TasksDueNotification extends Notification
{
    /**
     * @param  list<array{code: ?string, name: string, project: ?string, due: ?string, days: int}>  $dueToday
     * @param  list<array{code: ?string, name: string, project: ?string, due: ?string, days: int}>  $overdue
     */
    public function __construct(
        public readonly array $dueToday,
        public readonly array $overdue,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function line(array $task): string
    {
        return trim(($task['code'] ? $task['code'] . ' · ' : '') . $task['name'])
            . ($task['project'] ? ' (' . $task['project'] . ')' : '');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('project_management.reminders.mail_subject', [
                'due'     => count($this->dueToday),
                'overdue' => count($this->overdue),
            ]))
            ->greeting(__('project_management.reminders.mail_greeting'));

        if ($this->dueToday !== []) {
            $mail->line('**' . __('project_management.reminders.due_today') . '**');

            foreach ($this->dueToday as $task) {
                $mail->line('• ' . $this->line($task));
            }
        }

        if ($this->overdue !== []) {
            $mail->line('**' . __('project_management.reminders.overdue') . '**');

            foreach ($this->overdue as $task) {
                // How late, not just that it is late: "3 days" and "3 months" are different problems.
                $mail->line('• ' . $this->line($task) . ' — ' . __('project_management.reminders.days_late', [
                    'days' => $task['days'],
                ]));
            }
        }

        return $mail->action(__('project_management.reminders.mail_action'), route('project-management.field'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'    => 'pm_tasks_due',
            'message' => __('project_management.reminders.inapp', [
                'due'     => count($this->dueToday),
                'overdue' => count($this->overdue),
            ]),
            'due'     => count($this->dueToday),
            'overdue' => count($this->overdue),
            'url'     => route('project-management.field'),
        ];
    }
}
