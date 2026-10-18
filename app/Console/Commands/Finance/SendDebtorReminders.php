<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Services\DebtorMetricsService;
use App\Modules\Finance\Accounting\Services\DunningService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Daily collections run — for EACH company (tenant-safe): writes an AR ageing snapshot, flags any
 * lapsed promises-to-pay, then runs the dunning ladder (escalating, omnichannel, once-per-day
 * throttled reminders). Scheduled daily; also runnable ad-hoc.
 *
 *   php artisan finance:send-reminders                # every company
 *   php artisan finance:send-reminders --company=12   # one company
 *   php artisan finance:send-reminders --dry-run      # snapshot + broken-PTP only, no sends
 *
 * Previously this ran once with no tenant context — with the console company scope off it read every
 * company's invoices while using a single company's settings. It now fans out per company.
 */
class SendDebtorReminders extends Command
{
    use RunsPerTenant;

    protected $signature = 'finance:send-reminders {--company= : Only this company} {--dry-run : No reminders sent}';

    protected $description = 'Per-company: AR snapshot, broken-promise flags, and the dunning ladder';

    public function handle(TenantContext $tenant, DunningService $dunning, DebtorMetricsService $metrics): int
    {
        $dry = (bool) $this->option('dry-run');
        $totalSent = $totalBroken = $companies = 0;

        $run = function (?int $companyId) use ($dunning, $metrics, $dry, &$totalSent, &$totalBroken, &$companies) {
            $companies++;
            $metrics->writeSnapshot($companyId);
            $totalBroken += $dunning->flagBrokenPromises($companyId);
            if (! $dry) {
                $r = $dunning->runLadder($companyId);
                $totalSent += $r['sent'];
                $this->line('  company ' . ($companyId ?? 'default') . ": sent {$r['sent']}, skipped {$r['skipped']}");
            }
        };

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $run((int) $cid));
        } else {
            $this->eachTenant($tenant, fn (?int $companyId) => $run($companyId));
        }

        $this->info(($dry ? '[dry-run] ' : '') . "Done. Companies: {$companies}, reminders sent: {$totalSent}, broken promises flagged: {$totalBroken}.");

        return self::SUCCESS;
    }
}
