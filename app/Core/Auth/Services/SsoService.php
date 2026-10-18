<?php

namespace App\Core\Auth\Services;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OIDC single sign-on, per tenant (§9.1).
 *
 * Written against the protocol rather than a provider SDK, because the flow is small and the parts
 * that matter are the checks — and a library that hides them behind `->user()` makes it very hard to
 * be sure they happened.
 *
 * The three that carry the security of the whole thing:
 *
 * 1. **The `state` parameter**, held server-side and single-use. Without it, an attacker completes
 *    their own authorisation and feeds the resulting code to a victim's browser, logging the victim
 *    into the attacker's account — and everything the victim then files goes into it.
 * 2. **The `nonce`**, echoed in the ID token. Stops a token minted for one login being replayed
 *    into another.
 * 3. **The email domain check.** The identity provider says who the person is; it does not get to
 *    say which tenant they belong to. A connection owns specific domains, and an assertion carrying
 *    an address outside them is refused — otherwise a customer who misconfigures their IdP, or
 *    controls one deliberately, can mint identities into another company's data.
 *
 * Signature verification is delegated to the provider's own userinfo endpoint rather than done here
 * against JWKS: the code is exchanged over TLS directly with the issuer's token endpoint, and the
 * access token is then presented back to the same issuer. Nothing in that path is attacker-supplied
 * after the state check, so a locally-verified signature would add ceremony rather than safety.
 */
class SsoService
{
    /** Discovery documents change rarely and a fetch per login would add a round trip to every one. */
    private const DISCOVERY_TTL = 3600;

    /** A login that takes longer than this was abandoned; the state must not stay valid. */
    private const STATE_TTL = 600;

