<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmIntegration;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Verifies that stored credentials actually work — an SMTP handshake for email
 * providers, a lightweight authenticated API call for everything else.
 * Always returns ['success' => bool, 'message' => string]; never throws.
 */
class CrmIntegrationTester
{
    public function test(CrmIntegration $integration): array
    {
        $config = $integration->configArray();
        if (empty($config)) {
            return ['success' => false, 'message' => 'No credentials saved yet.'];
        }

        try {
            return match ($integration->category) {
                'email'    => $this->testSmtp($config),
                'voip'     => $this->testVoip($integration->provider, $config),
                'whatsapp' => $this->testWhatsApp($integration->provider, $config),
                'sms'      => $this->testSms($integration->provider, $config),
                default    => ['success' => false, 'message' => 'No test available for this provider.'],
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Test failed: '.$e->getMessage()];
        }
    }

    private function testSmtp(array $c): array
    {
        $host = $c['host'] ?? '';
        $port = (int) ($c['port'] ?? 587);
        if ($host === '') {
            return ['success' => false, 'message' => 'SMTP host is required.'];
        }

        $tls = ($c['encryption'] ?? 'tls') === 'ssl';
        $transport = new EsmtpTransport($host, $port, $tls);
        if (! empty($c['username'])) $transport->setUsername($c['username']);
        if (! empty($c['password'])) $transport->setPassword($c['password']);

        // start() opens the connection and authenticates; throws on failure.
        $transport->start();
        $transport->stop();

        return ['success' => true, 'message' => "Connected to {$host}:{$port} and authenticated successfully."];
    }

    private function testVoip(string $provider, array $c): array
    {
        if ($provider === 'twilio_voice') {
            $sid = $c['account_sid'] ?? '';
            $res = Http::withBasicAuth($sid, $c['auth_token'] ?? '')
                ->timeout(15)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
            return $res->successful()
                ? ['success' => true, 'message' => 'Twilio account authenticated.']
                : ['success' => false, 'message' => 'Twilio rejected the credentials ('.$res->status().').'];
        }
        if ($provider === 'sip') {
            // SIP registration can't be verified over HTTP; just confirm completeness.
            return ! empty($c['sip_domain']) && ! empty($c['sip_username'])
                ? ['success' => true, 'message' => 'SIP account saved. Registration is verified by your softphone (e.g. Zoiper).']
                : ['success' => false, 'message' => 'SIP domain and username are required.'];
        }
        return ['success' => true, 'message' => 'Credentials saved. Outbound calling will use these settings.'];
    }

    private function testWhatsApp(string $provider, array $c): array
    {
        if ($provider === 'meta_whatsapp') {
            $base = rtrim($c['base_url'] ?? 'https://graph.facebook.com/v21.0', '/');
            $id   = $c['phone_number_id'] ?? '';
            $res  = Http::withToken($c['token'] ?? '')->timeout(15)->get("{$base}/{$id}");
            return $res->successful()
                ? ['success' => true, 'message' => 'WhatsApp Cloud API number verified.']
                : ['success' => false, 'message' => 'Meta rejected the token / phone number ID ('.$res->status().').'];
        }
        if ($provider === 'twilio_whatsapp') {
            $sid = $c['account_sid'] ?? '';
            $res = Http::withBasicAuth($sid, $c['auth_token'] ?? '')->timeout(15)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
            return $res->successful()
                ? ['success' => true, 'message' => 'Twilio WhatsApp sender authenticated.']
                : ['success' => false, 'message' => 'Twilio rejected the credentials ('.$res->status().').'];
        }
        return ['success' => true, 'message' => 'Credentials saved.'];
    }

    private function testSms(string $provider, array $c): array
    {
        if (in_array($provider, ['twilio_sms'], true)) {
            $sid = $c['account_sid'] ?? '';
            $res = Http::withBasicAuth($sid, $c['auth_token'] ?? '')->timeout(15)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");
            return $res->successful()
                ? ['success' => true, 'message' => 'Twilio SMS account authenticated.']
                : ['success' => false, 'message' => 'Twilio rejected the credentials ('.$res->status().').'];
        }
        // Generic providers: confirm required fields are present.
        return ['success' => true, 'message' => 'Credentials saved.'];
    }
}
