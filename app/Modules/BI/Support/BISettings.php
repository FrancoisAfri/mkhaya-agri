<?php

namespace App\Modules\BI\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform-wide Business Intelligence switch, set by a platform administrator on
 * /bi/platform (never in .env).
 *
 *   included = true  → BI is part of every company's system (the default).
 *   included = false → BI is a paid add-on: only companies given it in the company/module
 *                      screens (or, self-hosted, by their licence) have it.
 *
 * One row in bi_settings, cached until it changes.
 */
final class BISettings
{
    private const CACHE = 'bi_settings_included';

    public static function included(): bool
    {
        return Cache::rememberForever(self::CACHE, function () {
            try {
                if (! Schema::hasTable('bi_settings')) {
                    return true;
                }
                $v = DB::table('bi_settings')->orderBy('id')->value('included');

                return $v === null ? true : (bool) $v;
            } catch (\Throwable) {
                return true;
            }
        });
    }

    public static function set(bool $included, ?int $userId = null): void
    {
        $id = DB::table('bi_settings')->orderBy('id')->value('id');
        $row = ['included' => $included, 'updated_by' => $userId, 'updated_at' => now()];
        $id ? DB::table('bi_settings')->where('id', $id)->update($row) : DB::table('bi_settings')->insert($row + ['created_at' => now()]);
        Cache::forget(self::CACHE);
    }

    /** Who changed it last, and when — for the settings screen. */
    public static function row(): ?object
    {
        return Schema::hasTable('bi_settings') ? DB::table('bi_settings')->orderBy('id')->first() : null;
    }
}
