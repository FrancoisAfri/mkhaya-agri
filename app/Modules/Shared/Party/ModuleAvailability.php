<?php

namespace App\Modules\Shared\Party;

use App\Modules\Tenancy\Services\CompanyEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Is a module usable right now, for this tenant?
 *
 * Two gates, both required — the same pair EnsureModuleIsActive applies to
 * routes: the module must be switched on in the global registry, and the
 * current company's plan (SaaS) or signed licence (self-hosted) must include it.
 * The registry flag alone is shared across tenants, so checking only that would
 * let a company that dropped a module keep using it through the back door.
 *
 * Services use this to degrade rather than fail: a caller asks whether
 * Accounting is available and takes the null path if not, instead of importing
 * an Accounting class and discovering at runtime that the tenant never bought it.
 */
class ModuleAvailability
{
    /** @var array<string, bool> resolved once per request */
    private array $cache = [];

    public function active(string $code): bool
    {
        if (array_key_exists($code, $this->cache)) {
            return $this->cache[$code];
        }

        return $this->cache[$code] = $this->resolve($code);
    }

    /** True only if every listed module is available. */
    public function allActive(string ...$codes): bool
    {
        foreach ($codes as $code) {
            if (! $this->active($code)) {
                return false;
            }
        }

        return true;
    }

    private function resolve(string $code): bool
    {
        if (! Schema::hasTable('security_modules')) {
            return false;
        }

        $registered = DB::table('security_modules')
            ->where('code_name', $code)
            ->where('active', 1)
            ->exists();

        if (! $registered) {
            return false;
        }

        // No current company (console, platform admin) → unrestricted, so
        // backfills and CLI work.
        return app(CompanyEntitlements::class)->currentAllowsModule($code);
    }

    /** Test seam / long-running workers: drop the per-instance memo. */
    public function flush(): void
    {
        $this->cache = [];
    }
}
