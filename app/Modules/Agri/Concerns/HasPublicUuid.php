<?php

namespace App\Modules\Agri\Concerns;

use Illuminate\Support\Str;

/**
 * `id` stays internal; `uuid` is the only identifier that appears in a URL, a payload or an export.
 * UUIDv7 keeps index locality on tables that fill quickly (activities, events, movements).
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
