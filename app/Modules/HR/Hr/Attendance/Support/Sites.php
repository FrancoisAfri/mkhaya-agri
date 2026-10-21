<?php

namespace App\Modules\HR\Hr\Attendance\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Where time-and-attendance work happens, as far as the engine needs to know.
 *
 * The engine is the same for an office and a farm. A record may name a site (farm_id): the module
 * that owns sites (Mkhaya Agri's farms) registers how to read a site's time zone, which sites the
 * signed-in person may reach, and the models its records point at (the site, a field, a job). With
 * nothing registered, every record is on the organisation's own clock and nobody is limited by site.
 */
class Sites
{
    /** @var (callable(?int): ?string)|null */
    private static $zone = null;

    /** @var (callable(): ?array)|null  null = every site; [] = none */
    private static $access = null;

    /** @var array<string, class-string<Model>> */
    private static array $models = [];

    /** @var (callable(Model): void)|null  refuses a site that is not working (archived, dormant) */
    private static $operational = null;

    public static function register(callable $zone, callable $access, array $models, ?callable $operational = null): void
    {
        self::$zone = $zone;
        self::$access = $access;
        self::$models = $models;
        self::$operational = $operational;
    }

    /** @var array{can_write?: callable(\App\Core\User\Models\User, int): bool, options?: callable(bool): array} */
    private static array $hooks = [];

    /** What screens need besides the basics: may this person change records on a site; the sites (with fields) to choose from. */
    public static function hooks(array $hooks): void
    {
        self::$hooks = $hooks;
    }

    public static function canWrite(\App\Core\User\Models\User $user, ?int $siteId): bool
    {
        return ! $siteId || ! isset(self::$hooks['can_write']) || (self::$hooks['can_write'])($user, $siteId);
    }

    /** @return list<array{id: string, code: ?string, name: string, fields: list<array>}> */
    public static function options(bool $operationalOnly = false): array
    {
        return isset(self::$hooks['options']) ? (self::$hooks['options'])($operationalOnly) : [];
    }

    public static function byUuid(?string $uuid): ?Model
    {
        return $uuid && isset(self::$models['site']) ? (self::$models['site'])::query()->where('uuid', $uuid)->first() : null;
    }

    /** Refuse new work on a site that is not operational; no site is always fine. */
    public static function assertOperational(?Model $site): void
    {
        if ($site && self::$operational) {
            (self::$operational)($site);
        }
    }

    public static function find(?int $siteId): ?Model
    {
        return $siteId && isset(self::$models['site']) ? (self::$models['site'])::withoutGlobalScopes()->find($siteId) : null;
    }

    /** A wall-clock time on a date at the site ("06:00" on the 21st, on the farm's clock). */
    public static function at(string $date, string $time, Model|int|null $site = null): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse("{$date} {$time}", self::zone(self::idOf($site)));
    }

    /** The moment in the site's zone. */
    public static function local(?\DateTimeInterface $at, Model|int|null $site = null): ?\Illuminate\Support\Carbon
    {
        return $at ? \Illuminate\Support\Carbon::instance($at)->setTimezone(self::zone(self::idOf($site))) : null;
    }

    private static function idOf(Model|int|null $site): ?int
    {
        if ($site instanceof Model) {
            $site = isset(self::$models['site']) && $site instanceof self::$models['site'] ? $site->getKey() : $site->getAttribute('farm_id');
        }

        return $site ? (int) $site : null;
    }

    public static function zone(?int $siteId = null): string
    {
        return (self::$zone ? (self::$zone)($siteId) : null) ?: config('app.business_timezone', 'Africa/Johannesburg');
    }

    /** The sites the signed-in person may reach: null when not limited by site. */
    public static function accessible(): ?array
    {
        return self::$access ? (self::$access)() : null;
    }

    /** @return class-string<Model> the model a record's site, field, job… points at */
    public static function model(string $kind): string
    {
        return self::$models[$kind] ?? throw new \LogicException("No {$kind} model is registered for time and attendance.");
    }
}
