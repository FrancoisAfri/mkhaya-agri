<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\ShopFloor;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Climbs every floor escalation nobody has acknowledged within its factory's window (MFG-007:
 * "supervisor approvals and escalation"). Per tenant, through TenantContext, like every other
 * scheduled manufacturing command.
 */
class EscalateFloorCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:escalate-floor {--company= : Only this company id}';

    protected $description = 'Raise the level of unanswered shop-floor escalations';

    protected function tenantFailureLabel(): string
    {
        return 'Shop-floor escalation';
    }

    public function handle(TenantContext $tenants, ShopFloor $floor): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($floor) {
            $climbed = $floor->climbUnanswered($companyId);
            if ($climbed > 0) {
                $this->line("  company {$companyId}: {$climbed} escalation(s) climbed");
            }
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
