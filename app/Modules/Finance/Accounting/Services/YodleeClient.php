<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinYodleeSetting;
use App\Modules\Finance\Accounting\Models\FinYodleeUser;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Yodlee's REST API, as much of it as a bank feed needs.
 *
 * Four calls carry the whole feature: mint a token, mint a user token for the FastLink widget, list
 * the accounts a user has linked, and pull that account's transactions for a date range.
 *
 * ── Tokens ─────────────────────────────────────────────────────────────────
 *
 * Yodlee issues a bearer token per (subscription, user) pair, valid for thirty minutes. Requesting
 * one on every call would triple the request count against a metered API, so tokens are cached just
 * short of their life — five minutes' headroom, because a token that expires mid-flight fails the
 * call it was fetched for.
 *
 * Cached per tenant and per login name. A shared cache key would hand one customer's token to
 * another, which on this API means one customer's bank transactions.
 *
 * ── Errors ─────────────────────────────────────────────────────────────────
 *
 * Yodlee answers failures with its own errorCode and a reference id that their support asks for
 * first. Both are carried into the exception message rather than discarded for a bare HTTP status,
 * because "Y804" and a reference is a support ticket that can be answered, and "401" is not.
 */
class YodleeClient
{
    /** The API version header every request must carry. */
    public const API_VERSION = '1.1';

    private const TOKEN_TTL_SECONDS = 1500;   // 25 min of Yodlee's 30, leaving room to make the call

    /**
     * @param  FinYodleeUser  $user  the tenant acting inside this subscription — never shared
     */
    public function __construct(
        private readonly FinYodleeSetting $settings,
        private readonly FinYodleeUser $user,
    ) {
    }

    /**
     * A client bound to one company's Yodlee identity.
     *
     * The user is a required argument rather than something read off the settings, because the
     * settings are now usually the platform's and shared by every tenant. Yodlee scopes accounts
     * and transactions to the login name, so a client that defaulted it would serve one customer's
     * bank accounts to another.
     */
    public static function for(FinYodleeSetting $settings, FinYodleeUser $user): self
    {
        return new self($settings, $user);
    }

    /** A token for this tenant's own Yodlee user — the one that reads accounts and transactions. */
    public function userToken(): string
    {
        return $this->token($this->user->login_name);
    }

    /**
     * A token to hand FastLink so the widget can act as this user.
     *
     * Same token, named separately because it leaves the server: it is posted to Yodlee's hosted
     * widget in the browser. That is how FastLink is designed to work, and it is the reason the
     * token is short-lived and scoped to one end user rather than being the subscription secret.
     */
    public function fastlinkToken(): string
    {
        return $this->userToken();
    }

    /**
     * Every account the user has linked, across all their banks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accounts(?string $providerAccountId = null): array
    {
        $response = $this->request()
            ->get('/accounts', array_filter([
                'providerAccountId' => $providerAccountId,
                'status'            => 'ACTIVE',
            ]));

        return $this->json($response)['account'] ?? [];
    }

    /**
     * One account's transactions for a date range.
     *
     * Paged deliberately rather than asked for in one call: Yodlee caps a response at 500 and
     * silently returns the first page, so a first sync over a year of history would import January
     * and quietly lose the rest.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transactions(string $accountId, Carbon $from, Carbon $to): array
    {
        $all = [];
        $skip = 0;
        $top = 500;

        do {
            $response = $this->request()->get('/transactions', [
                'accountId' => $accountId,
                'fromDate'  => $from->toDateString(),
                'toDate'    => $to->toDateString(),
                'top'       => $top,
                'skip'      => $skip,
            ]);

            $page = $this->json($response)['transaction'] ?? [];
            $all = array_merge($all, $page);
            $skip += $top;

            // A short page is the last page. Guarded against a runaway loop as well, because an API
            // that always returns a full page would otherwise spin forever.
        } while (count($page) === $top && $skip < 20000);

        return $all;
    }

    /**
     * Prove the stored credentials work, without changing anything.
     *
     * Used by the settings screen's Test connection button, because the alternative is discovering
     * a typo in the client id at the moment somebody tries to link their bank.
     */
    public function verify(): bool
    {
        $this->forgetToken();
        $this->userToken();

        return true;
    }

