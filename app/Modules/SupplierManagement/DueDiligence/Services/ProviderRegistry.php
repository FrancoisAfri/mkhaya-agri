<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Providers\HttpJsonProvider;
use App\Modules\SupplierManagement\DueDiligence\Providers\ManualProvider;
use App\Modules\SupplierManagement\Models\DueDiligenceProvider;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;

/**
 * Decides who answers a check for the current tenant.
 *
 * The factory half is the same idea as `Tenancy\Payments\GatewayFactory` — a code maps to a class —
 * with one addition it needs and payments does not: resolution by *capability*. The supplier module
 * asks for `company.registry` without naming a provider, so this is where "which of the tenant's
 * configured sources can answer this, and which do we prefer" is decided.
 *
 * Test doubles are registered through `fake()`, which is what lets the engine's behaviour be tested
 * without a single real endpoint.
 */
class ProviderRegistry
{
    /** @var array<string, VerificationProvider> */
    private array $overrides = [];

    /** @var array<string, VerificationProvider> */
    private array $resolved = [];

    /**
     * Register a provider instance under a code, bypassing the database.
     *
     * Used by tests, and by any provider that needs genuinely bespoke behaviour — a service provider
     * can bind its own class here without this file learning about it.
     */
    public function fake(string $code, VerificationProvider $provider): void
    {
        $this->overrides[$code] = $provider;
        unset($this->resolved[$code]);
    }

    public function forget(string $code): void
    {
        unset($this->overrides[$code], $this->resolved[$code]);
    }

    /**
     * The configured data source this tenant uses for a check, or null when nothing can answer it.
     *
     * The manual provider is deliberately NOT a fallback here. It needs a person to supply the
     * outcome, so resolving to it automatically would let a scheduled run record "awaiting review"
     * for a check nobody looked at — an observation that reads as work in progress when in truth the
     * source was never consulted. Null is the honest answer, and the engine turns it into a recorded
     * UNAVAILABLE that says "configure a provider, or record the check manually".
     *
     * Preference order: the tenant's own default for the check type, then catalogue `sort`.
     */
    public function forCheck(string $checkType): ?VerificationProvider
    {
        foreach ($this->candidateCodes($checkType) as $code) {
            $provider = $this->make($code);

            if ($provider && $provider->supports($checkType)) {
                return $provider;
            }
        }

        return null;
    }

    /** Build a provider by code, or null if it is not configured for this tenant. */
    public function make(string $code): ?VerificationProvider
    {
        if (isset($this->overrides[$code])) {
            return $this->overrides[$code];
        }

        if (array_key_exists($code, $this->resolved)) {
            return $this->resolved[$code];
        }

        return $this->resolved[$code] = $this->build($code);
    }

    /**
     * The manual provider, which is always available and never needs configuring.
     *
     * Reached explicitly — by `VerificationEngine::recordManual()` and by the screen that offers
     * "record this check yourself" — never by automatic resolution.
     */
    public function manual(): VerificationProvider
    {
        return $this->overrides['manual'] ?? new ManualProvider;
    }

    /** Whether a real data source is configured for this check, or a person would have to do it. */
    public function hasDataSourceFor(string $checkType): bool
    {
        return $this->forCheck($checkType) !== null;
    }

    /**
     * Every configured provider for this tenant, paired with its catalogue row and health — the
     * Data Source Registry screen reads exactly this.
     *
     * @return array<int, array{catalogue: DueDiligenceProvider, config: TenantVerificationProvider|null, provider: VerificationProvider|null}>
     */
    public function inventory(): array
    {
        $configs = TenantVerificationProvider::query()->get()->keyBy('provider_code');

        return DueDiligenceProvider::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn (DueDiligenceProvider $catalogue) => [
                'catalogue' => $catalogue,
                'config' => $configs->get($catalogue->code),
                'provider' => $this->make($catalogue->code),
            ])
            ->all();
    }

    private function build(string $code): ?VerificationProvider
    {
        $catalogue = DueDiligenceProvider::query()->where('code', $code)->where('is_active', true)->first();

        if (! $catalogue) {
            return null;
        }

        // Manual needs no configuration — a person is always available, which is what keeps the
        // framework useful before anything is licensed.
        if ($catalogue->driver === 'manual') {
            return new ManualProvider;
        }

        $config = TenantVerificationProvider::query()->where('provider_code', $code)->first();

        if (! $config || ! $config->is_enabled) {
            return null;
        }

        return match ($catalogue->driver) {
            'http_json' => new HttpJsonProvider(
                $code, $config, $catalogue->capabilities ?? [], (array) ($catalogue->profile ?? []),
            ),
            default => null,
        };
    }

    /**
     * Provider codes worth trying for a check, in order.
     *
     * A tenant can pin a check to a provider (`settings.default_for` on their configuration), which
     * is how an installation with two credit bureaux says which one to pull from by default.
     */
    private function candidateCodes(string $checkType): array
    {
        $codes = [];

        $configs = TenantVerificationProvider::query()->where('is_enabled', true)->get();

        foreach ($configs as $config) {
            if (in_array($checkType, (array) $config->setting('default_for', []), true)) {
                $codes[] = $config->provider_code;
            }
        }

        foreach (DueDiligenceProvider::forCheck($checkType) as $catalogue) {
            if ($catalogue->driver === 'manual') {
                continue;
            }

            $config = $configs->firstWhere('provider_code', $catalogue->code);

            if ($config && $config->allowsCheck($checkType)) {
                $codes[] = $catalogue->code;
            }
        }

        return array_values(array_unique($codes));
    }
}
