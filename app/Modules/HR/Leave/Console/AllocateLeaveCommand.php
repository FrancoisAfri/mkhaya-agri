<?php

namespace App\Modules\HR\Leave\Console;

use App\Modules\HR\Leave\Jobs\AdvanceLeaveCyclesJob;
use App\Modules\HR\Leave\Jobs\AllocateLeaveBalancesJob;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Runs leave balance allocation PER COMPANY. In SaaS every company has its own
 * leave cycle, profiles and accrual rules, so the accrual jobs must run inside
 * each tenant's context (RLS scopes hr_people / leave_cycles / allocations and
 * tags new transactions to the right company). Default fans out one queued job
 * per active/trial company so it scales to thousands of tenants; --company runs
 * a single tenant (used by the fan-out job); --sync loops inline.
 */
class AllocateLeaveCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:allocate
                            {mode=monthly : monthly | yearly | prorated | advance}
                            {--company= : Process only this company}
                            {--sync : Process companies inline instead of fanning out to the queue}';

    protected $description = 'Run per-company leave balance allocation. Use "advance" to close the current cycle and open the next one (carry-over + forfeiture + yearly grant).';

    public function handle(TenantContext $tenant): int
    {
        $mode = (string) $this->argument('mode');
        if (! in_array($mode, ['monthly', 'yearly', 'prorated', 'advance'], true)) {
            $this->error("Unknown mode '{$mode}'. Use: monthly | yearly | prorated | advance");
            return self::FAILURE;
        }

        // Single company (invoked by the fan-out job, or manually for one tenant).
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $this->runForCurrentCompany($mode));
            return self::SUCCESS;
        }

        // Inline mode — small installs / local: run every company in one process.
        if ($this->option('sync')) {
            $this->eachTenant($tenant, fn () => $this->runForCurrentCompany($mode));
            $this->info('Leave allocation complete (sync).');
            return self::SUCCESS;
        }

        // Default: one queued job per active/trial company.
        $n = $tenant->dispatchEachCompany('leave:allocate', ['mode' => $mode]);
        $this->info("Dispatched leave allocation ({$mode}) to {$n} company(ies).");
        return self::SUCCESS;
    }

    /** Run the requested allocation for the company whose tenant context is set. */
    private function runForCurrentCompany(string $mode): void
    {
        if ($mode === 'advance') {
            app()->call([new AdvanceLeaveCyclesJob(), 'handle']);
            return;
        }

        // monthly | yearly | prorated need an active cycle for THIS company.
        if (! LeaveCycle::active()) {
            $this->warn('  No active leave cycle for this company — skipped.');
            return;
        }

        app()->call([new AllocateLeaveBalancesJob($mode), 'handle']);
    }
}
