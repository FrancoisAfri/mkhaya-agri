<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\AccessOverrideService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Close the overrides whose window has shut (CON-17).
 *
 * The requirement says an override must automatically expire, and the implementation this
 * replaces never did: nothing in the codebase ever set `access_overridden` back to false. The
 * only expiry was a date comparison at read time, which the compliance file did and the cached
 * `access_status` column did not — and the turnstile reads the cache. So an override that ran
 * out on Friday went on admitting people until something unrelated happened to recompute it.
 *
 * Two things happen here, and the second is the one that matters.
 *
 * The lapse is **stamped**, so "it expired" is a recorded fact with a time on it rather than a
 * comparison every reader has to redo and can get wrong differently.
 *
 * And the contractor's cached access status is **recomputed**, exactly once, at the moment the
 * override stops counting. That is what actually shuts the gate.
 *
 * Hourly rather than nightly: an override granted to 18:00 that keeps working until 02:30 the
 * next morning is eight and a half hours of a gate nobody meant to leave open, and the whole
 * point of a window with an end on it is that the end means something.
 */
class ExpireOverridesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:overrides:expire
                            {--company= : Restrict to one company id}';

    protected $description = 'Close temporary access overrides whose window has shut, and put the gate back';

    public function handle(TenantContext $tenants, AccessOverrideService $overrides): int
    {
        $total = 0;

        $this->eachTenant($tenants, function (int $companyId) use ($overrides, &$total): void {
            $closed = $overrides->expireLapsed($companyId);

            $total += $closed;

            if ($closed > 0) {
                $this->line("  company {$companyId}: expired {$closed}");
            }
        });

        $this->info($total === 0
            ? 'No overrides have lapsed since the last run.'
            : "Expired {$total} overrides and put those gates back.");

        return self::SUCCESS;
    }
}