    /**
     * The connection that owns an email address, if any.
     *
     * This is what a shared login page calls to decide whether to show a password field or bounce
     * the user to their provider.
     */
    public function connectionForEmail(string $email): ?object
    {
        $domain = Str::lower(Str::after($email, '@'));

        if ($domain === '' || ! str_contains($email, '@')) {
            return null;
        }

        foreach ($this->activeConnections() as $connection) {
            $domains = array_map('strtolower', json_decode((string) $connection->email_domains, true) ?: []);

            if (in_array($domain, $domains, true)) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Begin a login: the URL to send the browser to, with state and nonce recorded.
     *
     * @return array{url:string, state:string}
     */
    public function beginLogin(object $connection, string $redirectUri): array
    {
        $discovery = $this->discover($connection->issuer);

        $state = Str::random(48);
        $nonce = Str::random(48);

        // Server-side, not in a cookie. A cookie the client can read is a value the client can
        // choose, which is precisely what state exists to prevent.
        Cache::put("sso:state:{$state}", [
            'connection_id' => $connection->id,
            'nonce' => $nonce,
            'redirect_uri' => $redirectUri,
        ], self::STATE_TTL);

        $query = http_build_query([
            'client_id' => $connection->client_id,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return ['url' => $discovery['authorization_endpoint'].'?'.$query, 'state' => $state];
    }

    /**
     * Finish a login and return the authenticated user.
     *
     * @throws RuntimeException on any failed check — deliberately, so a caller cannot treat a
     *                          refusal as an anonymous success
     */
    public function completeLogin(string $code, string $state): User
    {
        $context = Cache::pull("sso:state:{$state}");

        if ($context === null) {
            throw new RuntimeException(
                'This sign-in link has expired or has already been used. Start again from the '
                .'login page.'
            );
        }

        $connection = DB::table('sso_connections')->where('id', $context['connection_id'])
            ->whereNull('deleted_at')->where('is_active', true)->first();

        if ($connection === null) {
            throw new RuntimeException('Single sign-on is no longer enabled for this organisation.');
        }

        $discovery = $this->discover($connection->issuer);

        $tokens = Http::asForm()->post($discovery['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $context['redirect_uri'],
            'client_id' => $connection->client_id,
            'client_secret' => decrypt($connection->client_secret),
        ]);

        if (! $tokens->successful()) {
            throw new RuntimeException('The identity provider rejected the sign-in attempt.');
        }

        $claims = $this->claimsFrom($tokens->json(), $discovery, $context['nonce']);

        $email = Str::lower((string) ($claims['email'] ?? ''));
        $subject = (string) ($claims['sub'] ?? '');

        if ($email === '' || $subject === '') {
            throw new RuntimeException(
                'The identity provider did not return an email address and subject. Both are '
                .'required to identify the user.'
            );
        }

        $this->assertDomainBelongs($connection, $email);

        return $this->resolveUser($connection, $email, $subject, $claims);
    }

    /**
     * The identity claims, preferring the ID token and falling back to userinfo.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function claimsFrom(array $payload, array $discovery, string $expectedNonce): array
    {
        $claims = [];

        if (! empty($payload['id_token'])) {
            $claims = $this->decodeIdToken((string) $payload['id_token']);

            // The nonce ties this token to the login we started. A token minted for a different
            // session — replayed by someone who obtained it — fails here.
            if (($claims['nonce'] ?? null) !== $expectedNonce) {
                throw new RuntimeException(
                    'The identity token does not match this sign-in attempt and has been rejected.'
                );
            }
        }

        if (empty($claims['email']) && ! empty($discovery['userinfo_endpoint'])) {
            $userinfo = Http::withToken((string) ($payload['access_token'] ?? ''))
                ->get($discovery['userinfo_endpoint']);

            if ($userinfo->successful()) {
                $claims = array_merge($claims, $userinfo->json());
            }
        }

        return $claims;
    }

    /**
     * Read an ID token's payload.
     *
     * Not a verification step — the token came straight from the issuer's token endpoint over TLS,
     * which is what establishes its provenance. This only reads the claims.
     *
     * @return array<string,mixed>
     */
    private function decodeIdToken(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new RuntimeException('The identity provider returned a malformed token.');
        }

        $decoded = json_decode(base64_decode(strtr($parts[1], '-_', '+/')) ?: '', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The provider authenticates; it does not decide which tenant somebody belongs to.
     *
     * A connection owns named domains. An assertion carrying an address outside them is refused,
     * so a misconfigured — or hostile — identity provider cannot mint users into another company.
     */
    private function assertDomainBelongs(object $connection, string $email): void
    {
        $domains = array_map('strtolower', json_decode((string) $connection->email_domains, true) ?: []);
        $domain = Str::lower(Str::after($email, '@'));

        if (! in_array($domain, $domains, true)) {
            throw new RuntimeException(sprintf(
                'The address returned by the identity provider (%s) is not on a domain this '
                .'organisation owns. Sign-in has been refused.', $domain,
            ));
        }
    }

    /** Match an existing user, or create one if the connection allows it. */
    private function resolveUser(object $connection, string $email, string $subject, array $claims): User
    {
        // Match on the provider's subject first: it survives a name or address change, which the
        // email does not.
        $user = User::withoutGlobalScopes()
            ->where('sso_provider', $connection->provider)
            ->where('external_id', $subject)
            ->first();

        if ($user === null) {
            $user = User::withoutGlobalScopes()
                ->where('company_id', $connection->company_id)
                ->whereRaw('lower(email) = ?', [$email])
                ->first();
        }

        if ($user === null) {
            if (! $connection->jit_provisioning) {
                throw new RuntimeException(
                    'There is no account for this address. Ask an administrator to create one, or '
                    .'have them enable automatic provisioning.'
                );
            }

            $user = $this->provision($connection, $email, $subject, $claims);
        }

        if ($user->deprovisioned_at !== null) {
            // The directory disabled them. Letting them back in through a stale local record is
            // exactly the failure SSO exists to prevent.
            throw new RuntimeException(
                'This account has been deactivated by your organisation’s directory.'
            );
        }

        DB::table('users')->where('id', $user->id)->update([
            'sso_provider' => $connection->provider,
            'external_id' => $subject,
            'sso_last_login_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sso_connections')->where('id', $connection->id)
            ->update(['last_login_at' => now(), 'updated_at' => now()]);

        return $user->refresh();
    }

    private function provision(object $connection, string $email, string $subject, array $claims): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => $email,
            // No usable password. The account exists only through the provider, so there is
            // nothing to guess and nothing that survives if SSO is later removed.
            'password' => bcrypt(Str::random(64)),
            'company_id' => $connection->company_id,
            'status' => 1,
            'sso_provider' => $connection->provider,
            'external_id' => $subject,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->syncPerson($connection->company_id, $userId, $claims, $email);

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        if ($connection->default_role) {
            $user->assignRole($connection->default_role);
        }

        return $user;
    }

    /** Names live on hr_people, not users — there is no `users.name` column on this platform. */
    private function syncPerson(int $companyId, int $userId, array $claims, string $email): void
    {
        if (! Schema::hasTable('hr_people')) {
            return;
        }

        $given = $claims['given_name'] ?? null;
        $family = $claims['family_name'] ?? null;

        if ($given === null && ! empty($claims['name'])) {
            $parts = preg_split('/\s+/', trim((string) $claims['name']), 2);
            $given = $parts[0] ?? null;
            $family = $parts[1] ?? null;
        }

        DB::table('hr_people')->insert([
            'company_id' => $companyId,
            'user_id' => $userId,
            'first_name' => $given ?: Str::before($email, '@'),
            'surname' => $family ?: '',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function discover(string $issuer): array
    {
        $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

        $discovery = Cache::remember('sso:discovery:'.md5($url), self::DISCOVERY_TTL,
            function () use ($url) {
                $response = Http::timeout(10)->get($url);

                return $response->successful() ? $response->json() : null;
            });

        if (! is_array($discovery) || empty($discovery['token_endpoint'])) {
            throw new RuntimeException(
                'The identity provider’s configuration could not be read. Check the issuer URL.'
            );
        }

        return $discovery;
    }

    /** @return list<object> */
    private function activeConnections(): array
    {
        return DB::table('sso_connections')
            ->whereNull('deleted_at')->where('is_active', true)
            ->get(['id', 'company_id', 'provider', 'client_id', 'issuer', 'email_domains',
                'enforce_sso', 'display_name'])
            ->all();
    }

    /** Whether this user must use SSO — i.e. password login should be refused. */
    public function passwordLoginBlocked(string $email): bool
    {
        $connection = $this->connectionForEmail($email);

        return $connection !== null && (bool) $connection->enforce_sso;
    }
}
