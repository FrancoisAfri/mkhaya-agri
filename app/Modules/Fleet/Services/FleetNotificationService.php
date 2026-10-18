<?php

namespace App\Modules\Fleet\Services;

use App\Core\User\Models\User;
use App\Modules\Fleet\Models\FleetNotificationPreference;
use App\Modules\Fleet\Models\FleetPushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class FleetNotificationService
{
    public function dispatch(User $user, string $eventType, array $payload): void
    {
        // Always save to the notification bar regardless of user preferences
        $this->saveInAppNotification($user, $eventType, $payload);

        $enabledChannels = $this->enabledChannels($user->id, $eventType);

        foreach ($enabledChannels as $channel) {
            match ($channel) {
                'email'    => $this->sendEmail($user, $eventType, $payload),
                'sms'      => $this->sendSms($user, $eventType, $payload),
                'whatsapp' => $this->sendWhatsApp($user, $eventType, $payload),
                'push'     => $this->sendWebPush($user, $eventType, $payload),
                'in_app'   => null, // already saved above
                default    => null,
            };
        }
    }

    private function saveInAppNotification(User $user, string $eventType, array $payload): void
    {
        $label = FleetNotificationPreference::EVENTS[$eventType] ?? ucfirst(str_replace('_', ' ', $eventType));

        $user->notifications()->create([
            'id'   => \Illuminate\Support\Str::uuid(),
            'type' => 'App\Modules\Fleet\Notifications\FleetGenericNotification',
            'data' => [
                'type'    => $eventType,
                'title'   => $label,
                'message' => $payload['body'],
                'url'     => $payload['url'] ?? null,
            ],
            'read_at' => null,
        ]);
    }

    private function enabledChannels(int $userId, string $eventType): array
    {
        $saved = FleetNotificationPreference::where('user_id', $userId)
            ->where('event_type', $eventType)
            ->pluck('enabled', 'channel');

        // If no preferences saved, default all channels enabled except sms/whatsapp
        $defaults = ['email' => true, 'push' => true, 'in_app' => true, 'sms' => false, 'whatsapp' => false];

        $result = [];
        foreach (FleetNotificationPreference::CHANNELS as $ch) {
            $enabled = $saved->has($ch) ? (bool) $saved[$ch] : ($defaults[$ch] ?? false);
            if ($enabled) $result[] = $ch;
        }

        return $result;
    }

    private function sendEmail(User $user, string $eventType, array $payload): void
    {
        $label  = FleetNotificationPreference::EVENTS[$eventType] ?? ucfirst(str_replace('_', ' ', $eventType));
        $person = $user->person;
        $name   = trim(($person?->first_name ?? '') . ' ' . ($person?->surname ?? '')) ?: $user->email;

        try {
            Mail::raw(
                "Hi {$name},\n\n{$payload['body']}\n\n" . ($payload['url'] ? "View: {$payload['url']}" : ''),
                fn($m) => $m->to($user->email)->subject("[Fleet] {$label}")
            );
        } catch (\Throwable $e) {
            Log::warning("Fleet email failed for user {$user->id}: " . $e->getMessage());
        }
    }

    private function sendSms(User $user, string $eventType, array $payload): void
    {
        // Twilio / Africa's Talking integration — requires TWILIO_* env vars
        $sid   = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from  = config('services.twilio.from');

        $person = $user->person;
        $phone  = $person?->mobile_number ?? $person?->phone_number ?? null;

        if (!$sid || !$token || !$from || !$phone) return;

        try {
            Http::withBasicAuth($sid, $token)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To'   => $phone,
                    'Body' => "[Fleet] " . substr($payload['body'], 0, 160),
                ]);
        } catch (\Throwable $e) {
            Log::warning("Fleet SMS failed for user {$user->id}: " . $e->getMessage());
        }
    }

    private function sendWhatsApp(User $user, string $eventType, array $payload): void
    {
        // WhatsApp Business API via Meta Cloud API
        $waToken  = config('services.whatsapp.token');
        $phoneId  = config('services.whatsapp.phone_number_id');
        $person   = $user->person;
        $phone    = $person?->mobile_number ?? $person?->phone_number ?? null;

        if (!$waToken || !$phoneId || !$phone) return;

        // Normalise to E.164 format (strip non-digits, ensure +27 prefix for SA)
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '27' . substr($phone, 1);
        }

        try {
            Http::withToken($waToken)
                ->post("https://graph.facebook.com/v19.0/{$phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $phone,
                    'type'              => 'text',
                    'text'              => ['body' => "[Fleet] " . $payload['body']],
                ]);
        } catch (\Throwable $e) {
            Log::warning("Fleet WhatsApp failed for user {$user->id}: " . $e->getMessage());
        }
    }

    private function sendWebPush(User $user, string $eventType, array $payload): void
    {
        $subs = FleetPushSubscription::where('user_id', $user->id)->get();
        if ($subs->isEmpty()) return;

        $privateKey = config('fleet.vapid_private_key');
        $publicKey  = config('fleet.vapid_public_key');
        $subject    = config('fleet.vapid_subject', 'mailto:admin@mkhaya.co.za');

        if (!$privateKey || !$publicKey) return;

        $label = FleetNotificationPreference::EVENTS[$eventType] ?? 'Fleet Alert';

        foreach ($subs as $sub) {
            try {
                $this->pushToEndpoint(
                    $sub->endpoint,
                    $sub->p256dh_key,
                    $sub->auth_key,
                    $publicKey,
                    $privateKey,
                    $subject,
                    json_encode([
                        'title' => $label,
                        'body'  => $payload['body'],
                        'url'   => $payload['url'] ?? '/fleet',
                        'icon'  => '/icons/pwa-192.png',
                    ])
                );
                $sub->update(['last_used_at' => now()]);
            } catch (\Throwable $e) {
                Log::warning("Fleet push failed for sub {$sub->id}: " . $e->getMessage());
                // Remove dead subscriptions (410 Gone)
                if (str_contains($e->getMessage(), '410')) {
                    $sub->delete();
                }
            }
        }
    }

    private function pushToEndpoint(
        string $endpoint,
        string $p256dhKey,
        string $authKey,
        string $vapidPublic,
        string $vapidPrivate,
        string $subject,
        string $payload
    ): void {
        // Minimal VAPID push without third-party package dependency
        // Uses web-push HTTP protocol with JSON body to push service endpoints
        $headers = $this->buildVapidHeaders($endpoint, $vapidPublic, $vapidPrivate, $subject);

        Http::withHeaders($headers)
            ->withBody($payload, 'application/json')
            ->post($endpoint);
    }

    private function buildVapidHeaders(
        string $endpoint,
        string $vapidPublic,
        string $vapidPrivate,
        string $subject
    ): array {
        $parsed   = parse_url($endpoint);
        $audience = $parsed['scheme'] . '://' . $parsed['host'];
        $expiry   = time() + 43200;

        $header  = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims  = base64_encode(json_encode(['aud' => $audience, 'exp' => $expiry, 'sub' => $subject]));
        $signing = $header . '.' . $claims;

        // Sign with ES256 using openssl
        $key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode(base64_decode(strtr($vapidPrivate, '-_', '+/'))), 64, "\n")
            . "-----END EC PRIVATE KEY-----");

        openssl_sign($signing, $sig, $key, OPENSSL_ALGO_SHA256);

        $jwt = $signing . '.' . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');

        return [
            'Authorization' => "vapid t={$jwt}, k={$vapidPublic}",
            'Content-Type'  => 'application/json',
            'TTL'           => '86400',
        ];
    }
}
