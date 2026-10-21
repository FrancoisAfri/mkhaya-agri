<?php

namespace App\Shared\Messaging;

use App\Core\SMS\Models\SmsConfiguration;
use App\Core\SMS\Services\SmsService;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Sending to a person's phone on the organisation's own channels.
 *
 * SMS goes through the company's SMS configuration and its monthly SMS allowance (an urgent alert
 * is never held back by the allowance). WhatsApp goes through the company's own WhatsApp Business
 * number on the Meta Cloud API, as the approved template the company named — the only kind of
 * message a business may start a conversation with. The number is the person's HR cell number.
 */
class CompanyMessaging
{
    /** @return array{sms: array, whatsapp: array} what is set up, without secrets */
    public function status(int $companyId): array
    {
        $sms = SmsConfiguration::withoutGlobalScopes()->where('company_id', $companyId)->first();
        $wa = CompanyChannel::for('whatsapp', $companyId);
        $s = $wa?->settings() ?? [];

        return [
            'sms' => ['configured' => (bool) ($sms && $sms->sms_username && ! empty($sms->getAttributes()['sms_password'] ?? null)), 'active' => (bool) $sms?->is_active,
                'provider' => $sms?->provider ?? 'bulksms', 'username' => $sms?->sms_username],
            'whatsapp' => ['configured' => ! empty($s['token']) && ! empty($s['phone_number_id']), 'active' => (bool) $wa?->is_active,
                'phone_number_id' => $s['phone_number_id'] ?? null, 'business_account_id' => $s['business_account_id'] ?? null,
                'alert_template' => $wa?->alert_template, 'template_language' => $wa?->template_language ?? 'en',
                'last_test_at' => $wa?->last_test_at?->toIso8601String(), 'last_test_status' => $wa?->last_test_status],
        ];
    }

    public function usable(int $companyId, string $channel): bool
    {
        $s = $this->status($companyId)[$channel] ?? null;

        return $s && $s['configured'] && $s['active'] && ($channel !== 'whatsapp' || ! empty($s['alert_template']));
    }

    /** The person's mobile in international form, from their HR record. */
    public function mobile(User $user): ?string
    {
        return $user->routeNotificationForWhatsApp(null);
    }

    /** @return string|null an error, or null when sent */
    public function sms(User $user, string $text, bool $urgent): ?string
    {
        $to = $this->mobile($user);
        if (! $to) {
            return 'No cell number on the person\'s HR record.';
        }

        return SmsService::send($to, mb_strimwidth($text, 0, 300), $urgent ? 'alert' : 'notification') ? null : 'The SMS provider did not accept it (check the SMS setup and allowance).';
    }

    /**
     * @param  list<string>  $params  the template's {{1}}, {{2}}… values
     * @return string|null an error, or null when sent
     */
    public function whatsapp(int $companyId, User $user, array $params): ?string
    {
        $wa = CompanyChannel::for('whatsapp', $companyId);
        $s = $wa?->settings() ?? [];
        if (! $wa?->is_active || empty($s['token']) || empty($s['phone_number_id']) || ! $wa->alert_template) {
            return 'WhatsApp is not set up for this organisation.';
        }
        $to = $this->mobile($user);
        if (! $to) {
            return 'No cell number on the person\'s HR record.';
        }
        $base = rtrim($s['base_url'] ?? 'https://graph.facebook.com/v18.0', '/');
        $r = Http::timeout(10)->withToken($s['token'])->post("{$base}/{$s['phone_number_id']}/messages", [
            'messaging_product' => 'whatsapp', 'to' => ltrim($to, '+'), 'type' => 'template',
            'template' => ['name' => $wa->alert_template, 'language' => ['code' => $wa->template_language ?: 'en'],
                'components' => [['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => mb_strimwidth((string) $v, 0, 900)], $params)]]],
        ]);

        return $r->successful() ? null : 'WhatsApp refused it: '.mb_strimwidth((string) ($r->json('error.message') ?? $r->status()), 0, 200);
    }
}