    /** Yodlee needs the user to exist before a token can be minted for them. */
    public function ensureUser(): void
    {
        try {
            $this->userToken();

            return;   // already registered
        } catch (RuntimeException) {
            // Falls through to registration below.
        }

        $admin = $this->token(null);

        $response = Http::asJson()
            ->withHeaders([
                'Api-Version'   => self::API_VERSION,
                'Authorization' => "Bearer {$admin}",
            ])
            ->post($this->settings->apiBaseUrl() . '/user/register', [
                'user' => [
                    'loginName' => $this->user->login_name,
                    'locale'    => 'en_US',
                ],
            ]);

        if (! $response->successful()) {
            $this->user->update(['last_error' => \Illuminate\Support\Str::limit($this->errorText($response), 240)]);

            throw new RuntimeException('Could not register the Yodlee user: ' . $this->errorText($response));
        }

        $this->user->markRegistered();
        $this->forgetToken();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->settings->apiBaseUrl())
            ->timeout(45)
            ->retry(2, 1000, throw: false)
            ->withHeaders([
                'Api-Version'   => self::API_VERSION,
                'Authorization' => 'Bearer ' . $this->userToken(),
                'Accept'        => 'application/json',
            ]);
    }

    /**
     * A bearer token, cached.
     *
     * A null login name asks for the subscription's own token, which is what registering a user
     * needs — everything else acts as the end user.
     */
    private function token(?string $loginName): string
    {
        if (! $this->settings->isConfigured()) {
            throw new RuntimeException('Yodlee is not configured for this company. Add the client ID and secret in Accounting settings.');
        }

        return Cache::remember($this->tokenKey($loginName), self::TOKEN_TTL_SECONDS, function () use ($loginName) {
            $response = Http::asForm()
                ->timeout(30)
                ->withHeaders(array_filter([
                    'Api-Version' => self::API_VERSION,
                    'loginName'   => $loginName,
                ]))
                ->post($this->settings->apiBaseUrl() . '/auth/token', [
                    'clientId' => $this->settings->clientId(),
                    'secret'   => $this->settings->secret(),
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Yodlee refused the credentials: ' . $this->errorText($response));
            }

            $token = $response->json('token.accessToken');

            if (! $token) {
                throw new RuntimeException('Yodlee returned no access token. Check that the client ID belongs to this environment.');
            }

            return $token;
        });
    }

    private function tokenKey(?string $loginName): string
    {
        /*
         * Keyed on the login name, which is unique platform-wide.
         *
         * Not on the settings row: with one subscription behind every tenant that is the same value
         * for all of them, and the cached token would be handed to whoever asked next — which on
         * this API is a view of somebody else's bank accounts.
         */
        return 'yodlee:token:' . ($loginName ?: 'admin:' . ($this->settings->id ?? 'new'));
    }

    private function forgetToken(): void
    {
        Cache::forget($this->tokenKey($this->user->login_name));
        Cache::forget($this->tokenKey(null));
    }

    /** @return array<string, mixed> */
    private function json($response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Yodlee request failed: ' . $this->errorText($response));
        }

        return $response->json() ?? [];
    }

    /**
     * Yodlee's own error code and reference, which is what their support desk asks for.
     */
    private function errorText($response): string
    {
        $body = $response->json() ?? [];
        $code = $body['errorCode'] ?? null;
        $message = $body['errorMessage'] ?? $response->body();
        $reference = $body['referenceCode'] ?? null;

        return trim(sprintf(
            'HTTP %d%s — %s%s',
            $response->status(),
            $code ? " (Yodlee {$code})" : '',
            \Illuminate\Support\Str::limit((string) $message, 180),
            $reference ? " [ref {$reference}]" : '',
        ));
    }
}
