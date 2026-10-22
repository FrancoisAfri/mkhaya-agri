<?php

namespace App\Modules\Agri\Accounting;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\AccountingConnection;
use App\Modules\Agri\Models\AccountingLink;
use App\Modules\Agri\Models\AccountingMapping;
use App\Modules\Agri\Models\AgriOrgSetting;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting an organisation to Xero, QuickBooks Online or Sage: the OAuth 2.0 authorisation-code
 * flow with a one-time state, the tokens kept encrypted on the connection, the tenant (organisation,
 * realm, business) chosen after connecting, and disconnecting. Sage in South Africa is the
 * exception: a sign-in with the Sage username and password (signIn), no redirect. Nothing here works until the
 * platform has registered an app with the provider (client id and secret in config); until then a
 * connection says so.
 */
class AccountingConnections
{
    public const CONNECTORS = [AccountingConnection::XERO => XeroConnector::class, AccountingConnection::QUICKBOOKS => QuickBooksConnector::class, AccountingConnection::SAGE => SageConnector::class];

    /** Sage's South African product: its own API, signed in with the Sage username and password (SageSouthAfricaConnector). */
    public const SAGE_ZA = 'za';

    public function connector(AccountingConnection $c): Connector
    {
        $class = self::CONNECTORS[$c->provider] ?? throw new \InvalidArgumentException("No connector for {$c->provider}.");
        if ($c->provider === AccountingConnection::SAGE && $c->region === self::SAGE_ZA) {
            $class = SageSouthAfricaConnector::class;
        }

        return new $class($c);
    }

    public static function redirectUri(string $provider): string
    {
        return route('agri.accounting.callback', $provider);
    }

    /** Where to send the person to grant access. */
    public function start(int $companyId, string $provider, ?string $region, User $actor): string
    {
        $this->assertProvider($provider);
        $c = AccountingConnection::for($companyId, $provider);
        if ($provider === AccountingConnection::SAGE && ($region ?: 'uk') === self::SAGE_ZA) {
            throw ValidationException::withMessages(['region' => 'Sage Business Cloud Accounting South Africa does not sign in through a Sage page: enter your Sage username and password on the Sage card, check the connection, then choose the company.']);
        }
        if (! AccountingConnection::registered($provider)) {
            $c->update(['status' => 'needs_registration']);
            throw ValidationException::withMessages(['provider' => config("agri.accounting.providers.{$provider}.name").' is not set up on this platform yet: the platform needs an app registered with '
                .config("agri.accounting.providers.{$provider}.name").' (client id and secret) before an organisation can connect.']);
        }
        if ($provider === AccountingConnection::SAGE) {
            $regions = config('agri.accounting.providers.sage.regions', []);
            $region = $region ?: 'uk';
            if (! ($regions[$region]['supported'] ?? false)) {
                throw ValidationException::withMessages(['region' => ($regions[$region]['name'] ?? $region).': Sage Business Cloud Accounting there runs on a different Sage API that this connector does not speak yet.']);
            }
            $this->switchRegion($c, $region);
        }
        $state = Str::random(48);
        $c->forceFill(['oauth_state' => hash('sha256', $state), 'oauth_state_expires_at' => now()->addMinutes(15), 'region' => $provider === AccountingConnection::SAGE ? $region : null,
            'status' => $c->isConnected() ? 'connected' : 'disconnected'])->save();

        return $this->connector($c)->authorizeUrl($state, self::redirectUri($provider));
    }

