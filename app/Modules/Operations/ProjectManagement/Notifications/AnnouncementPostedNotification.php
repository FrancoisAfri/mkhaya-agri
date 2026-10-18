<?php

namespace App\Modules\Operations\ProjectManagement\Notifications;

use App\Modules\Operations\ProjectManagement\Models\Announcement;
use App\Modules\Operations\ProjectManagement\Notifications\Channels\SmsChannel;
use App\Modules\Operations\ProjectManagement\Notifications\Channels\WhatsAppChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notice was posted to a project this person is on.
 *
 * The channel list is decided by severity, not by preference: an urgent notice is the one case
 * where the cost and intrusion of a text message is justified, and everything else stays on email
 * and in-app so the urgent one still means something when it arrives.
 */
class AnnouncementPostedNotification extends Notification
{
    public function __construct(public readonly Announcement $announcement)
    {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail'];

        if ($this->announcement->severity === 'urgent') {
            // Both, and they are not duplicates: WhatsApp is the one people on site read, and it
            // falls through to SMS itself when a tenant has no WhatsApp account. Listing SMS as
            // well would double-send for every tenant that has both.
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subjectLine())
            ->greeting(__('project_management.announcements.mail_greeting'))
            ->line($this->announcement->title)
            ->line($this->announcement->body);

        if ($this->announcement->requires_ack) {
            $mail->line(__('project_management.announcements.mail_ack_required'));
        }

        return $mail->action(
            __('project_management.announcements.mail_action'),
            route('project-management.announcements.index'),
        );
    }

    /**
     * Deliberately short.
     *
     * SMS is charged per 160-character segment, so the notice's title carries the message and the
     * body stays in the email — a three-segment text of prose costs three times as much to say the
     * same thing, and is read on a phone screen either way.
     */
    public function toSms(object $notifiable): string
    {
        $project = $this->announcement->project?->code;

        return trim(
            __('project_management.announcements.sms_prefix')
            . ($project ? ' ' . $project : '')
            . ': ' . $this->announcement->title
        );
    }

    /**
     * The WhatsApp template and its variables.
     *
     * A template rather than a sentence because the Business API refuses free-form text outside a
     * 24-hour window — prose would work against a sandbox and fail on every real send. `fallback`
     * is what goes out by SMS when WhatsApp is unavailable.
     *
     * @return array{template:string, variables:list<string>, fallback:string}
     */
    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template'  => config('services.whatsapp.templates.announcement', 'project_announcement'),
            'variables' => [
                (string) ($this->announcement->project?->code ?? '—'),
                $this->announcement->title,
            ],
            'fallback'  => $this->toSms($notifiable),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'            => 'pm_announcement',
            'message'         => $this->subjectLine(),
            'announcement_id' => $this->announcement->uuid,
            'severity'        => $this->announcement->severity,
            'requires_ack'    => $this->announcement->requires_ack,
            'url'             => route('project-management.announcements.index'),
        ];
    }

    /**
     * The subject, spelled out per severity rather than assembled from a variable.
     *
     * A key built by concatenation is one no search finds and the translation test cannot check —
     * which is the whole reason that test exists.
     */
    private function subjectLine(): string
    {
        return match ($this->announcement->severity) {
            'urgent'    => __('project_management.announcements.subject_urgent', ['title' => $this->announcement->title]),
            'important' => __('project_management.announcements.subject_important', ['title' => $this->announcement->title]),
            default     => __('project_management.announcements.subject_info', ['title' => $this->announcement->title]),
        };
    }
}
