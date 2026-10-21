<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\Agriculture\Commands;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Forecasts;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Incidents;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Orchestrator;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Readings;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * The Command Centre's clock: sensors gone quiet become stale, unacknowledged incidents climb to
 * the next step of their escalation, commands nobody confirmed become unconfirmed, and forecasts
 * whose day has a measurement are scored. With --agents, the production agents run as well.
 *
 * Alarms never wait for this: thresholds and water interruptions are raised the moment a reading
 * arrives. This catches what only the passing of time reveals.
 */
class ProductionMonitorCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:production-monitor {--company= : Only this company id} {--agents : Run the production agents too} {--sources : Fetch the outside sources that are due}';

    protected $description = 'Stale sensors, incident escalation, unconfirmed commands and forecast scoring for agricultural production';

    protected function tenantFailureLabel(): string
    {
        return 'Production monitor';
    }

    public function handle(TenantContext $tenants, Readings $readings, Incidents $incidents, Commands $commands, Forecasts $forecasts, Orchestrator $agents): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($readings, $incidents, $commands, $forecasts, $agents) {
            $done = ['stale' => $readings->sweepStale(), 'escalated' => $incidents->escalateDue(), 'unconfirmed' => $commands->sweepUnconfirmed(),
                'scored' => $forecasts->resolveDue()];
            if ($this->option('sources')) {
                $done['fetched'] = count(app(\App\Modules\Operations\Manufacturing\Services\Agriculture\SourceFetcher::class)->fetchDue());
            }
            if ($this->option('agents')) {
                $done['recommended'] = $agents->run($companyId)['proposed'];
                $done['forecast'] = $forecasts->forecastAll($companyId);
            }
            if (array_sum($done) > 0) {
                $this->line("  company {$companyId}: ".collect($done)->filter()->map(fn ($n, $k) => "{$n} {$k}")->implode(', '));
            }
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
