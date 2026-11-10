<?php

namespace App\Modules\BI\Support;

/**
 * Ready-made dashboards, read from app/Modules/BI/Templates/*.php. Each file returns
 *
 *   'template_key' => ['name' => '…', 'group' => 'Finance', 'description' => '…', 'widgets' => [...]]
 *
 * built with the Tpl helpers. A template needs no setup: it is opened as it is, and the modules it
 * draws on are worked out from its widgets' datasets.
 */
final class TemplateRegistry
{
    private static ?array $all = null;

    /** @return array<string, array> */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }
        $all = [];
        foreach (glob(app_path('Modules/BI/Templates/*.php')) ?: [] as $file) {
            try {
                $defs = (array) require $file;
            } catch (\Throwable $e) {
                report($e);
                continue;
            }
            foreach ($defs as $key => $t) {
                $widgets = collect($t['widgets'] ?? [])->values()->map(fn ($w, $i) => $w + ['id' => 'w' . ($i + 1)])->all();
                $modules = collect($widgets)->map(fn ($w) => DatasetRegistry::get($w['config']['dataset'] ?? '')['module'] ?? null)->filter()->unique()->values()->all();
                $all[$key] = ['key' => $key, 'name' => $t['name'], 'group' => $t['group'] ?? 'General', 'description' => $t['description'] ?? '',
                    'icon' => $t['icon'] ?? 'tabler-layout-dashboard', 'widgets' => $widgets, 'modules' => $modules];
            }
        }

        return self::$all = $all;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
