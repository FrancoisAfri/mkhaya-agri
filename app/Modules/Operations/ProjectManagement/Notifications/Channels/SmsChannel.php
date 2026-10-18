<?php

namespace App\Modules\Operations\ProjectManagement\Notifications\Channels;

use App\Core\SMS\Services\SmsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Text messages, for the notifications where email is not fast enough.
 *
 * The people this module is for are frequently on a site rather than at a desk, and an urgent
 * approval or a shutdown notice that waits until somebody next opens their inbox has already
 * failed. Everything else stays on email and in-app: SMS is charged per segment and metered
 * against the tenant's monthly allowance, so a channel used for routine traffic is one that gets
 * switched off — and then unavailable for the message that mattered.
 *
 * Three deliberate properties:
 *
 *  - **Opt-in per notification.** A notification joins this channel by declaring `toSms()`. There is
 *    no fallback that quietly texts everything.
 *  - **Silent when unconfigured.** A tenant with no SMS provider is not an error; the notification
 *    still arrives by its other channels.
 *  - **Never fatal.** A failed send is logged, not thrown. Losing an approval because a gateway was
 *    down would be a worse outcome than losing the text message.
 */
class SmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms') || ! SmsService::isConfigured()) {
            return;
        }

        $number = $this->numberFor($notifiable);

        if (! $number) {
            return;
        }

        $message = (string) $notification->toSms($notifiable);

        if (trim($message) === '') {
            return;
        }

        try {
            // Purpose 'notification' so the platform's per-tenant SMS quota applies. Nothing here
            // bypasses it: a tenant out of credit sees the send skipped, which is the correct
            // outcome for a message somebody would otherwise be billed for.
            SmsService::send($number, mb_substr($message, 0, 300), 'notification');
        } catch (\Throwable $e) {
            Log::warning('PM SMS notification failed.', [
                'notification' => $notification::class,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * The mobile number, from wherever this platform keeps it.
     *
     * There is no `users.phone`; the number lives on the person record, so a user with no linked
     * HR person simply has no SMS address and the channel does nothing.
     */
    private function numberFor(object $notifiable): ?string
    {
        if (method_exists($notifiable, 'routeNotificationForSms')) {
            return $notifiable->routeNotificationForSms() ?: null;
        }

        $person = $notifiable->person ?? null;

        // `cell_number` first: on `hr_people` that is the mobile, where `phone_number` is often a
        // landline, and texting a landline is a message nobody receives.
        $number = $person->cell_number ?: ($person->phone_number ?: null);

        return $number ? (string) $number : null;
    }
}
