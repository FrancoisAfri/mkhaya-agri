<?php

namespace App\Modules\Operations\ProjectManagement\Concerns;

use Illuminate\Support\Str;

/**
 * PM-ADR-003 / PM-ADR-004 — the two-identifier rule.
 *
 * `id` (bigint) is internal and never leaves the server. `uuid` (UUIDv7) is the only public
 * identifier: it appears in URLs, API payloads and exports, and a field device may generate it
 * offline and send it with the record.
 *
 * A client-supplied uuid is stored verbatim — never regenerated — because silently replacing it
 * is exactly the remapping problem PM-ADR-004 exists to avoid. Validation of the supplied value
 * belongs in the form request; by the time a model is saved the value is trusted.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            // v7, not v4: the timestamp prefix keeps index locality on insert-heavy tables and
            // makes an offline batch coarsely sortable by creation time.
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** Route binding is on the public id, so an internal id never appears in a URL. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
