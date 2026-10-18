<?php

namespace App\Modules\Operations\Sheq\Http\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RFC 9457 problem details — the SHEQ API's single error shape (SHEQ-API-4).
 *
 * One shape for every failure, because the alternative is what most APIs actually ship: a validation
 * error with `errors.field[]`, a 403 with `message`, a 500 with `error`, and a rate limit with
 * something else again. An integrator then writes four parsers, gets three of them right, and
 * discovers the fourth in production.
 *
 * The `type` URI is the part that makes this worth doing rather than just returning JSON. It is a
 * stable identifier a client can branch on — `.../permit-not-active` means the same thing next year
 * even if we rewrite the sentence in `detail`. Clients that match on message strings break every
 * time somebody improves the wording; this gives them something safe to match on instead.
 *
 * `detail` is written for a human debugging at 2am, and says what to do rather than only what went
 * wrong. "Permit PTW-2026-0031 is SUSPENDED, so work cannot be logged against it" beats
 * "invalid state".
 */
class ProblemDetails
{
    /** The documentation base. A client can fetch the type URI and get a description. */
    private const BASE = 'https://docs.mkhaya.co.za/sheq/problems/';

    /**
     * Build a problem response.
     *
     * @param  array<string,mixed>  $extensions  extra members, per RFC 9457 §3.2
     */
    public static function make(int $status, string $type, string $title,
        string $detail, array $extensions = []): JsonResponse
    {
        return response()->json([
            'type' => self::BASE.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ] + $extensions, $status, [
            // The registered media type. A client content-negotiating on it can tell a problem from
            // a successful body without inspecting the payload.
            'Content-Type' => 'application/problem+json',
        ]);
    }

    /**
     * Map any thrown exception to a problem.
     *
     * The default is deliberately vague — "an unexpected error" with a reference id — because the
     * alternative leaks a stack trace, a table name or a file path to whoever is holding a token.
     * The reference is what support asks for, and it ties back to the log line that has the detail.
     */
    public static function fromException(Throwable $e, ?string $reference = null): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::make(422, 'validation-failed', 'Your request did not validate',
                'One or more fields were missing or malformed. See `errors` for which.',
                // Kept as an extension member rather than replacing the shape: a client parsing
                // problem details still works, and one that wants per-field detail has it.
                ['errors' => $e->errors()]);
        }

        // Thrown by `auth:sanctum`, and not an HttpException — so without this it would fall
        // through to the 500 branch and report an outage every time a token expired.
        if ($e instanceof AuthenticationException) {
            return self::make(401, 'unauthenticated', 'Your token was not accepted',
                'The bearer token is missing, expired or revoked.');
        }

        if ($e instanceof AuthorizationException) {
            return self::make(403, 'forbidden', 'You may not do this',
                $e->getMessage() !== '' ? $e->getMessage()
                    : 'The token is valid but not permitted here.');
        }

        /*
         * A refused business rule, not a crash.
         *
         * Every service in this module signals a refusal by throwing one of these with a message
         * written for the person reading it — "only 2 of Safety boots left in stock", "you raised
         * this request, so you cannot approve it". Falling through to the 500 branch would report
         * those to a client as a server error: it would retry, and support would investigate an
         * outage that never happened.
         *
         * The narrowness matters. This module never throws RuntimeException for an internal
         * failure — those surface as QueryException, TypeError and the like, which still reach the
         * 500 branch and are still logged with a reference.
         */
        if ($e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
            return self::refused('rule-refused', $e->getMessage());
        }

        if ($e instanceof ModelNotFoundException) {
            return self::make(404, 'not-found', 'No such record',
                'Nothing with that identifier exists in your company.');
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();

            // The entitlement convention used throughout the module: `abort(403,
            // 'ENTITLEMENT_REQUIRED: sheq.permit.approve')`. Turning it into a machine-readable
            // member means a client can tell "you lack this permission" from "this is forbidden
            // for another reason", which is the difference between a useful UI and a dead end.
            if ($status === 403 && str_starts_with($message, 'ENTITLEMENT_REQUIRED:')) {
                $permission = trim(substr($message, strlen('ENTITLEMENT_REQUIRED:')));

                return self::make(403, 'entitlement-required', 'You do not have this permission',
                    "This endpoint requires the `{$permission}` permission, which this token's "
                    .'user does not hold.', ['required_permission' => $permission]);
            }

            return match ($status) {
                401 => self::make(401, 'unauthenticated', 'Your token was not accepted',
                    'The bearer token is missing, expired or revoked.'),
                403 => self::make(403, 'forbidden', 'You may not do this',
                    $message !== '' ? $message : 'The token is valid but not permitted here.'),
                404 => self::make(404, 'not-found', 'No such record',
                    'Nothing with that identifier exists in your company. Identifiers are scoped '
                    .'to a tenant, so a valid id from elsewhere reads as missing here.'),
                409 => self::make(409, 'conflict', 'That would conflict with the current state',
                    $message !== '' ? $message : 'The record changed since you read it.'),
                422 => self::make(422, 'unprocessable', 'The request was understood but refused',
                    $message !== '' ? $message : 'A business rule rejected it.'),
                429 => self::make(429, 'rate-limited', 'Too many requests',
                    'Slow down and retry after the interval in the Retry-After header.'),
                default => self::make($status, 'http-error', 'Request failed',
                    $message !== '' ? $message : 'The request could not be completed.'),
            };
        }

        return self::make(500, 'internal-error', 'Something went wrong on our side',
            'This has been logged. Quote the reference when contacting support.',
            array_filter(['reference' => $reference]));
    }

    /** A business rule refused the request — the most common non-trivial failure. */
    public static function refused(string $type, string $detail, array $extensions = []): JsonResponse
    {
        return self::make(422, $type, 'The request was understood but refused', $detail, $extensions);
    }
}
