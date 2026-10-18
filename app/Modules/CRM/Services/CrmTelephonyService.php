<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-agnostic outbound calling. The CRM dials through whichever VoIP
 * provider is integrated AND active on the Integrations hub — SIP/Zoiper,
 * Twilio Voice, Vonage or Telnyx. SIP softphones use a click-to-call sip: URI;
 * API providers place the call server-side when fully configured, otherwise
 * fall back to a tel: link. Always returns a structured result, never throws.
 */
class CrmTelephonyService
{
    public function activeIntegration(): ?CrmIntegration
    {
        return CrmIntegration::active('voip');
    }

    public function isConfigured(): bool
    {
        return $this->activeIntegration() !== null;
    }

    /**
     * Place / prepare a call to a number using the active provider.
     *
     * @return array{success:bool, mode?:string, uri?:string, provider?:string, label?:string, message:string}
     */
    public function dial(string $to): array
    {
        $integration = $this->activeIntegration();
        if (! $integration) {
            return ['success' => false, 'message' => 'No VoIP provider is active. Add one under CRM → Integrations and enable it.'];
        }

        $config = $integration->configArray();
        $digits = $this->normalise($to);
        if ($digits === '') {
            return ['success' => false, 'message' => 'No phone number to dial.'];
        }

        return match ($integration->provider) {
            'sip'          => $this->dialSip($digits, $config),
            'twilio_voice' => $this->dialTwilio($digits, $config),
            'vonage'       => $this->dialApiOrTel('Vonage', $digits),
            'telnyx'       => $this->dialApiOrTel('Telnyx', $digits),
            default        => ['success' => true, 'mode' => 'tel', 'uri' => "tel:+{$digits}", 'message' => 'Opening dialer.'],
        };
    }

    /** SIP softphone (e.g. Zoiper): launch a sip: URI the OS hands to the phone. */
    private function dialSip(string $digits, array $config): array
    {
        $domain = $config['sip_domain'] ?? '';
        $uri = $domain !== '' ? "sip:{$digits}@{$domain}" : "tel:+{$digits}";

        return [
            'success'  => true,
            'mode'     => 'sip',
            'uri'      => $uri,
            'provider' => 'sip',
            'label'    => 'SIP / Zoiper',
            'message'  => 'Launching your softphone…',
        ];
    }

    /**
     * Twilio Voice: if a caller ID + agent number are configured, place a real
     * click-to-call (Twilio rings the agent, then bridges to the customer).
     * Otherwise fall back to a tel: link.
     */
    private function dialTwilio(string $digits, array $config): array
    {
        $sid   = $config['account_sid'] ?? '';
        $token = $config['auth_token'] ?? '';
        $from  = $config['from_number'] ?? '';
        $agent = $config['agent_number'] ?? '';

        if ($sid === '' || $token === '' || $from === '' || $agent === '') {
            return [
                'success' => true, 'mode' => 'tel', 'uri' => "tel:+{$digits}", 'provider' => 'twilio_voice',
                'label' => 'Twilio', 'message' => 'Opening dialer (add an agent number to Twilio for automatic calling).',
            ];
        }

        try {
            $twiml = '<Response><Dial callerId="'.$from.'">+'.$digits.'</Dial></Response>';
            $res = Http::asForm()->withBasicAuth($sid, $token)->timeout(20)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Calls.json", [
                    'To'    => $agent,           // ring the agent first…
                    'From'  => $from,
                    'Twiml' => $twiml,           // …then bridge to the customer
                ]);

            return $res->successful()
                ? ['success' => true, 'mode' => 'api', 'provider' => 'twilio_voice', 'label' => 'Twilio', 'message' => 'Calling — your phone will ring, then connect to the customer.']
                : ['success' => false, 'message' => 'Twilio rejected the call ('.$res->status().').'];
        } catch (\Throwable $e) {
            Log::warning('Twilio call failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Call failed: '.$e->getMessage()];
        }
    }

    private function dialApiOrTel(string $label, string $digits): array
    {
        // Vonage/Telnyx programmatic calling needs an app + webhook; until that is
        // wired, hand off to the OS dialer so the number still dials.
        return [
            'success' => true, 'mode' => 'tel', 'uri' => "tel:+{$digits}",
            'label' => $label, 'message' => "Opening dialer via {$label}.",
        ];
    }

    /** Keep a leading + as digits; strip everything else. */
    private function normalise(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
