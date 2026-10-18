<?php

namespace App\Modules\PerformanceAppraisal\Notifications;

use App\Modules\PerformanceAppraisal\Models\PaRecognition;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent synchronously (no ShouldQueue) so the in-app notification always lands;
 * the fault-tolerant mail channel skips email failures without blocking.
 */
class PaRecognitionNotification extends Notification
{
    public function __construct(
        public readonly PaRecognition $recognition,
        public readonly bool $isMention = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $giver = $this->giverName();
        $badge = $this->recognition->badge ? ' (' . ucwords(str_replace('_', ' ', $this->recognition->badge)) . ')' : '';

        $subject = $this->isMention
            ? "{$giver} mentioned you in a recognition"
            : "🎉 You received recognition from {$giver}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello,')
            ->line($subject . $badge)
            ->line('"' . $this->recognition->message . '"')
            ->action('View Recognition Feed', route('performance.recognition.index'));
    }

    public function toArray(object $notifiable): array
    {
        $giver = $this->giverName();

        return [
            'type'           => $this->isMention ? 'pa_recognition_mention' : 'pa_recognition_received',
            'message'        => $this->isMention
                ? "{$giver} mentioned you in a recognition."
                : "{$giver} recognised you: \"" . \Illuminate\Support\Str::limit($this->recognition->message, 80) . '"',
            'recognition_id' => $this->recognition->id,
            'url'            => route('performance.recognition.index'),
        ];
    }

    private function giverName(): string
    {
        $person = $this->recognition->giver?->person;
        if ($person) {
            return trim("{$person->first_name} {$person->surname}") ?: ($this->recognition->giver->email ?? 'A colleague');
        }
        return $this->recognition->giver?->email ?? 'A colleague';
    }
}
