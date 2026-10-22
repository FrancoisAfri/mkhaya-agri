<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\WaterBalance;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Daily, after the weather: every irrigated zone's soil-water balance from ET0, crop coefficients,
 * rain, irrigation given and soil-moisture readings, and the week's irrigation recommendations.
 * The farm alert scan that runs after it raises "irrigation due" where a zone is past its threshold
 * with nothing planned.
 */
class ComputeWaterBalance extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:water-balance {--company= : Only this organisation} {--farm= : Only this farm (code)}';

    protected $description = 'Compute Mkhaya Agri soil-water balances and irrigation recommendations from evapotranspiration';

    public function handle(TenantContext $tenants, WaterBalance $balance): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($balance) {
            $farms = Farm::withoutGlobalScope('farm_access')->where('company_id', $companyId)->where('status', Farm::ACTIVE)
                ->when($this->option('farm'), fn ($q, $code) => $q->where('code', $code))->orderBy('code')->get();
            foreach ($farms as $farm) {
                $r = $balance->computeFarm($farm);
                $this->line("{$farm->code}: {$r['scopes']} zone(s), {$r['days']} day(s), {$r['recommendations']} recommendation(s).");
            }
        });

        return self::SUCCESS;
    }
}
