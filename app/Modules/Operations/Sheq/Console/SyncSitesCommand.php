<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\SiteProvisioner;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Mirrors the tenant's company structure into SHEQ sites.
 *
 *   php artisan sheq:sites:sync
 *   php artisan sheq:sites:sync --company=1
 *
 * Safe to re-run: existing sites are matched on `company_unit_id`, only structure-owned fields are
 * refreshed, and anything the tenant configured in SHEQ — geofence, timezone, external reference —
 * is left alone.
 */
class SyncSitesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:sites:sync {--company= : Restrict to one company id}';

    protected $description = 'Create or refresh SHEQ sites from the company structure';

    public function handle(TenantContext $tenants, SiteProvisioner $provisioner): int
    {
        $only = $this->option('company') ? (int) $this->option('company') : null;

        $run = function (int $companyId) use ($provisioner): void {
            $counts = $provisioner->syncFromCompanyStructure($companyId);

            $this->components->twoColumnDetail(
                "Company {$companyId}",
                sprintf('%d created, %d updated, %d unchanged',
                    $counts['created'], $counts['updated'], $counts['unchanged'])
            );
        };

        if ($only !== null) {
            $tenants->run($only, fn () => $run($only));
        } else {
            $this->eachTenant($tenants, function ($companyId) use ($run) {
                if ($companyId !== null) {
                    $run((int) $companyId);
                }
            });
        }

        $this->components->info('SHEQ sites are in step with the company structure.');

        return self::SUCCESS;
    }
}
