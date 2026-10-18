<?php

namespace App\Support\Hubs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Resolves the feature cards that live behind a hub ribbon, so hub-aware tooling (e.g. the how-to
 * enricher) can generate granular per-feature content even though the nav now shows only the hub.
 * Card display titles are read from the English locale file (the same source the UI uses), falling
 * back to a humanised key.
 */
class HubCardCatalog
{
    /**
     * The feature cards for a module's hub ribbon (matched by path). Empty if the module isn't
     * hub-managed or the path isn't a hub (e.g. a Dashboard / direct ribbon).
     *
     * @return array<int, array{key:string, title:string, path:string}>
     */
    public static function cards(string $moduleCode, string $ribbonPath): array
    {
        $class = HubRegistry::for($moduleCode);
        if (! $class) {
            return [];
        }

        $ribbonPath = trim($ribbonPath, '/');
        foreach ($class::hubs() as $hub) {
            if (trim($hub['path'], '/') === $ribbonPath) {
                return array_map(fn ($c) => [
                    'key'   => $c['key'],
                    'title' => self::title($c['title'] ?? '') ?? Str::headline($c['key']),
                    'path'  => $c['path'],
                ], $hub['cards']);
            }
        }

        return [];
    }

    /** Resolve a dotted i18n key (e.g. "crm.cards.leads.title") to its English string. */
    private static function title(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        $v = self::locale();
        foreach (explode('.', $key) as $p) {
            if (! is_array($v) || ! array_key_exists($p, $v)) {
                return null;
            }
            $v = $v[$p];
        }

        return is_string($v) ? $v : null;
    }

    private static function locale(): array
    {
        return Cache::rememberForever('howto_hub_locale_en', function () {
            $path = resource_path('js/utils/locales/modules/en.json');

            return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        });
    }
}
