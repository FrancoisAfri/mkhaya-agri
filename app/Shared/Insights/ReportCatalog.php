<?php

namespace App\Shared\Insights;

use App\Core\User\Models\User;

/**
 * Reports any module offers to Insights: what it is called, which module it belongs to (and so
 * which menu category lists it), who may run it, and how to build its rows for a period.
 *
 * build(User $as, string $from, string $to, array $params): array{columns: list<array{key: string, title: string}>, rows: list<array>}
 * is called as that person, inside their organisation, so a scheduled run shows only what they may see.
 */
class ReportCatalog
{
    /** @var array<string, array{title: string, description: string, module: string, ability: string|list<string>|null, build: callable, params?: array}> ability: every one is needed */
    private static array $reports = [];

    public static function register(string $key, string $title, string $description, string $module, string|array|null $ability, callable $build, array $params = []): void
    {
        self::$reports[$key] = compact('title', 'description', 'module', 'ability', 'build', 'params');
    }

    public static function get(string $key): ?array
    {
        return self::$reports[$key] ?? null;
    }

    /** @param  list<string>  $modules  module code names */
    public static function forModules(array $modules, ?User $user = null): array
    {
        return collect(self::$reports)->filter(fn ($r) => in_array($r['module'], $modules, true))
            ->filter(fn ($r) => ! $user || self::allows($r, $user))
            ->map(fn ($r, $k) => ['key' => $k, 'title' => $r['title'], 'description' => $r['description'], 'module' => $r['module'], 'params' => $r['params']])
            ->values()->all();
    }

    public static function mayRun(string $key, User $user): bool
    {
        $r = self::get($key);

        return $r !== null && self::allows($r, $user);
    }

    private static function allows(array $r, User $user): bool
    {
        return collect((array) $r['ability'])->filter()->every(fn ($a) => $user->can($a));
    }
}
