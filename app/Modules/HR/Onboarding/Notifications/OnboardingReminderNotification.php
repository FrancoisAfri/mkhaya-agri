<?php

namespace App\Modules\HR\Onboarding\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One reminder covering everything of a given kind a person owes.
 *
 * Deliberately a single notification carrying a list, rather than one per task. Somebody who owes
 * eleven onboarding items should receive one message listing eleven; eleven separate messages teach
 * people to filter the sender, which costs you every future reminder as well as this one.
 */
class OnboardingReminderNotification extends Notification
{
    use Queueable;

    /**
     * @param  'overdue_tasks'|'upcoming_starts'|'probation_reviews'  $kind
     * @param  list<array{title: string, due: string|null}>  $items
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $count,
        public readonly array $items,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello,')
            ->line($this->intro());

        foreach ($this->items as $item) {
            $mail->line('• ' . $item['title'] . ($item['due'] ? ' — ' . $item['due'] : ''));
        }

        // Only when the list was truncated, so the message does not imply there is more when the
        // list is complete.
        if ($this->count > count($this->items)) {
            $mail->line(sprintf('…and %d more.', $this->count - count($this->items)));
        }

        return $mail->action('Open onboarding', url('/onboarding'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'count' => $this->count,
            'items' => $this->items,
            'subject' => $this->subject(),
            'url' => '/onboarding',
        ];
    }

    private function subject(): string
    {
        return match ($this->kind) {
            'overdue_tasks' => sprintf('%d onboarding task(s) overdue', $this->count),
            'upcoming_starts' => sprintf('%d new hire(s) starting soon', $this->count),
            'probation_reviews' => sprintf('%d probation review(s) due', $this->count),
            default => 'Onboarding reminder',
        };
    }

    private function intro(): string
    {
        return match ($this->kind) {
            'overdue_tasks' => 'These onboarding tasks are assigned to you and past their due date:',
            'upcoming_starts' => 'These new hires start soon — the pre-arrival work has to be done before day one:',
            'probation_reviews' => 'These probation reviews fall due shortly. A period that lapses unreviewed generally confirms employment by default:',
            default => 'You have onboarding items needing attention:',
        };
    }
}
