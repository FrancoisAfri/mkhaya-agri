<?php

namespace App\Modules\SupplierManagement\DueDiligence\Providers;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A JSON-over-HTTP verification source, configured rather than coded.
 *
 * Deliberately not a hand-written CIPC or SARS client. Access to those sources is by authorised
 * account — customers reach them through their own arrangement, an aggregator, or a government
 * gateway, and each of those speaks a different dialect. Writing a client against an API contract we
 * have not been given would mean inventing one, and an invented integration that returns plausible
 * answers is the single most dangerous thing this module could contain.
 *
 * So the tenant supplies the endpoint, the authentication and a field map, and this connector does
 * the mechanical part: call, read, normalise, hand back. Wiring up a real CIPC gateway becomes
 * configuration, and a provider that needs genuinely bespoke behaviour still gets its own class
 * against the same interface.
 *
 * Settings, per check type (`settings.checks.<check_type>`):
 *
 *   endpoint     required  URL, with {placeholders} filled from the claim and context
 *   method       GET|POST  default GET
 *   query        array     name => {placeholder} or literal
 *   body         array     as query, for POST
 *   map          array     our field name => dot path into the response
 *   found_path   string    dot path whose emptiness means "no record" (optional)
 *   status_path  string    dot path to a provider status string (optional)
 *
 * Credentials: `api_key` (sent per `settings.auth`: bearer | header | query), `header_name`,
 * `query_name`, plus anything else the placeholders reference.
 */
class HttpJsonProvider implements VerificationProvider
{
    public function __construct(
        private readonly string $providerCode,
        private readonly TenantVerificationProvider $config,
        private readonly array $catalogueCapabilities = [],
        /**
         * What we ship for this source: endpoint, method, placeholders and field map, per check.
         *
         * Underneath the tenant's own settings rather than over them. A commercial contract's
         * response shape varies by tier, so the customer's documentation beats ours whenever the two
         * disagree — and a tenant who has configured nothing still gets a working connector.
         */
        private readonly array $profile = [],
    ) {}

    /**
     * The spec for each check: what we ship, with the tenant's own settings merged over it.
     *
     * Per check rather than wholesale, so a tenant overriding one endpoint does not lose the shipped
     * map for the other five. Merged key by key within a check for the same reason — a contract that
     * differs only in its base address should not require the whole field map to be retyped.
     *
     * @return array<string, array<string, mixed>>
     */
    private function checkSpecs(): array
    {
        $shipped = (array) Arr::get($this->profile, 'checks', []);
        $tenant = (array) $this->config->setting('checks', []);

        $specs = [];

        foreach (array_keys($shipped + $tenant) as $check) {
            $specs[$check] = array_merge((array) ($shipped[$check] ?? []), (array) ($tenant[$check] ?? []));
        }

        // A check with no endpoint on either side cannot be called, and claiming it would have the
        // registry resolve to a provider that answers nothing.
        return array_filter($specs, fn (array $spec) => filled($spec['endpoint'] ?? null));
    }

    public function code(): string
    {
        return $this->providerCode;
    }

    public function capabilities(): array
    {
        // What this tenant has actually configured an endpoint for, never the catalogue's ambitions.
        $configured = array_keys($this->checkSpecs());

        return $this->catalogueCapabilities === []
            ? $configured
            : array_values(array_intersect($this->catalogueCapabilities, $configured));
    }

    public function supports(string $checkType): bool
    {
        return in_array($checkType, $this->capabilities(), true);
    }

    public function verify(VerificationRequest $request): VerificationResult
    {
        $spec = (array) ($this->checkSpecs()[$request->checkType] ?? []);

        if ($spec === [] || empty($spec['endpoint'])) {
            return VerificationResult::unavailable(
                "No endpoint is configured for {$request->checkType} on {$this->providerCode}."
            );
        }

        $tokens = $this->tokens($request);

        try {
            $response = $this->send($spec, $tokens);
        } catch (\Throwable $e) {
            // The message is kept because a person has to act on it, but it is truncated and never
            // includes the request — the request carries the credentials.
            return VerificationResult::error(Str::limit($e->getMessage(), 200, ''));
        }

        if ($response === null) {
            return VerificationResult::unavailable("{$this->providerCode} did not respond.");
        }

        [$ok, $payload, $httpStatus] = $response;

        if (! $ok) {
            return $httpStatus === 404
                ? VerificationResult::notFound("{$this->providerCode} has no record of this subject.", $payload)
                : VerificationResult::error("{$this->providerCode} returned HTTP {$httpStatus}.", $payload);
        }

        // An explicit "nothing here" path, because plenty of APIs answer 200 with an empty result.
        if (! empty($spec['found_path']) && $this->isEmpty(Arr::get($payload, $spec['found_path']))) {
            return VerificationResult::notFound("{$this->providerCode} has no record of this subject.", $payload);
        }

        $observed = $this->mapFields((array) ($spec['map'] ?? []), $payload);

        if ($observed === []) {
            return VerificationResult::manualReview(
                "{$this->providerCode} answered, but none of the mapped fields were present — check the field map.",
                [],
                $payload
            );
        }

        // The comparison against what the supplier claimed is the engine's job, not the connector's:
        // one implementation of "does this match" for every provider.
        return new VerificationResult(
            status: \App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus::VERIFIED,
            observed: $observed,
            summary: "Answered by {$this->providerCode}.",
            confidence: $this->confidenceFrom($spec, $payload),
            reference: $this->referenceFrom($spec, $payload) ?? $request->reference,
            raw: $payload,
            cost: isset($spec['cost']) ? (float) $spec['cost'] : null,
        );
    }

