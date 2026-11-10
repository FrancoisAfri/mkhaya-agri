<?php

namespace App\Modules\BI\Support;

use App\Modules\Tenancy\Services\CompanyEntitlements;
use Illuminate\Support\Facades\DB;

/**
 * Who may see what in BI.
 *
 *   1. BI is a paid module: the company must have `business_intelligence`.
 *   2. Each visual draws on one dataset, owned by a module: the company must subscribe to it.
 *   3. The person must have access to that module (security_module_access, level 1+).
 *   4. Row-level security keeps every query inside the company anyway.
 *
 * Designing (new dashboards, copying a template, editing) needs BI access level 4 (Admin) or 5
 * (SuperUser) — the organisation's administrators and senior executives. Everyone else uses the
 * templates and the dashboards shared with them.
 */
final class BIAccess
{
    public const MODULE = 'business_intelligence';
    public const DESIGN_LEVEL = 4;

    private static array $moduleIds = [];

    public static function companyHasBi(): bool
    {
        return app(CompanyEntitlements::class)->currentAllowsModule(self::MODULE);
    }

    public static function isPlatform($user): bool
    {
        return (bool) ($user?->is_platform_admin || $user?->is_master_admin);
    }

    /** May this person open BI at all? */
    public static function canView($user): bool
    {
        return $user && self::companyHasBi() && (self::isPlatform($user) || self::level($user, self::MODULE) >= 1);
    }

    /** May this person build and edit dashboards? */
    public static function canDesign($user): bool
    {
        return $user && self::companyHasBi() && (self::isPlatform($user) || self::level($user, self::MODULE) >= self::DESIGN_LEVEL);
    }

    /** Null when the visual may be drawn, else why not: 'subscription' or 'permission'. */
    public static function blocked(string $dataset, $user): ?string
    {
        $ds = DatasetRegistry::get($dataset);
        if (! $ds) {
            return 'unknown';
        }
        if (! app(CompanyEntitlements::class)->allowsNavModule($ds['module'])) {
            return 'subscription';
        }
        if (! self::isPlatform($user) && self::moduleId($ds['module']) && self::level($user, $ds['module']) < 1) {
            return 'permission';
        }

        return null;
    }

    /** Datasets this person may use, for the visual settings panel. */
    public static function datasetsFor($user): array
    {
        return collect(DatasetRegistry::all())->filter(fn ($ds, $k) => self::blocked($k, $user) === null)->all();
    }

    private static function moduleId(string $code): ?int
    {
        return self::$moduleIds[$code] ??= (int) DB::table('security_modules')->where('code_name', $code)->value('id') ?: null;
    }

    /** The person's raw access level on a module. */
    public static function rawLevel($user, string $code): int
    {
        $id = self::moduleId($code);

        return $id && $user ? (int) DB::table('security_module_access')->where('user_id', $user->id)->where('module_id', $id)->where('active', 1)->value('access_level') : 0;
    }

    private static function level($user, string $code): int
    {
        $id = self::moduleId($code);
        if (! $id || ! $user) {
            return $id ? 0 : 1; // a module with no access screen (core) is open to everyone signed in
        }

        // Access lives on security_module_access (the shared ModuleService reads an older table here).
        $level = (int) DB::table('security_module_access')->where('user_id', $user->id)->where('module_id', $id)->where('active', 1)->value('access_level');

        return $level >= self::DESIGN_LEVEL ? self::DESIGN_LEVEL : ($level >= 1 ? 1 : 0);
    }
}
