<?php

namespace App\Modules\Operations\ProjectManagement\Support\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RFC 7807 problem+json responses for the PM API (§4).
 *
 * One error shape for every failure, so a client writes one error handler instead of guessing
 * whether this endpoint returns `message`, `error`, or a bare string. Validation failures keep the
 * per-field detail under `errors`, which RFC 7807 explicitly allows as an extension member.
 */
class ProblemDetails
{
    /** Base for the `type` URI. Stable strings, so clients can branch on them. */
    private const TYPE_BASE = 'https://mkhaya.app/problems/';

    public static function make(
        int $status,
        string $title,
        ?string $detail = null,
        ?Request $request = null,
        array $extensions = [],
        ?string $type = null,
    ): Response {
        $body = array_filter([
            'type'     => self::TYPE_BASE . ($type ?? self::slug($title)),
            'title'    => $title,
            'status'   => $status,
            'detail'   => $detail,
            'instance' => $request?->path() ? '/' . ltrim($request->path(), '/') : null,
        ], fn ($v) => $v !== null);

        return response(
            json_encode($body + $extensions, JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/problem+json']
        );
    }

    /**
     * The three refusals a client actually branches on, named so a controller reads as prose.
     *
     * A missing record and a forbidden one both answer 404 by default (PM-1010): telling a caller
     * that a record exists but is not theirs confirms the record exists, which is the thing tenancy
     * is meant to hide. `forbidden()` is for the case where the caller can already see the record
     * and is being refused an action on it — there, saying so is useful rather than leaky.
     */
    public static function notFound(string $detail, ?Request $request = null): Response
    {
        return self::make(404, 'Not found', $detail, $request);
    }

    public static function forbidden(string $detail, ?Request $request = null): Response
    {
        return self::make(403, 'Forbidden', $detail, $request);
    }

    public static function unprocessable(string $detail, ?Request $request = null, array $extensions = []): Response
    {
        return self::make(422, 'Unprocessable', $detail, $request, $extensions);
    }

    /**
     * Translate an exception into a problem document.
     *
     * Deliberately explicit rather than a catch-all: an unhandled 500 must not leak an internal
     * message or a stack trace to an API client, but every expected failure should say something
     * a developer can act on.
     */
    public static function fromThrowable(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            return self::make(
                status: 422,
                title: 'Validation failed',
                detail: 'The request body did not pass validation.',
                request: $request,
                extensions: ['errors' => $e->errors()],
                type: 'validation-failed',
            );
        }

        if ($e instanceof AuthenticationException) {
            return self::make(
                status: 401,
                title: 'Unauthenticated',
                detail: 'A valid bearer token is required.',
                request: $request,
                type: 'unauthenticated',
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return self::make(
                status: $status,
                title: self::titleFor($status),
                // A 404 says nothing about whether the resource exists in another tenant — that is
                // the point of answering 404 rather than 403 for a cross-tenant id (PM-1010).
                detail: $e->getMessage() !== '' ? $e->getMessage() : null,
                request: $request,
            );
        }

        return self::make(
            status: 500,
            title: 'Server error',
            detail: config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
            request: $request,
            type: 'server-error',
        );
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            409 => 'Conflict',
            410 => 'Gone',
            422 => 'Validation failed',
            429 => 'Too many requests',
            default => $status >= 500 ? 'Server error' : 'Request failed',
        };
    }

    private static function slug(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    }
}
