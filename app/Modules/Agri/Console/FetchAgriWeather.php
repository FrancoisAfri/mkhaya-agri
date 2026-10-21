<?php

namespace App\Modules\Agri\Console;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\WeatherIntelligence;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Every few hours (Phase 2 §11.1): fetch the forecast for every located, active farm from the
 * approved provider. A farm whose provider call fails is reported and skipped; the next run tries
 * again. The alert scan that follows turns forecasts into alerts where an approved rule applies.
 */
class FetchAgriWeather extends Command
{
    use RunsPerTenant;

    protected $signature = 'agri:weather {--company= : Only this organisation} {--history= : Also fetch this many past days of observations}';

    protected $description = 'Fetch Mkhaya Agri farm weather from the approved provider';

    public function handle(TenantContext $tenants, WeatherIntelligence $weather): int
    {
        if (! $weather->provider()) {
            $this->warn('No approved weather provider is configured (agri.weather.provider).');

            return self::SUCCESS;
        }
        $this->eachTenant($tenants, function (int $companyId) use ($weather) {
            foreach (Farm::withoutGlobalScope('farm_access')->where('company_id', $companyId)->where('status', Farm::ACTIVE)->whereNotNull('latitude')->whereNotNull('longitude')->get() as $farm) {
                try {
                    // §14: through the integration log, once per farm per hour; a failure waits for the retry.
                    ['event' => $e] = app(\App\Modules\Agri\Services\Integrations::class)->receive($companyId, 'weather', "farm:{$farm->id}:".now()->format('Y-m-d\TH'), ['farm_id' => $farm->id], null, $farm->id);
                    if ($e->status !== 'processed') {
                        throw new \RuntimeException((string) $e->error);
                    }
                    $n = $e->result['hours'] ?? 0;
                    if ($days = (int) $this->option('history')) {
                        $weather->history($farm, today()->subDays($days)->toDateString(), today()->subDay()->toDateString());
                    }
                    $this->line("{$farm->code}: {$n} hour(s).");
                } catch (\Throwable $e) {
                    $this->error("{$farm->code}: {$e->getMessage()}");
                }
            }
            app(\App\Modules\Agri\Services\FarmAlerts::class)->scan($companyId);
        });

        return self::SUCCESS;
    }
}
