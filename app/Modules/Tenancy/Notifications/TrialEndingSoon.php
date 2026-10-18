<?php

namespace App\Modules\Tenancy\Notifications;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells platform admins a client's trial is running out — before it does.
 *
 * The lock-out itself (tenancy:expire-trials → ResolveCompany) used to be the first
 * anyone heard of it: the client phoned in blocked, and Mkhaya had no warning it was
 * coming. This carries the days remaining so the decision (extend or activate) can be
 * made while the client is still working.
 *
 * `$daysLeft` is 0 on the last day and negative once the trial has already lapsed.
 */
class TrialEndingSoon extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Company $company,
        public readonly int $daysLeft,
        public readonly int $userCount = 0,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('tenancy.trial_alert.'.$this->subjectKey(), $this->replacements()))
            ->greeting(__('tenancy.trial_alert.greeting'))
            ->line(__('tenancy.trial_alert.'.$this->bodyKey(), $this->replacements()))
            ->line(__('tenancy.trial_alert.plan_line', [
                'plan' => $this->company->plan,
                'users' => $this->userCount,
                'contact' => $this->company->contact_email ?: '—',
            ]))
            ->action(__('tenancy.trial_alert.action'), route('tenancy.companies.index'));

        return $this->daysLeft <= 0 ? $mail->error() : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'trial_ending',
            'company_id' => $this->company->id,
            'company' => $this->company->name,
            'days_left' => $this->daysLeft,
            'trial_ends_at' => $this->company->trial_ends_at?->toDateString(),
            'severity' => $this->daysLeft <= 0 ? 'critical' : ($this->daysLeft <= 3 ? 'high' : 'medium'),
            'title' => __('tenancy.trial_alert.'.$this->dbTitleKey(), $this->replacements()),
            'message' => __('tenancy.trial_alert.'.$this->bodyKey(), $this->replacements()),
            'url' => '/platform/companies',
        ];
    }

    private function replacements(): array
    {
        return [
            'company' => $this->company->name,
            'days' => max(0, $this->daysLeft),
            'date' => $this->company->trial_ends_at?->toFormattedDateString() ?? '—',
        ];
    }

    private function subjectKey(): string
    {
        return match (true) {
            $this->daysLeft < 0 => 'subject_lapsed',
            $this->daysLeft === 0 => 'subject_today',
            default => 'subject_soon',
        };
    }

    private function bodyKey(): string
    {
        return match (true) {
            $this->daysLeft < 0 => 'body_lapsed',
            $this->daysLeft === 0 => 'body_today',
            default => 'body_soon',
        };
    }

    private function dbTitleKey(): string
    {
        return match (true) {
            $this->daysLeft < 0 => 'db_title_lapsed',
            $this->daysLeft === 0 => 'db_title_today',
            default => 'db_title_soon',
        };
    }
}
