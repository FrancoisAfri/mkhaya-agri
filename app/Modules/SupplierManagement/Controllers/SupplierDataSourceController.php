<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\EvidenceLedger;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\Models\DueDiligenceProvider;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Data Source Registry: which verification sources this company can use, what each one is
 * allowed to answer, and whether it actually works.
 *
 * The screen exists to answer one question honestly — "why is this check not being done?" — so it
 * shows the coverage gap as plainly as the coverage. A check with no configured source is not hidden;
 * it is listed as something a person currently has to do by hand.
 *
 * Credentials go in and never come back out. The payload carries the *names* of the keys that are
 * set, never their values, so a compromised session cannot read a customer's CIPC or bureau
 * credentials off the page.
 */
class SupplierDataSourceController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly EvidenceLedger $ledger,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function index(): Response
    {
        $this->authorize('update', Supplier::class);

        $sources = collect($this->registry->inventory())->map(function (array $entry) {
            /** @var DueDiligenceProvider $catalogue */
            $catalogue = $entry['catalogue'];
            /** @var TenantVerificationProvider|null $config */
            $config = $entry['config'];
            $health = $entry['provider']?->health();

            return [
                'code' => $catalogue->code,
                'name' => $catalogue->name,
                'driver' => $catalogue->driver,
                'category' => $catalogue->category,
                'country' => $catalogue->country,
                'capabilities' => collect($catalogue->capabilities ?? [])
                    ->map(fn ($check) => ['key' => $check, 'label' => CheckType::label($check)])->values(),
                'access_note' => $catalogue->access_note,
                'requires_credentials' => (bool) $catalogue->requires_credentials,

                'is_enabled' => (bool) ($config->is_enabled ?? false),
                'mode' => $config->mode ?? 'live',
                // Which keys are set, never what they contain.
                'credential_keys' => $config?->credentialKeys() ?? [],
                'enabled_checks' => $config->enabled_checks ?? [],
                'configured_checks' => array_keys((array) ($config?->setting('checks', []) ?? [])),
                /*
                 * The checks we ship a call profile for. With one, this source needs credentials and
                 * nothing else; without, the customer has to supply an endpoint and a field map,
                 * which is a developer's job on a buyer's screen — and the difference was invisible.
                 */
                'profiled_checks' => array_keys((array) data_get($catalogue->profile, 'checks', [])),
                'profile_source' => $catalogue->profile_source,
                'default_for' => (array) ($config?->setting('default_for', []) ?? []),
                'monthly_quota' => $config->monthly_quota ?? null,
                'calls_this_month' => $config?->currentMonthCalls() ?? 0,
                'quota_remaining' => $config?->quotaRemaining(),

                'state' => $health?->state() ?? 'not_configured',
                'state_message' => $health?->message,
                'last_ok_at' => $config?->last_ok_at?->toDateTimeString(),
                'last_error' => $config->last_error ?? null,
                'last_error_at' => $config?->last_error_at?->toDateTimeString(),
            ];
        })->values();

        return Inertia::render('Modules/SupplierManagement/Settings/DataSources', [
            'sources' => $sources,
            'checks' => $this->checkCoverage(),
            'usage' => $this->usage(),
            'ledger' => $this->ledgerState(),
        ]);
    }

    /**
     * Turn a source on or off, and capture what it needs to work.
     *
     * Credentials merge rather than replace: the screen never receives the current values, so a save
     * that posted only the fields the user retyped would silently wipe the rest.
     */
    public function update(Request $request, string $code): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $catalogue = DueDiligenceProvider::query()->where('code', $code)->where('is_active', true)->firstOrFail();

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'mode' => ['nullable', 'in:live,test'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'enabled_checks' => ['nullable', 'array'],
            'enabled_checks.*' => ['string', 'max:60'],
            'default_for' => ['nullable', 'array'],
            'default_for.*' => ['string', 'max:60'],
            'monthly_quota' => ['nullable', 'integer', 'min:0'],
            'checks' => ['nullable', 'array'],          // per-check endpoint configuration
        ]);

        // A tenant cannot enable a check the provider cannot answer — otherwise the registry screen
        // promises coverage the connector has no way to deliver.
        $allowed = $catalogue->capabilities ?? [];
        $requested = array_values(array_intersect($data['enabled_checks'] ?? [], $allowed));
        $defaults = array_values(array_intersect($data['default_for'] ?? [], $requested ?: $allowed));

        $config = TenantVerificationProvider::query()->firstOrNew(['provider_code' => $code]);
        $existingCredentials = $config->credentials ?? [];

        $incoming = array_filter($data['credentials'] ?? [], fn ($v) => $v !== null && $v !== '');

        $config->fill([
            'provider_code' => $code,
            'is_enabled' => $data['is_enabled'],
            'mode' => $data['mode'] ?? 'live',
            'credentials' => array_merge($existingCredentials, $incoming),
            'enabled_checks' => $requested,
            'monthly_quota' => $data['monthly_quota'] ?? null,
            'settings' => array_merge($config->settings ?? [], [
                'default_for' => $defaults,
                'checks' => $this->sanitiseChecks($data['checks'] ?? [], $allowed, $config),
            ]),
            'configured_by' => Auth::id(),
        ])->save();

        if ($companyId = $this->companyId()) {
            $this->ledger->record($companyId, [
                'action' => $data['is_enabled'] ? 'provider.enabled' : 'provider.disabled',
                'provider_code' => $code,
                'summary' => $catalogue->name.' was '.($data['is_enabled'] ? 'enabled' : 'disabled')
                    .($requested ? ' for '.count($requested).' check(s)' : ''),
                'observed' => ['checks' => $requested, 'credential_keys' => array_keys($config->credentials ?? [])],
            ]);
        }

        return back()->with('success', $data['is_enabled']
            ? "{$catalogue->name} is now available for verification."
            : "{$catalogue->name} has been switched off. Checks it answered will be recorded as unavailable.");
    }

    /** Remove a credential key without having to clear and retype the rest. */
    public function forgetCredential(Request $request, string $code): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $request->validate(['key' => ['required', 'string', 'max:100']]);

        $config = TenantVerificationProvider::query()->where('provider_code', $code)->firstOrFail();
        $credentials = $config->credentials ?? [];
        unset($credentials[$data['key']]);

        $config->update(['credentials' => $credentials]);

        return back()->with('success', "Removed \"{$data['key']}\".");
    }

    /**
     * Every check type, and who would answer it today.
     *
     * The point of this table is the gaps: a check with no source is shown as "recorded by a person",
     * which is true and actionable, rather than being left off the screen where it reads as covered.
     */
    private function checkCoverage(): array
    {
        return collect(CheckType::all())->map(function (array $meta, string $check) {
            $provider = $this->registry->forCheck($check);

            return [
                'key' => $check,
                'label' => $meta['label'],
                'layer' => $meta['layer'],
                'subject' => $meta['subject'],
                'valid_days' => $meta['valid_days'],
                'provider' => $provider?->code(),
                'automated' => $provider !== null,
            ];
        })->values()->all();
    }

    /** What has actually been verified in this tenant — the registry screen's proof of life. */
    private function usage(): array
    {
        $byStatus = SupplierVerification::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus->map(fn ($n) => (int) $n),
            'last_checked_at' => SupplierVerification::query()->max('checked_at'),
            'needs_attention' => SupplierVerification::query()->needsAttention()->count(),
        ];
    }

    private function ledgerState(): array
    {
        $companyId = $this->companyId();

        if (! $companyId) {
            return ['entries' => 0, 'ok' => null];
        }

        $entries = DB::table('dd_evidence_entries')->where('company_id', $companyId)->count();

        // Only verified on demand for small chains — walking a large ledger on every page load would
        // make the screen slower the more evidence a customer has, which is exactly backwards.
        $verdict = $entries > 0 && $entries <= 5000 ? $this->ledger->verify($companyId) : null;

        return [
            'entries' => $entries,
            'ok' => $verdict['ok'] ?? null,
            'broken_at' => $verdict['broken_at'] ?? null,
            'reason' => $verdict['reason'] ?? null,
        ];
    }

    /**
     * Keep only endpoint specs for checks the provider can answer, and preserve any spec the screen
     * did not send so a partial save does not discard a field map somebody set up earlier.
     */
    private function sanitiseChecks(array $checks, array $allowed, TenantVerificationProvider $config): array
    {
        $existing = (array) $config->setting('checks', []);

        foreach ($checks as $check => $spec) {
            if (! in_array($check, $allowed, true) || ! is_array($spec)) {
                continue;
            }

            $existing[$check] = array_filter([
                'endpoint' => $spec['endpoint'] ?? null,
                'method' => in_array($spec['method'] ?? 'GET', ['GET', 'POST'], true) ? $spec['method'] : 'GET',
                'query' => is_array($spec['query'] ?? null) ? $spec['query'] : null,
                'body' => is_array($spec['body'] ?? null) ? $spec['body'] : null,
                'map' => is_array($spec['map'] ?? null) ? $spec['map'] : null,
                'found_path' => $spec['found_path'] ?? null,
                'reference_path' => $spec['reference_path'] ?? null,
                'confidence_path' => $spec['confidence_path'] ?? null,
                'cost' => isset($spec['cost']) ? (float) $spec['cost'] : null,
            ], fn ($v) => $v !== null && $v !== []);
        }

        return $existing;
    }

    private function companyId(): ?int
    {
        return $this->currentCompany->id() ?? Auth::user()?->company_id;
    }
}
