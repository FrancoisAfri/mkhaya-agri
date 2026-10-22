<?php

namespace App\Modules\Agri\MachineData;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\MachineConflict;
use App\Modules\Agri\Models\MachineConnection;
use App\Modules\Agri\Models\MachineLink;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting an organisation to John Deere Operations Center or CNH FieldOps: the OAuth 2.0
 * authorisation-code flow with a one-time state, tokens kept encrypted on the connection, the
 * Deere organisation or CNH company chosen after connecting — and, for Deere, the organisation's
 * own access grant, without which its data stays closed to the app — and disconnecting.
 *
 * Nothing here works until the manufacturer has approved the platform's developer app (client id
 * and secret in config); until then a connection says it needs developer approval.
 */
class MachineConnections
{
    public const CONNECTORS = [MachineConnection::DEERE => DeereConnector::class, MachineConnection::CNH => CnhConnector::class];

    public function connector(MachineConnection $c): MachineConnector
    {
        $class = self::CONNECTORS[$c->provider] ?? throw new \InvalidArgumentException("No connector for {$c->provider}.");

        return new $class($c);
    }

    public static function redirectUri(string $provider): string
    {
        return route('agri.machine-data.callback', $provider);
    }

    public function start(int $companyId, string $provider, User $actor): string
    {
        $this->assertProvider($provider);
        $c = MachineConnection::for($companyId, $provider);
        if (! MachineConnection::registered($provider)) {
            $c->update(['status' => 'needs_registration']);
            $name = config("agri.machine_data.providers.{$provider}.name");
            throw ValidationException::withMessages(['provider' => "{$name} is not set up on this platform yet: the platform's developer app must be approved by the manufacturer (client id and secret) before an organisation can connect."]);
        }
        $state = Str::random(48);
        $c->forceFill(['oauth_state' => hash('sha256', $state), 'oauth_state_expires_at' => now()->addMinutes(15),
            'status' => $c->isConnected() ? 'connected' : ($c->tokens() ? $c->status : 'disconnected')])->save();

        return $this->connector($c)->authorizeUrl($state, self::redirectUri($provider));
    }

    public function callback(int $companyId, string $provider, array $query, User $actor): MachineConnection
    {
        $this->assertProvider($provider);
        $c = MachineConnection::for($companyId, $provider);
        $state = (string) ($query['state'] ?? '');
        if (! $c->oauth_state || ! hash_equals($c->oauth_state, hash('sha256', $state)) || ! $c->oauth_state_expires_at || $c->oauth_state_expires_at->isPast()) {
            throw ValidationException::withMessages(['state' => 'That sign-in link has expired or was not started here. Connect again.']);
        }
        $c->forceFill(['oauth_state' => null, 'oauth_state_expires_at' => null])->save();
        if (! empty($query['error'])) {
            $c->update(['status' => $c->tokens() ? $c->status : 'disconnected', 'last_error' => 'Access was not granted: '.mb_strimwidth((string) ($query['error_description'] ?? $query['error']), 0, 300)]);
            throw ValidationException::withMessages(['provider' => $c->last_error]);
        }
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            throw ValidationException::withMessages(['code' => 'The platform sent no authorisation code.']);
        }
        $connector = $this->connector($c);
        try {
            $tokens = $connector->exchange($code, self::redirectUri($provider));
            $orgs = $connector->organisations($tokens);
        } catch (MachineApiError $e) {
            $c->update(['status' => 'error', 'last_error' => mb_strimwidth($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }
        $c->putTokens($tokens);
        $c->forceFill(['organisations' => $orgs, 'connected_at' => now(), 'connected_by' => $actor->id, 'last_error' => null])->save();
        if (count($orgs) === 1) {
            return $this->chooseOrganisation($c, $orgs[0]['id']);
        }
        if ($c->org_id && collect($orgs)->firstWhere('id', $c->org_id)) {
            return $this->chooseOrganisation($c, $c->org_id);   // a reconnect keeps the organisation it had
        }
        $c->update(['status' => $orgs ? 'choose_organisation' : 'error', 'last_error' => $orgs ? null : 'The sign-in covers no organisation.']);

        return $c->fresh();
    }

    /**
     * Use this organisation. A Deere organisation that has not yet granted the app access is
     * remembered but not usable: the screen sends a person to Deere's grant page.
     */
    public function chooseOrganisation(MachineConnection $c, string $orgId): MachineConnection
    {
        $o = collect($c->organisations ?? [])->firstWhere('id', $orgId);
        if (! $o) {
            throw ValidationException::withMessages(['organisation' => 'That organisation is not one this connection covers.']);
        }
        if ($c->org_id && $c->org_id !== $orgId) {
            // Another organisation: what was paired belongs to the old one. Imported operations stay, for the audit.
            MachineConflict::withoutGlobalScopes()->where('connection_id', $c->id)->delete();
            MachineLink::withoutGlobalScopes()->where('connection_id', $c->id)->delete();
            $c->cursors = null;
        }
        $c->forceFill(['org_id' => $orgId, 'org_name' => $o['name'] ?? $orgId, 'status' => ($o['needs_access'] ?? false) ? 'needs_org_access' : 'connected', 'last_error' => null])->save();

        return $c->fresh();
    }

    /** Read the organisations again — after the organisation granted access on Deere's page. */
    public function recheck(MachineConnection $c): MachineConnection
    {
        try {
            $orgs = $this->connector($c)->organisations(['access_token' => $this->connector($c)->accessToken()]);
        } catch (MachineApiError $e) {
            $c->update(['last_error' => mb_strimwidth($e->getMessage(), 0, 500)]);
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }
        $c->forceFill(['organisations' => $orgs])->save();

        return $c->org_id ? $this->chooseOrganisation($c, $c->org_id) : $c->fresh();
    }

    /** Where a person grants the chosen organisation's access (Deere), coming back to our screen. */
    public function accessUrl(MachineConnection $c): ?string
    {
        if ($c->provider !== MachineConnection::DEERE || $c->status !== 'needs_org_access') {
            return null;
        }
        $o = collect($c->organisations ?? [])->firstWhere('id', $c->org_id);
        $back = route('agri.machine-data.index', ['provider' => $c->provider]);
        if (! empty($o['access_url'])) {
            return $o['access_url'].(str_contains($o['access_url'], '?') ? '&' : '?').http_build_query(['redirect_uri' => $back]);
        }

        return (new DeereConnector($c))->accessGrantUrl($back);
    }

    public function disconnect(MachineConnection $c): MachineConnection
    {
        $this->connector($c)->revoke();
        $c->putTokens(null);
        $c->forceFill(['status' => MachineConnection::registered($c->provider) ? 'disconnected' : 'needs_registration', 'last_error' => null])->save();

        return $c->fresh();
    }

    private function assertProvider(string $provider): void
    {
        if (! isset(self::CONNECTORS[$provider])) {
            throw ValidationException::withMessages(['provider' => 'Unknown machine platform.']);
        }
    }
}
