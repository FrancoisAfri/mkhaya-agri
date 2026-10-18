<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\ExpiryWatch;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/** The daily expiry check, per company (WMS-093). */
class ExpiryCommand extends Command
{
    protected $signature = 'wms:expiry {--company= : Only this company} {--sync : Run companies inline instead of queueing one job each}';

    protected $description = 'Warn about lots crossing their expiry warning points';

    public function handle(TenantContext $tenant, ExpiryWatch $watch): int
    {
        if ($cid = $this->option('company')) {
            $this->info("Company {$cid}: ".$tenant->run((int) $cid, fn () => $watch->alert()).' warning(s).');

            return self::SUCCESS;
        }
        if ($this->option('sync')) {
            $tenant->eachCompany(fn () => $watch->alert());

            return self::SUCCESS;
        }
        $this->info('Queued expiry checks for '.$tenant->dispatchEachCompany('wms:expiry').' company(ies).');

        return self::SUCCESS;
    }
}
