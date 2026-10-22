<?php

namespace App\Modules\Agri\Accounting;

use App\Modules\Agri\Models\AccountingConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One accounting provider's API, behind the same shape: OAuth 2.0 authorisation code with refresh,
 * the organisations (tenants, realms, businesses) the person may pick, their chart of accounts, tax
 * rates, tracking and items, contacts found or created, a sales invoice or purchase bill and a
 * journal pushed, and payments read back with what they paid.
 *
 * Documents arrive here already mapped (see AccountingDocuments): an account carries the
 * provider's id and code, a tax line its tax code, a tracking line its category and option. A
 * connector only speaks the provider's dialect. Every call that fails throws AccountingApiError
 * with the provider's own message, which is what the sync log shows.
 */
abstract class Connector
{
    public function __construct(protected AccountingConnection $connection) {}

    abstract public function provider(): string;

    /** The URL the person is sent to to grant access. */
    abstract public function authorizeUrl(string $state, string $redirectUri): string;

    /** The organisations the grant covers: [{id, name}]. $query is the callback's query (QuickBooks puts the realm there). */
    abstract public function tenants(array $tokens, array $query): array;

    /** @return array{accounts: list<array>, tax_rates: list<array>, tracking: list<array>, items: list<array>} */
    abstract public function catalog(): array;

    /** @return list<array{id: string, name: string, tax_number: ?string, kind: string}> */
    abstract public function contacts(): array;

    /** A contact by VAT number, then exact name, or null. $kind: customer | supplier */
    abstract public function findContact(string $name, ?string $taxNumber, string $kind): ?array;

    /** @param array{name: string, email: ?string, tax_number: ?string} $party @return array{id: string} */
    abstract public function createContact(array $party, string $kind): array;

    /** @return array{id: string, number: ?string} */
    abstract public function pushInvoice(array $doc, string $idempotencyKey): array;

    /** @return array{id: string, number: ?string} */
    abstract public function pushJournal(array $doc, string $idempotencyKey): array;

    /**
     * Payments changed since a moment, with the documents each paid.
     *
     * @return list<array{id: string, date: string, kind: string, reference: ?string, allocations: list<array{document_id: string, amount: float}>}>
     */
    abstract public function payments(?Carbon $since): array;

    /** Whether a contact id there can stand for a $kind (customer | supplier). One contact list for both: always. */
    public function contactFits(string $externalId, string $kind): bool
    {
        return true;
    }

    public function config(?string $key = null): mixed
    {
        $c = config('agri.accounting.providers.'.$this->provider(), []);

        return $key ? ($c[$key] ?? null) : $c;
    }

    /** Exchange the code from the callback for tokens. */
    public function exchange(string $code, string $redirectUri): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
    }

    public function refreshTokens(string $refreshToken): array
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /** Tell the provider to forget the grant. Best effort: a provider that is down must not stop a disconnect. */
    public function revoke(): void
    {
        $t = $this->connection->tokens();
        if (! ($t['refresh_token'] ?? null) || ! $this->config('revoke_url')) {
            return;
        }
        try {
            Http::asForm()->timeout(10)->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
                ->post($this->config('revoke_url'), ['token' => $t['refresh_token']]);
        } catch (\Throwable) {
        }
    }

    /**
     * A live access token: refreshed (and the new pair stored) when it is within a minute of
     * expiring. A refused refresh means the grant is gone; the connection says so and must be
     * connected again.
     */
    public function accessToken(): string
    {
        $t = $this->connection->tokens();
        if (! ($t['access_token'] ?? null)) {
            throw new AccountingApiError('Not connected: connect '.$this->config('name').' again.');
        }
        if (($t['expires_at'] ?? 0) - 60 <= now()->timestamp) {
            try {
                $new = $this->refreshTokens((string) ($t['refresh_token'] ?? ''));
            } catch (AccountingApiError $e) {
                $this->connection->forceFill(['status' => 'error', 'last_error' => 'The connection has expired or was revoked: connect again. ('.$e->getMessage().')'])->save();
                throw $e;
            }
            $this->connection->putTokens($new + ['refresh_token' => $t['refresh_token'] ?? null]);
            $this->connection->save();
            $t = $this->connection->tokens();
        }

        return (string) $t['access_token'];
    }

    protected function tokenRequest(array $form): array
    {
        $req = Http::asForm()->acceptJson()->timeout((int) config('agri.accounting.timeout', 30));
        if ($this->tokenExtras() === []) {
            $req = $req->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'));
        }
        $r = $req->post($this->config('token_url'), $form + $this->tokenExtras());
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new AccountingApiError($this->config('name').' refused the token request: '.($r->json('error_description') ?? $r->json('error') ?? $r->status()));
        }

        return array_filter(['access_token' => (string) $r->json('access_token'), 'refresh_token' => $r->json('refresh_token'),
            'expires_at' => now()->timestamp + (int) ($r->json('expires_in') ?? 1800),
            'refresh_expires_at' => $r->json('refresh_token_expires_in') ? now()->timestamp + (int) $r->json('refresh_token_expires_in') : ($r->json('x_refresh_token_expires_in') ? now()->timestamp + (int) $r->json('x_refresh_token_expires_in') : null),
            'scope' => $r->json('scope')], fn ($v) => $v !== null);
    }

    /** Fields a provider wants on the token request instead of HTTP basic client authentication (Sage: the client credentials in the body). */
    protected function tokenExtras(): array
    {
        return [];
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())->acceptJson()->asJson()->timeout((int) config('agri.accounting.timeout', 30));
    }

    /** The response's JSON, or the provider's own error message thrown. */
    protected function ok(Response $r, string $what): array
    {
        if ($r->successful()) {
            return $r->json() ?? [];
        }
        $msg = $this->errorMessage($r);

        throw new AccountingApiError("{$this->config('name')} refused {$what} ({$r->status()}): {$msg}", $r->status());
    }

    abstract protected function errorMessage(Response $r): string;

    protected static function money(float $v): float
    {
        return round($v, 2);
    }

    /** Tracking/analysis entries a document line carries for this provider. */
    protected static function tracking(array $line): array
    {
        return array_values(array_filter($line['tracking'] ?? [], fn ($t) => ! empty($t['option_id']) || ! empty($t['option'])));
    }

    protected function requireTenant(): string
    {
        return $this->connection->tenant_id ?: throw new RuntimeException('Choose which '.$this->config('name').' organisation to use first.');
    }
}
