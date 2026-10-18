<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use Illuminate\Support\Str;

/**
 * The request's correlation id.
 *
 * One id, generated once and reused for every audit row and log line the request produces, so a
 * question like "what else happened in the request that changed this budget?" is answerable by
 * filtering rather than by guessing from timestamps.
 *
 * Honours an inbound `X-Request-Id` when the caller supplies one — a mobile client or an upstream
 * gateway that already has a trace id keeps it, rather than the trail breaking at our door.
 * Registered as a singleton, so the id is stable for the life of the request.
 */
class RequestContext
{
    private ?string $requestId = null;

    public function requestId(): string
    {
        return $this->requestId ??= (string) Str::uuid();
    }

    /** Adopt an id supplied by the caller. Ignored if it does not look like an id. */
    public function adopt(?string $incoming): void
    {
        $incoming = trim((string) $incoming);

        if ($incoming !== '' && strlen($incoming) <= 64 && preg_match('/^[A-Za-z0-9._:-]+$/', $incoming)) {
            $this->requestId = $incoming;
        }
    }
}
