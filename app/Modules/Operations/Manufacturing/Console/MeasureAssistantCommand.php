<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\AssistantDesk;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/** Measures the assistant's recommendations against what happened afterwards (MFG-019). Per tenant. */
class MeasureAssistantCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:measure-assistant {--company= : Only this company id}';

    protected $description = 'Measure manufacturing assistant recommendations against subsequent results';

    protected function tenantFailureLabel(): string
    {
        return 'Assistant outcome measurement';
    }

    public function handle(TenantContext $tenants, AssistantDesk $desk): int
    {
        $this->eachTenant($tenants, fn (int $companyId) => $this->line("  company {$companyId}: {$desk->measureDue()} measured"));

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
