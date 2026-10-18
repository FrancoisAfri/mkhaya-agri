<?php

namespace App\Modules\Operations\Manufacturing\Integrations;

use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * An ERP or device reached through its own API (SYSPRO, Cin7 Core, Sage 200, Acumatica, label
 * printers). Endpoints differ by product, edition and version, so they are settings -- health_path,
 * pull_path, push_paths per event type -- confirmed when the integration is validated, rather than
 * guessed here. Authentication is API key, basic, bearer token or OAuth2 client credentials.
 */
class RestAdapter implements IntegrationAdapter
{
    /** Defaults the vendors document; every one can be overridden in the integration's settings. */
    public const PRESETS = [
        'CIN7_CORE' => ['base_url' => 'https://inventory.dearsystems.com/ExternalApi/v2', 'auth_type' => 'API_KEY',
            'headers' => ['account_id' => 'api-auth-accountid', 'key' => 'api-auth-applicationkey'], 'health_path' => '/me'],
        'ACUMATICA' => ['auth_type' => 'OAUTH2', 'token_path' => '/identity/connect/token', 'health_path' => '/entity/Default/23.200.001/StockItem?$top=1'],
        'SAGE_200' => ['auth_type' => 'OAUTH2', 'health_path' => '/companies'],
        'SYSPRO' => ['auth_type' => 'BASIC', 'health_path' => '/api/health'],
    ];

    public function ping(Integration $i): array
    {
        $path = $this->setting($i, 'health_path');
        if (! $path || ! $i->base_url) {
            return ['ok' => false, 'detail' => 'No base URL or health path is set.'];
        }
        try {
            $r = $this->client($i)->get($path);

            return ['ok' => $r->successful(), 'detail' => "HTTP {$r->status()} from {$path}"];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => class_basename($e).': '.$this->scrub($i, $e->getMessage())];
        }
    }

    public function fetch(Integration $i): array
    {
        $path = $this->setting($i, 'pull_path');
        if (! $path) {
            return [];
        }
        $r = $this->client($i)->get($path)->throw();

        return array_values(array_filter((array) ($r->json('events') ?? $r->json()), fn ($e) => is_array($e) && isset($e['type'], $e['id'])));
    }

    public function send(Integration $i, IntegrationEvent $e): array
    {
        $path = ($this->setting($i, 'push_paths') ?? [])[$e->event_type] ?? null;
        if (! $path) {
            return ['ok' => false, 'reference' => null, 'detail' => "No endpoint is set for {$e->event_type}."];
        }
        $r = $this->client($i)->withHeaders(['Idempotency-Key' => $e->idempotency_key])->post($path, $e->payload);

        return ['ok' => $r->successful(), 'reference' => (string) ($r->json('id') ?? $r->header('Location') ?? ''),
            'detail' => "HTTP {$r->status()}"];
    }

    private function client(Integration $i): PendingRequest
    {
        $c = (array) ($i->credentials ?? []);
        $http = Http::baseUrl(rtrim((string) $i->base_url, '/'))->acceptJson()->timeout(20)->retry(2, 500, throw: false);
        $headers = (array) $this->setting($i, 'headers');

        return match ($i->auth_type) {
            'API_KEY' => $http->withHeaders(array_filter([
                (string) ($headers['key'] ?? 'X-API-Key') => $c['key'] ?? null,
                (string) ($headers['account_id'] ?? 'X-Account-Id') => $c['account_id'] ?? null,
            ])),
            'BASIC' => $http->withBasicAuth((string) ($c['username'] ?? ''), (string) ($c['password'] ?? '')),
            'BEARER' => $http->withToken((string) ($c['token'] ?? '')),
            'OAUTH2' => $http->withToken($this->oauthToken($i, $c)),
            default => $http,
        };
    }

    private function oauthToken(Integration $i, array $c): string
    {
        $url = $c['token_url'] ?? (rtrim((string) $i->base_url, '/').($this->setting($i, 'token_path') ?? '/oauth/token'));
        $r = Http::asForm()->timeout(20)->post($url, ['grant_type' => 'client_credentials', 'client_id' => $c['client_id'] ?? '',
            'client_secret' => $c['client_secret'] ?? '', 'scope' => $c['scope'] ?? null])->throw();

        return (string) $r->json('access_token');
    }

    private function setting(Integration $i, string $key): mixed
    {
        return ($i->settings ?? [])[$key] ?? (self::PRESETS[$i->connector][$key] ?? null);
    }

    /** An error message never carries a secret back into a log. */
    private function scrub(Integration $i, string $text): string
    {
        foreach ((array) ($i->credentials ?? []) as $v) {
            if (is_string($v) && strlen($v) >= 4) {
                $text = str_replace($v, '[redacted]', $text);
            }
        }

        return $text;
    }
}
