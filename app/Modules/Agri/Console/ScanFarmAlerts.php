<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Daily: raise the farm alerts that need somebody (low stock, services, expiring permits, overdue
 * tasks, withdrawal periods, scouting, harvests, budgets) and clear the ones that no longer apply.
 * Each organisation in turn, so one failing tenant does not stop the rest.
 */
class ScanFarmAlerts extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:scan-alerts {--company= : Only this organisation}';

    protected $description = 'Raise, clear and email Mkhaya Agri farm alerts for every organisation';

    public function handle(TenantContext $tenants, FarmAlerts $alerts, \App\Modules\Agri\Services\AgriNotifier $notifier): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($alerts, $notifier) {
            $r = $alerts->scan($companyId);
            $escalated = app(\App\Modules\Agri\Services\Scouting::class)->escalateOverdue($companyId);
            // Emails go from the scheduled scan only, never from a dashboard visit's quick refresh.
            $m = $notifier->alerts($companyId);
            $this->line("Company {$companyId}: {$r['farms']} farm(s), {$r['raised']} raised, {$r['resolved']} cleared; {$m['alerts']} alert(s) emailed to {$m['people']} person(s); {$escalated} scouting observation(s) escalated.");
        });

        return self::SUCCESS;
    }
}
