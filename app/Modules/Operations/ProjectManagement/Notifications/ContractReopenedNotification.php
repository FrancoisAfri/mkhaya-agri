<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A contract everybody had signed is being changed.
 *
 * This goes to every party who signed, and it is not optional or batched. Somebody agreed to a
 * document and that document is being reopened; finding out later — or not at all — is how a
 * person ends up held to wording they never read. It carries the reason for exactly that reason.
 *
 * The contract returns to draft, so nobody is being asked to sign anything yet. The signing
 * request follows separately once the new wording is settled.
 */
class ContractReopenedNotification extends Notification
{
    public function __construct(
        public readonly ProjectContract $contract,
        public readonly Project $project,
        public readonly string $reason,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('project_management.contracts.mail_reopened_subject', [
                'number' => $this->contract->contract_number,
            ]))
            ->greeting(__('project_management.approvals.mail_greeting'))
            ->line(__('project_management.contracts.mail_reopened_line', [
                'number'  => $this->contract->contract_number,
                'project' => $this->project->name ?: '—',
            ]))
            ->line(__('project_management.contracts.mail_reopened_reason', ['reason' => $this->reason]))
            // Said plainly: the signature they already gave no longer stands on its own.
            ->line(__('project_management.contracts.mail_reopened_resign'))
            ->action(__('project_management.contracts.mail_signature_action'), $this->url());
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'        => 'pm_contract_reopened',
            'message'     => __('project_management.contracts.inapp_reopened', [
                'number' => $this->contract->contract_number,
            ]),
            'contract_id' => $this->contract->uuid,
            'project_id'  => $this->project->uuid,
            'reason'      => $this->reason,
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