    /** The provider sent the person back. */
    public function callback(int $companyId, string $provider, array $query, User $actor): AccountingConnection
    {
        $this->assertProvider($provider);
        $c = AccountingConnection::for($companyId, $provider);
        $state = (string) ($query['state'] ?? '');
        if (! $c->oauth_state || ! hash_equals($c->oauth_state, hash('sha256', $state)) || ! $c->oauth_state_expires_at || $c->oauth_state_expires_at->isPast()) {
            throw ValidationException::withMessages(['state' => 'That sign-in link has expired or was not started here. Connect again.']);
        }
        $c->forceFill(['oauth_state' => null, 'oauth_state_expires_at' => null])->save();
        if (! empty($query['error'])) {
            $c->update(['status' => $c->tokens ? $c->status : 'disconnected', 'last_error' => 'Access was not granted: '.mb_strimwidth((string) ($query['error_description'] ?? $query['error']), 0, 300)]);
            throw ValidationException::withMessages(['provider' => $c->last_error]);
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'The provider sent no authorisation code.']);
        }
        $connector = $this->connector($c);
        try {
            $tokens = $connector->exchange($code, self::redirectUri($provider));
            $tenants = $connector->tenants($tokens, $query);
        } catch (AccountingApiError $e) {
            $c->update(['status' => 'error', 'last_error' => mb_strimwidth($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }
        $c->putTokens($tokens);
        $c->forceFill(['tenants' => $tenants, 'connected_at' => now(), 'connected_by' => $actor->id, 'last_error' => null])->save();
        if (count($tenants) === 1) {
            return $this->chooseTenant($c, $tenants[0]['id']);
        }
        $c->update(['status' => $tenants ? 'choose_tenant' : 'error', 'last_error' => $tenants ? null : 'The grant covers no organisation.']);

        return $c->fresh();
    }

    /**
     * Sage South Africa: the person's Sage username and password (and, if the organisation has its
     * own, an API key) checked against Company/Get, kept encrypted, and the company chosen — at once
     * when there is one, else from the list. A blank password keeps the one already stored (the
     * screen never shows it); a blank API key keeps the organisation's own, else the platform's.
     *
     * @param array{username: string, password?: ?string, api_key?: ?string} $data
     */
    public function signIn(int $companyId, array $data, User $actor): AccountingConnection
    {
        $c = AccountingConnection::for($companyId, AccountingConnection::SAGE);
        $old = $c->region === self::SAGE_ZA ? $c->tokens() : [];
        $username = trim((string) $data['username']);
        $cred = array_filter(['username' => $username,
            'password' => filled($data['password'] ?? null) ? (string) $data['password'] : (($old['username'] ?? null) === $username ? ($old['password'] ?? null) : null),
            'api_key' => filled($data['api_key'] ?? null) ? trim((string) $data['api_key']) : ($old['api_key'] ?? null)]);
        if (empty($cred['password'])) {
            throw ValidationException::withMessages(['password' => 'Enter the Sage password.']);
        }
        $this->switchRegion($c, self::SAGE_ZA);
        $c->forceFill(['region' => self::SAGE_ZA, 'oauth_state' => null, 'oauth_state_expires_at' => null]);
        try {
            $tenants = $this->connector($c)->tenants($cred, []);
        } catch (AccountingApiError $e) {
            $c->forceFill(['status' => $c->isConnected() ? $c->status : 'error', 'last_error' => mb_strimwidth($e->getMessage(), 0, 500)])->save();
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }
        $c->putTokens($cred);
        $c->forceFill(['tenants' => $tenants, 'connected_at' => now(), 'connected_by' => $actor->id, 'last_error' => null])->save();
        if ($c->tenant_id && collect($tenants)->contains('id', $c->tenant_id)) {
            return $this->chooseTenant($c, $c->tenant_id);
        }
        if (count($tenants) === 1) {
            return $this->chooseTenant($c, $tenants[0]['id']);
        }
        $c->update(['status' => $tenants ? 'choose_tenant' : 'error', 'last_error' => $tenants ? null : 'This Sage sign-in has no company.']);

        return $c->fresh();
    }

    /**
     * Moving a Sage connection between the v3.1 product and the South African one is a different
     * set of books: the sign-in, the company, what was mapped and what was pushed are forgotten.
     */
    private function switchRegion(AccountingConnection $c, string $region): void
    {
        if (! $c->exists || ! $c->region || $c->region === $region || ($c->region !== self::SAGE_ZA && $region !== self::SAGE_ZA)) {
            return;
        }
        AccountingLink::withoutGlobalScopes()->where('connection_id', $c->id)->delete();
        AccountingMapping::withoutGlobalScopes()->where('connection_id', $c->id)->delete();
        $c->putTokens(null);
        $c->forceFill(['catalog' => null, 'tenant_id' => null, 'tenant_name' => null, 'tenants' => null, 'region' => $region,
            'status' => $region === self::SAGE_ZA || AccountingConnection::registered($c->provider) ? 'disconnected' : 'needs_registration'])->save();
    }

    public function chooseTenant(AccountingConnection $c, string $tenantId): AccountingConnection
    {
        $t = collect($c->tenants ?? [])->firstWhere('id', $tenantId);
        if (! $t) {
            throw ValidationException::withMessages(['tenant' => 'That organisation is not one this connection covers.']);
        }
        if ($c->tenant_id && $c->tenant_id !== $tenantId) {
            // Another organisation: what was mapped and pushed belongs to the old one.
            AccountingLink::withoutGlobalScopes()->where('connection_id', $c->id)->delete();
            AccountingMapping::withoutGlobalScopes()->where('connection_id', $c->id)->delete();
            $c->catalog = null;
        }
        $c->forceFill(['tenant_id' => $tenantId, 'tenant_name' => $t['name'] ?? $tenantId, 'status' => 'connected', 'last_error' => null])->save();

        return $c->fresh();
    }

    /** The grant is revoked (best effort) and the tokens forgotten; mappings and the sync log stay. */
    public function disconnect(AccountingConnection $c, User $actor): AccountingConnection
    {
        $this->connector($c)->revoke();
        $c->putTokens(null);
        $c->forceFill(['status' => AccountingConnection::registered($c->provider) || $c->region === self::SAGE_ZA ? 'disconnected' : 'needs_registration', 'last_error' => null])->save();
        $settings = AgriOrgSetting::for((int) $c->company_id);
        if ($settings->book_of_record === $c->provider) {
            // The books cannot be a system we no longer reach: back to our own ledger, said out loud on the screen.
            $settings->update(['book_of_record' => 'internal', 'book_of_record_changed_at' => now(), 'book_of_record_changed_by' => $actor->id]);
        }

        return $c->fresh();
    }

    private function assertProvider(string $provider): void
    {
        if (! isset(self::CONNECTORS[$provider])) {
            throw ValidationException::withMessages(['provider' => 'Unknown accounting system.']);
        }
    }
}
