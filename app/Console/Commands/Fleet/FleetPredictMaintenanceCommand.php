<?php

namespace App\Console\Commands\Fleet;

use App\Modules\Fleet\Services\FleetPredictiveService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class FleetPredictMaintenanceCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'fleet:predict-maintenance';
    protected $description = 'FR-4.1 — Run daily predictive maintenance scoring for all fleet vehicles.';

    public function handle(FleetPredictiveService $service): int
    {
        $this->eachTenant(app(TenantContext::class), function () use ($service) {
            $this->info('Running fleet predictive maintenance…');

            $results = $service->runForAllVehicles();

            $this->table(['Metric', 'Count'], [
                ['Vehicles processed', $results['processed']],
                ['High-risk alerts sent', $results['alerts']],
                ['Job cards auto-created', $results['jobs_created']],
            ]);
        });

        return Command::SUCCESS;
    }
}
