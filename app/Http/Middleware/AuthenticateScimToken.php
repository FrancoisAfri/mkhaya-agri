<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a SCIM request and pins it to one tenant.
 *
 * A SCIM token is a standing credential held by an external system that can create accounts and
 * disable them. Two consequences shape this class:
 *
 * - **The tenant comes from the token, never from the request.** There is no company parameter on
 *   any SCIM route. A directory can only ever act on the company whose token it holds, so a
 *   misconfigured connector cannot reach into another customer's users.
 * - **The stored value is a hash.** Tokens are compared by SHA-256 lookup, so a database read
 *   yields nothing usable. It is also a constant-time-free comparison by construction — the lookup
 *   is on the hash, not on the secret.
 */
class AuthenticateScimToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $presented = $this->bearer($request);

        if ($presented === null) {
            return $this->unauthorised('A bearer token is required.');
        }

        $record = DB::table('scim_tokens')
            ->where('token_hash', hash('sha256', $presented))
            ->whereNull('deleted_at')->where('is_active', true)
            ->first(['id', 'company_id', 'expires_at']);

        if ($record === null) {
            return $this->unauthorised('The token was not recognised.');
        }

        if ($record->expires_at !== null && now()->greaterThan($record->expires_at)) {
            return $this->unauthorised('The token has expired.');
        }

        // Deliberately not awaited or batched: knowing when a connector last ran is how an
        // administrator notices provisioning silently stopped weeks ago.
        DB::table('scim_tokens')->where('id', $record->id)
            ->update(['last_used_at' => now()]);

        $request->attributes->set('scim_company_id', (int) $record->company_id);

        return $next($request);
    }

    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        return str_starts_with($header, 'Bearer ')
            ? trim(substr($header, 7)) ?: null
            : null;
    }

    private function unauthorised(string $detail): Response
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'status' => '401',
            'detail' => $detail,
        ], 401, ['Content-Type' => 'application/scim+json']);
    }
}
