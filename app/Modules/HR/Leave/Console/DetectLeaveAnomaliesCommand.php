<?php

namespace App\Modules\HR\Leave\Console;

use App\Modules\HR\Leave\Services\LeaveIntegrityScanner;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Scans every active employee per company for leave account-setup / ledger-integrity
 * anomalies (no leave profile, missing accrual, missing birthdate/join date, no sick
 * setup, negative balance, uncredited overtime) and records them in leave_anomaly_flags.
 * Runs inside each tenant's RLS context. Shared by the "Scan now" button (single company)
 * and the weekly cron (all companies).
 */
class DetectLeaveAnomaliesCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'leave:detect-anomalies
                            {--company= : Scan only this company}
                            {--sync : Scan companies inline instead of fanning out to the queue}';

    protected $description = 'Detect leave account-setup / ledger-integrity anomalies per company.';

    public function handle(TenantContext $tenant, LeaveIntegrityScanner $scanner): int
    {
        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, function () use ($scanner) {
                $n = $scanner->scanCurrentCompany();
                $this->info("  {$n} new anomaly flag(s).");
            });
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $total = 0;
            $this->eachTenant($tenant, function () use ($scanner, &$total) {
                $total += $scanner->scanCurrentCompany();
            });
            $this->info("Anomaly scan complete (sync) — {$total} new flag(s).");
            return self::SUCCESS;
        }

        $n = $tenant->dispatchEachCompany('leave:detect-anomalies', []);
        $this->info("Dispatched anomaly scan to {$n} company(ies).");
        return self::SUCCESS;
    }
}
