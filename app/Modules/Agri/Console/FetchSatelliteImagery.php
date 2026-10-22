<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\SatelliteProvider;
use App\Modules\Agri\Services\SatelliteImagery;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Daily: new satellite scenes for every watched field of every organisation that has switched a
 * provider on. Each field is one integration message, so a field whose fetch fails is reported,
 * waits for the retry, and the run moves on; once the organisation's daily budget is spent its
 * fetch stops until tomorrow. A drop in NDVI raises the usual imagery flag and scouting check.
 */
class FetchSatelliteImagery extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:satellite {--company= : Only this organisation} {--farm= : Only this farm (code)}';

    protected $description = 'Fetch new satellite NDVI scenes per field and zone for Mkhaya Agri';

    public function handle(TenantContext $tenants, SatelliteImagery $satellite): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($satellite) {
            $p = SatelliteProvider::activeFor($companyId);
            if (! $p) {
                $this->line("Company {$companyId}: no satellite provider switched on.");

                return;
            }
            $farms = Farm::withoutGlobalScope('farm_access')->where('company_id', $companyId)->where('status', Farm::ACTIVE)
                ->when($this->option('farm'), fn ($q, $code) => $q->where('code', $code))->orderBy('code')->get();
            foreach ($farms as $farm) {
                $r = $satellite->fetchFarm($farm, today()->toDateString());
                $this->line("{$farm->code}: {$r['fields']} field(s), {$r['scenes']} scene(s) stored, {$r['cloudy']} too cloudy, {$r['flags']} flag(s).");
                foreach ($r['failed'] as $f) {
                    $this->error("  {$f} — waits for the retry.");
                }
                if ($r['stopped'] === 'budget') {
                    $this->warn("Company {$companyId}: today's request budget is spent; the rest follows tomorrow.");
                    break;
                }
            }
        });

        return self::SUCCESS;
    }
}
