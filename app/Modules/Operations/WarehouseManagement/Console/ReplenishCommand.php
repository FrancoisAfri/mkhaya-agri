<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\Replenishment;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * Generate replenishment work for every company (WMS-052). Fans out per company, so each run is
 * inside one tenant's context and creates rows tagged to it -- a scheduled command that ran
 * untenanted would read nothing and report success.
 */
class ReplenishCommand extends Command
{
    protected $signature = 'wms:replenish {--company= : Only this company} {--sync : Run companies inline instead of queueing one job each}';

    protected $description = 'Create replenishment tasks and transfer orders for pick faces and warehouses below their minimum';

    public function handle(TenantContext $tenant, Replenishment $replenishment): int
    {
        if ($cid = $this->option('company')) {
            $n = $tenant->run((int) $cid, fn () => $replenishment->generate());
            $this->info("Company {$cid}: {$n} replenishment task(s).");

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $tenant->eachCompany(fn ($cid) => $replenishment->generate());

            return self::SUCCESS;
        }

        $this->info('Queued replenishment for '.$tenant->dispatchEachCompany('wms:replenish').' company(ies).');

        return self::SUCCESS;
    }
}
