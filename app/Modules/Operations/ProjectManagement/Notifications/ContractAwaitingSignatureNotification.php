<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody nominated this person to sign a project contract.
 *
 * Sending used to default silently to whoever clicked, which is right often enough to have hidden
 * the problem: the person who signs for a practice is frequently a director, and nothing told them
 * a contract had gone out in their name. Now they are chosen, and told — in the module they
 * already work in, with a link to the contract itself. Signing happens inside Project Management,
 * so there is no separate portal to send them to.
 */
class ContractAwaitingSignatureNotification extends Notification
{
    public function __construct(
        public readonly ProjectContract $contract,
        public readonly Project $project,
        public readonly string $contractorName,
    ) {
    }

    /**
     * In-app and email, never SMS.
     *
     * A signature is not time-critical the way an approval above a threshold is, and this module
     * pays per text message.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('project_management.contracts.mail_signature_subject', [
                'number' => $this->contract->contract_number,
            ]))
            ->greeting(__('project_management.approvals.mail_greeting'))
            ->line(__('project_management.contracts.mail_signature_line', [
                'number'     => $this->contract->contract_number,
                'contractor' => $this->contractorName,
                'project'    => $this->project->name ?: '—',
            ]))
            ->action(__('project_management.contracts.mail_signature_action'), $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'pm_contract_awaiting_signature',
            'message'     => __('project_management.contracts.inapp_signature', [
                'number'     => $this->contract->contract_number,
                'contractor' => $this->contractorName,
            ]),
            'contract_id' => $this->contract->uuid,
            'project_id'  => $this->project->uuid,
            'url'         => $this->path(),
        ];
    }

    /**
     * Where the mail button goes: absolute, because it is followed from an email client.
     */
    private function url(): string
    {
        return route('project-management.contracts.show', $this->contract->uuid);
    }

    /**
     * Where the in-app notification goes: a path, not a URL.
     *
     * An absolute link is baked into the notification row at the moment it is written, from
     * APP_URL — so one raised by a queued job or a console command carries whatever APP_URL said
     * then. On this installation that is `http://localhost` with no port, and every such link
     * refused to connect. A path is resolved against whatever origin the person is actually on,
     * and survives the app moving domain.
     */
    private function path(): string
    {
        return route('project-management.contracts.show', $this->contract->uuid, absolute: false);
    }
}
