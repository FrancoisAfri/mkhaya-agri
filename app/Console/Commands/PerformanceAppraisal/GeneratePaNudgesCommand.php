<?php

namespace App\Console\Commands\PerformanceAppraisal;

use App\Modules\PerformanceAppraisal\Services\PaNudgeService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class GeneratePaNudgesCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'pa:generate-nudges';
    protected $description = 'Scan performance state and generate proactive nudges for managers/employees';

    public function handle(PaNudgeService $service): int
    {
        // Tenant-safe: per-company so nudges are generated from each company's own data and the
        // nudge rows are stamped with the correct company_id.
        $this->eachTenant(app(TenantContext::class), function () use ($service) {
            $count = $service->generateAll();
            $this->info("Generated {$count} performance nudges.");
        });

        return self::SUCCESS;
    }
}
