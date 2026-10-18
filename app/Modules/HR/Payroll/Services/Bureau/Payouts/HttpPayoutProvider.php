<?php

namespace App\Modules\HR\Payroll\Services\Bureau\Payouts;

use App\Modules\HR\Payroll\Models\Bureau\BureauPaymentIntegration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shared HTTP behaviour for credential-driven payout providers. The client's
 * API key is sent as a bearer token to their configured base URL. NOTE: the
 * exact request/response shape differs per provider — align $payload and the
 * response parsing with the provider's API spec before going live.
 */
abstract class HttpPayoutProvider implements DisbursementProvider
{
    /** Provider-specific paths. */
    abstract protected function healthPath(): string;
    abstract protected function batchPath(): string;

    public function testConnection(BureauPaymentIntegration $integration): array
    {
        if (! $integration->hasCredentials()) {
            return ['success' => false, 'message' => 'Base URL and API key are required.'];
        }

        try {
            $res = $this->client($integration)->get($this->url($integration, $this->healthPath()));

            return ['success' => true, 'message' => 'Reached '.$this->provider().' (HTTP '.$res->status().').'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not reach '.$this->provider().': '.$e->getMessage()];
        }
    }

    public function createBatch(BureauPaymentIntegration $integration, array $instructions): array
    {
        if (! $integration->hasCredentials()) {
            throw new RuntimeException($this->provider().' is not fully configured.');
        }

        $payload = [
            'sandbox'  => $integration->sandbox,
            'payments' => array_map(fn ($i) => [
                'beneficiary_name' => $i['name'],
                'branch_code'      => $i['branch'],
                'account_number'   => $i['account'],
                'amount'           => $i['amount'],
                'reference'        => $i['reference'],
            ], $instructions),
        ];

        $res = $this->client($integration)->post($this->url($integration, $this->batchPath()), $payload);
        if ($res->failed()) {
            throw new RuntimeException($this->provider().' payout failed: '.$res->body());
        }
        $body = $res->json() ?? [];

        return [
            'reference' => $body['id'] ?? $body['reference'] ?? $body['batchId'] ?? null,
            'status'    => $body['status'] ?? 'submitted',
            'raw'       => $body,
        ];
    }

    public function batchStatus(BureauPaymentIntegration $integration, string $reference): array
    {
        $res = $this->client($integration)->get($this->url($integration, $this->batchPath().'/'.$reference));
        if ($res->failed()) {
            throw new \RuntimeException($this->provider().' status lookup failed: '.$res->body());
        }
        $body = $res->json() ?? [];

        return ['status' => $body['status'] ?? 'unknown', 'raw' => $body];
    }

    protected function client(BureauPaymentIntegration $integration)
    {
        return Http::withToken($integration->apiKey())->acceptJson()->timeout(20);
    }

    protected function url(BureauPaymentIntegration $integration, string $path): string
    {
        return rtrim($integration->base_url, '/').$path;
    }
}
