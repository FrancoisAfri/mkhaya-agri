<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmIntegration;
use App\Modules\CRM\Models\CrmSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp marketing messages via the Meta (WhatsApp Business) Cloud API.
 *
 * Credentials are read from the in-app CRM settings first (configured in the
 * Settings page, encrypted at rest), then config('services.whatsapp.*'), then
 * env. Degrades gracefully (['available' => false]) when unconfigured so the
 * campaign UI works without a live WhatsApp connection.
 */
class CrmWhatsAppService
{
    private string $token;
    private string $phoneNumberId;
    private string $baseUrl;

    public function __construct()
    {
        // Prefer the Integrations hub (meta_whatsapp), then in-app CRM settings,
        // then config/services, then env — so any of them "just works".
        $hub = Schema::hasTable('crm_integrations') ? CrmIntegration::configFor('meta_whatsapp') : [];

        $this->token         = (string) (($hub['token'] ?? '') ?: CrmSetting::get('whatsapp_token') ?: config('services.whatsapp.token') ?: env('WHATSAPP_TOKEN', ''));
        $this->phoneNumberId = (string) (($hub['phone_number_id'] ?? '') ?: CrmSetting::get('whatsapp_phone_number_id') ?: config('services.whatsapp.phone_number_id') ?: env('WHATSAPP_PHONE_NUMBER_ID', ''));
        $this->baseUrl       = rtrim((string) (($hub['base_url'] ?? '') ?: CrmSetting::get('whatsapp_base_url') ?: config('services.whatsapp.base_url') ?: 'https://graph.facebook.com/v18.0'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->phoneNumberId !== '';
    }

    /**
     * Send a free-form text message (only valid inside a 24h customer-service
     * window). For marketing/outbound use sendTemplate().
     *
     * @return array{available:bool, id?:string, error?:string}
     */
    public function sendText(string $to, string $body): array
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalise($to),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ]);
    }

    /**
     * Send an approved marketing template message.
     *
     * @param  array<int,string>  $bodyParams  positional {{1}}, {{2}} … values
     * @return array{available:bool, id?:string, error?:string}
     */
    public function sendTemplate(string $to, string $template, string $language = 'en_US', array $bodyParams = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalise($to),
            'type'              => 'template',
            'template'          => [
                'name'     => $template,
                'language' => ['code' => $language],
            ],
        ];

        if ($bodyParams) {
            $payload['template']['components'] = [[
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $bodyParams),
            ]];
        }

        return $this->post($payload);
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function post(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['available' => false, 'error' => 'WhatsApp is not configured.'];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(30)
                ->post($this->baseUrl . '/' . $this->phoneNumberId . '/messages', $payload);

            if (! $response->successful()) {
                $err = $response->json('error.message') ?? ('HTTP ' . $response->status());
                Log::warning('WhatsApp send failed', ['status' => $response->status(), 'body' => $response->body()]);
                return ['available' => true, 'error' => $err];
            }

            return ['available' => true, 'id' => $response->json('messages.0.id')];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp send exception', ['error' => $e->getMessage()]);
            return ['available' => true, 'error' => $e->getMessage()];
        }
    }

    /** Strip everything but digits (E.164 without the +). */
    private function normalise(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? $phone;
    }
}
