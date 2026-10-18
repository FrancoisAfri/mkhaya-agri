<?php

namespace App\Modules\Operations\Manufacturing\Concerns;

use Illuminate\Support\Str;

/**
 * `id` stays internal; `uuid` is the only identifier that appears in a URL, a payload or an export.
 *
 * UUIDv7 rather than v4: the timestamp prefix keeps index locality on insert-heavy tables, which
 * matters here because material issues and production events arrive in bursts.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    /** Route binding is on the public id, so an internal id never reaches a URL. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
