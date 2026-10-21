<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Processes\CropSeasonProcess;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Daily: when a season's planning date arrives, start the crop production cycle on every farm with
 * crop fields — once per farm and season. Each organisation in turn.
 */
class StartSeasonProcesses extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:start-seasons {--company= : Only this organisation}';

    protected $description = 'Start the crop season process on each farm when the season planning date arrives';

    public function handle(TenantContext $tenants): int
    {
        $this->eachTenant($tenants, function (int $companyId) {
            $this->line("Company {$companyId}: ".CropSeasonProcess::startDue().' season process(es) started.');
        });

        return self::SUCCESS;
    }
}
