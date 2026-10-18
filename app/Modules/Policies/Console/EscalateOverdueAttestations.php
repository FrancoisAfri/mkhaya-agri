<?php

namespace App\Modules\Policies\Console;

use App\Modules\Policies\Services\AttestationService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class EscalateOverdueAttestations extends Command
{
    use RunsPerTenant;

    protected $signature = 'attestations:escalate-overdue';

    protected $description = 'Escalate overdue policy attestations to each employee\'s direct manager';

    public function handle(AttestationService $service): int
    {
        // Tenant-safe: per-company so overdue reads, manager recipients and the reminder rows
        // created are all scoped to the company that owns each attestation.
        $this->eachTenant(app(TenantContext::class), function () use ($service) {
            $markedOverdue = $service->markOverdue();
            if ($markedOverdue > 0) {
                $this->line("  Marked {$markedOverdue} attestation(s) as overdue.");
            }
            $escalated = $service->escalateOverdue();
            $this->info("Escalated {$escalated} overdue attestation(s) to managers.");
        });

        return self::SUCCESS;
    }
}
