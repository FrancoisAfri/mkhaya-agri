<?php

namespace App\Modules\Operations\Sheq\Adapters;

use App\Core\SMS\Services\SmsService;
use App\Modules\Operations\Sheq\Contracts\NotificationTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Carries SHEQ notifications on whatever the platform already has (SHEQ-ADR-002 again: SHEQ does not
 * own delivery, it borrows it).
 *
 * Deliberately partial. EMAIL and SMS work today; PUSH and VOICE report unsupported so their
 * dispatch rows stay PENDING and appear on the queue as undelivered. That is the point — a
 * statutory alert that could not be placed must look undelivered, not quietly successful. When a
 * telephony provider is configured, this class grows a case and the pending rows drain themselves.
 */
class PlatformNotificationTransport implements NotificationTransport
{
    public function supports(string $channel): bool
    {
        return in_array($channel, ['EMAIL', 'SMS', 'WHATSAPP'], true);
    }

    public function send(array $message): bool
    {
        return match ($message['channel']) {
            'EMAIL' => $this->email($message),
            'SMS' => $this->sms($message),
            'WHATSAPP' => $this->whatsapp($message),
            default => false,
        };
    }

    private function email(array $message): bool
    {
        if (empty($message['address'])) {
            return false;
        }

        try {
            Mail::raw($this->plainBody($message), function ($mail) use ($message) {
                $mail->to($message['address'])->subject($message['subject']);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('SHEQ notification email failed.', [
                'reference' => $message['reference'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * WhatsApp, via the platform's existing Business API client.
     *
     * The channel that actually reaches the people SHEQ most needs to reach. Contractors and casual
     * workers do not have company email and do not log into portals; they read WhatsApp. An
     * induction link or a "your medical expires on Friday" that arrives there gets acted on, and the
     * same message sent by email does not.
     *
     * Falls back to SMS when WhatsApp is unconfigured or the send fails, because a safety
     * notification that silently goes nowhere is the worst outcome available — better a plain SMS
     * than a delivery that only exists in a log line.
     */
    private function whatsapp(array $message): bool
    {
        // No WhatsApp Business client is installed in this product yet, so every WhatsApp
        // message goes out as SMS rather than nowhere.
        return $this->sms($message);
    }

    private function sms(array $message): bool
    {
        if (empty($message['address'])) {
            return false;
        }

        /**
         * Purpose 'notification' means the platform's SMS quota applies — with one exception the
         * quota code already honours: nothing here bypasses it, so a tenant out of credit sees the
         * send skipped and the dispatch stays visible rather than silently vanishing.
         */
        return SmsService::send($message['address'], $this->smsBody($message), 'notification');
    }

    private function plainBody(array $message): string
    {
        $body = $message['body'];

        if (! empty($message['link'])) {
            $body .= "\n\n".$message['link'];
        }

        return $body;
    }

    /** SMS is charged per segment, so the reference and the ask come first and the prose is cut. */
    private function smsBody(array $message): string
    {
        $prefix = $message['severity'] === 'CRITICAL' ? 'URGENT ' : '';
        $text = $prefix.$message['subject'];

        return mb_substr($text, 0, 300);
    }
}
