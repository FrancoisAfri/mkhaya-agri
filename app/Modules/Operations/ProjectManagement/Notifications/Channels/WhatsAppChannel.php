<?php

namespace App\Modules\Operations\ProjectManagement\Notifications\Channels;

use App\Core\SMS\Services\SmsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp, where a tenant has a Business API account (§19).
 *
 * ── Why it falls back to SMS rather than failing ────────────────────────────
 *
 * WhatsApp is the channel people on site actually read, and it is also the one most likely to be
 * unconfigured. A notification that simply does not arrive because a tenant has no WhatsApp account
 * is worse than one that arrives as a text message, so an unconfigured or failed send falls through
 * to `SmsService`. The message was worth sending or it was not; the transport is an implementation
 * detail of that decision.
 *
 * ── Why it is template-shaped ───────────────────────────────────────────────
 *
 * The WhatsApp Business API refuses free-form text outside a 24-hour customer-service window, so a
 * business-initiated message has to be a pre-approved template. `toWhatsApp()` therefore returns a
 * template name and its variables, not a sentence — a channel that sent prose would work in testing
 * against a sandbox and fail on every real send.
 */
class WhatsAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $number = $this->numberFor($notifiable);

        if (! $number) {
            return;
        }

        /** @var array{template:string, variables:list<string>, fallback:string} $message */
        $message = $notification->toWhatsApp($notifiable);

        if (! $this->isConfigured()) {
            $this->fallBackToSms($number, $message);

            return;
        }

        try {
            $config = config('services.whatsapp');

            $response = Http::withToken($config['token'])
                ->timeout(10)
                ->post(rtrim($config['base_url'], '/') . '/' . $config['phone_number_id'] . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'to'                => $this->normalise($number),
                    'type'              => 'template',
                    'template'          => [
                        'name'       => $message['template'],
                        'language'   => ['code' => $config['language'] ?? 'en'],
                        'components' => [[
                            'type'       => 'body',
                            'parameters' => array_map(
                                fn ($v) => ['type' => 'text', 'text' => (string) $v],
                                $message['variables'] ?? [],
                            ),
                        ]],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('PM WhatsApp send failed; falling back to SMS.', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);

                $this->fallBackToSms($number, $message);
            }
        } catch (\Throwable $e) {
            Log::warning('PM WhatsApp send threw; falling back to SMS.', ['error' => $e->getMessage()]);

            $this->fallBackToSms($number, $message);
        }
    }

    private function isConfigured(): bool
    {
        $config = config('services.whatsapp', []);

        return ! empty($config['token']) && ! empty($config['phone_number_id']);
    }

    /** The plain sentence the template renders to, sent the ordinary way. */
    private function fallBackToSms(string $number, array $message): void
    {
        if (empty($message['fallback']) || ! SmsService::isConfigured()) {
            return;
        }

        SmsService::send($number, mb_substr($message['fallback'], 0, 300), 'notification');
    }

    /**
     * Digits only, with the country code.
     *
     * South African numbers are stored as `0821234567` more often than not, and the API rejects a
     * leading zero — so the one substitution that matters is done here rather than in eleven
     * notification classes.
     */
    private function normalise(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);

        return str_starts_with($digits, '0')
            ? (string) config('services.whatsapp.country_code', '27') . substr($digits, 1)
            : $digits;
    }

    private function numberFor(object $notifiable): ?string
    {
        if (method_exists($notifiable, 'routeNotificationForWhatsApp')) {
            return $notifiable->routeNotificationForWhatsApp() ?: null;
        }

        $person = $notifiable->person ?? null;

        return $person?->cell_number ?: null;
    }
}
