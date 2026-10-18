<?php

namespace App\Console\Commands\Fleet;

use App\Modules\Fleet\Services\FleetAnomalyService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class FleetDetectAnomaliesCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'fleet:detect-anomalies';
    protected $description = 'FR-4.3 — Run daily fuel and spend anomaly detection for all fleet vehicles.';

    public function handle(FleetAnomalyService $service): int
    {
        $this->eachTenant(app(TenantContext::class), function () use ($service) {
            $this->info('Running fleet anomaly detection…');

            $results = $service->detectForAllVehicles();

            $this->info("Anomalies flagged: {$results['flagged']}");
        });

        return Command::SUCCESS;
    }
}
