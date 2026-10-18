<?php

namespace App\Modules\HR\Hr\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a WhatsApp text message via Meta Cloud API.
 *
 * Required .env keys:
 *   WHATSAPP_ACCESS_TOKEN   – permanent token from Meta Business Suite
 *   WHATSAPP_PHONE_NUMBER_ID – the sending phone number ID
 *
 * The notifiable model must implement routeNotificationForWhatsApp()
 * returning the recipient's phone in E.164 format (e.g. +27821234567).
 */
class WhatsAppChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        $token   = config('services.whatsapp.access_token');
        $phoneId = config('services.whatsapp.phone_number_id');

        if (!$token || !$phoneId) {
            Log::warning('WhatsApp channel: WHATSAPP_ACCESS_TOKEN or WHATSAPP_PHONE_NUMBER_ID not set.');
            return;
        }

        $to = $notifiable->routeNotificationFor('whatsapp', $notification);
        if (!$to) {
            return;
        }

        if (!method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $body = $notification->toWhatsApp($notifiable);
        if (empty($body)) {
            return;
        }

        $response = Http::withToken($token)
            ->post("https://graph.facebook.com/v19.0/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $body, 'preview_url' => false],
            ]);

        if (!$response->successful()) {
            Log::warning('WhatsApp send failed', [
                'to'     => $to,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        }
    }
}
