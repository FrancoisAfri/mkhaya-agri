<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use App\Modules\Operations\WarehouseManagement\Services\WarehouseKpis;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/** Keep last month's KPI figures, per company (§33). Run on the 1st; running again adds nothing. */
class KpiSnapshotCommand extends Command
{
    protected $signature = 'wms:kpi-snapshot {--company=} {--period= : YYYY-MM, default last month} {--sync}';

    protected $description = 'Store the month\'s warehouse KPI figures';

    public function handle(TenantContext $tenant, WarehouseKpis $kpis): int
    {
        $period = $this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m');
        $one = fn () => $kpis->snapshot((int) app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id(), $period);
        if ($cid = $this->option('company')) {
            $this->info("Company {$cid}, {$period}: ".$tenant->run((int) $cid, $one).' figure(s) stored.');
        } elseif ($this->option('sync')) {
            $tenant->eachCompany($one);
        } else {
            $this->info('Queued KPI snapshots for '.$tenant->dispatchEachCompany('wms:kpi-snapshot', ['--period' => $period]).' company(ies).');
        }

        return self::SUCCESS;
    }
}