    public function health(): ProviderHealth
    {
        if (! $this->config->is_enabled) {
            return ProviderHealth::notConfigured('Not enabled for this company.');
        }

        if ($this->config->credentialKeys() === []) {
            return ProviderHealth::notConfigured('No credentials captured.');
        }

        if ($this->capabilities() === []) {
            return ProviderHealth::notConfigured('Enabled, but no check endpoints are configured.');
        }

        if ($this->config->quotaExhausted()) {
            return ProviderHealth::failing('This month\'s allowance is used up.');
        }

        // Nothing is called here: a health check that pinged a metered API would spend the customer's
        // money every time the registry screen was opened. What we know is what the last real call
        // reported, which is the honest answer.
        if ($this->config->last_error && $this->config->last_error_at
            && (! $this->config->last_ok_at || $this->config->last_error_at->gt($this->config->last_ok_at))) {
            return ProviderHealth::failing($this->config->last_error);
        }

        return ProviderHealth::ok(
            $this->config->last_ok_at ? 'Last call succeeded '.$this->config->last_ok_at->diffForHumans() : 'Configured; not yet used.',
            $this->config->quotaRemaining()
        );
    }

    /** @return array{0:bool,1:array,2:int}|null */
    private function send(array $spec, array $tokens): ?array
    {
        $method = Str::upper((string) ($spec['method'] ?? 'GET'));
        $url = $this->fill((string) $spec['endpoint'], $tokens);
        $timeout = (int) ($this->config->setting('timeout', 20));

        $client = Http::timeout($timeout)->acceptJson()->asJson();

        foreach ($this->authHeaders() as $name => $value) {
            $client = $client->withHeaders([$name => $value]);
        }

        $query = $this->fillAll((array) ($spec['query'] ?? []), $tokens) + $this->authQuery();
        $body = $this->fillAll((array) ($spec['body'] ?? []), $tokens);

        $response = $method === 'POST'
            ? $client->post($url, $body + $query)
            : $client->get($url, $query);

        return [$response->successful(), (array) $response->json(), $response->status()];
    }

    private function authHeaders(): array
    {
        $key = (string) $this->config->cred('api_key', '');
        $style = (string) $this->config->setting('auth', 'bearer');

        if ($key === '') {
            return [];
        }

        return match ($style) {
            'header' => [(string) $this->config->setting('header_name', 'X-API-Key') => $key],
            'bearer' => ['Authorization' => 'Bearer '.$key],
            default => [],
        };
    }

    private function authQuery(): array
    {
        $key = (string) $this->config->cred('api_key', '');

        return $this->config->setting('auth') === 'query' && $key !== ''
            ? [(string) $this->config->setting('query_name', 'api_key') => $key]
            : [];
    }

    /** The values a check's endpoint and parameters may reference. */
    private function tokens(VerificationRequest $request): array
    {
        $tokens = [];

        foreach ($request->claimed as $field => $value) {
            $tokens[$field] = is_scalar($value) ? (string) $value : '';
        }

        foreach ($request->context as $field => $value) {
            if (is_scalar($value)) {
                $tokens[$field] = (string) $value;
            }
        }

        $tokens['reference'] = (string) $request->reference;
        $tokens['supplier_id'] = (string) $request->supplier->id;

        return $tokens;
    }

    private function fill(string $template, array $tokens): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', function ($m) use ($tokens) {
            return rawurlencode((string) ($tokens[$m[1]] ?? ''));
        }, $template);
    }

    private function fillAll(array $values, array $tokens): array
    {
        return array_map(
            fn ($v) => is_string($v) ? $this->fillRaw($v, $tokens) : $v,
            $values
        );
    }

    /** As fill(), without URL-encoding — query and body values are encoded by the HTTP client. */
    private function fillRaw(string $template, array $tokens): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($tokens[$m[1]] ?? ''), $template);
    }

    private function mapFields(array $map, array $payload): array
    {
        $observed = [];

        foreach ($map as $field => $path) {
            $value = Arr::get($payload, (string) $path);

            if (! $this->isEmpty($value)) {
                $observed[$field] = $value;
            }
        }

        return $observed;
    }

    private function confidenceFrom(array $spec, array $payload): ?int
    {
        if (empty($spec['confidence_path'])) {
            return null;
        }

        $value = Arr::get($payload, $spec['confidence_path']);

        return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
    }

    private function referenceFrom(array $spec, array $payload): ?string
    {
        if (empty($spec['reference_path'])) {
            return null;
        }

        $value = Arr::get($payload, $spec['reference_path']);

        return is_scalar($value) ? Str::limit((string) $value, 118, '') : null;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
}
