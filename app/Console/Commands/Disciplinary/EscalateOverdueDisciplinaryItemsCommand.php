<?php

namespace App\Console\Commands\Disciplinary;

use App\Modules\Disciplinary\Services\DisciplinaryEscalationService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

class EscalateOverdueDisciplinaryItemsCommand extends Command
{
    use RunsPerTenant;

    protected $signature   = 'disciplinary:escalate-overdue
                                {--dry-run : Scan and report without sending notifications}';

    protected $description = 'Escalate overdue disciplinary investigations, hearings, notices and policy violations';

    public function __construct(private readonly DisciplinaryEscalationService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY RUN] No notifications will be sent.');
        }

        $this->info('Running disciplinary escalation checks (per company)...');

        // Tenant-safe: per-company so overdue reads AND the HR-admin escalation recipients are
        // scoped to the company that owns each case (no more emailing tenant A's case PII to
        // every tenant's admins).
        $this->eachTenant(app(TenantContext::class), function () {
            try {
                $summary = $this->service->runOverdueChecks();
            } catch (\Throwable $e) {
                $this->error('Escalation check failed: ' . $e->getMessage());

                return;
            }

            $total = $summary['overdue_investigations'] + $summary['overdue_hearings']
                   + $summary['overdue_notices'] + $summary['policy_violations'];
            $this->line("  company: {$total} escalation(s), {$summary['errors']} error(s).");
        });

        return self::SUCCESS;
    }
}
