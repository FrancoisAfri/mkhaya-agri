<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Models\FinFiscalPeriod;
use App\Modules\Finance\Accounting\Models\FinFiscalYear;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\FiscalYearService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Daily, per-company fiscal-calendar reminders emailed to the finance address:
 *   • a period closes in 5 days / tomorrow,
 *   • a period ended and is still open past the grace window,
 *   • the financial year ends in ~30 days and the next year isn't created yet.
 * Tenant-safe (fans out per company). Scheduled daily.
 */
class SendPeriodReminders extends Command
{
    use RunsPerTenant;

    protected $signature = 'finance:period-reminders {--company= : Only this company} {--dry-run}';

    protected $description = 'Email fiscal-period closing reminders to each company\'s finance team';

    public function handle(TenantContext $tenant, FiscalYearService $years): int
    {
        $sent = 0;
        $run = function (?int $companyId) use ($years, &$sent) {
            $lines = $this->remindersFor($companyId, $years);
            if (! $lines) {
                return;
            }
            $email = FinSetting::current($companyId)->email;
            if (! $email) {
                $this->line('  company ' . ($companyId ?? 'default') . ": " . count($lines) . ' reminder(s), no finance email set — skipped');

                return;
            }
            $this->line('  company ' . ($companyId ?? 'default') . ': ' . count($lines) . ' reminder(s) → ' . $email);
            if (! $this->option('dry-run')) {
                Mail::raw("Finance period reminders:\n\n• " . implode("\n• ", $lines), fn ($m) => $m->to($email)->subject('Finance: period-close reminders'));
                $sent++;
            }
        };

        if ($cid = $this->option('company')) {
            $tenant->run((int) $cid, fn () => $run((int) $cid));
        } else {
            $this->eachTenant($tenant, fn (?int $companyId) => $run($companyId));
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "Done. Digests sent: {$sent}.");

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function remindersFor(?int $companyId, FiscalYearService $years): array
    {
        $today = Carbon::today();
        $lines = [];

        $open = FinFiscalPeriod::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->where('status', 'open')->get();

        foreach ($open as $p) {
            $end = Carbon::parse($p->end_date);
            $daysToEnd = $today->diffInDays($end, false);
            if ($daysToEnd === 5) {
                $lines[] = "Period {$p->label} closes in 5 days ({$p->end_date->toDateString()}).";
            } elseif ($daysToEnd === 1) {
                $lines[] = "Period {$p->label} closes tomorrow — complete bank reconciliations and post outstanding journals.";
            } elseif ($daysToEnd < 0 && abs($daysToEnd) >= 5) {
                $lines[] = "Period {$p->label} ended " . abs($daysToEnd) . ' days ago and is still open.';
            }
        }

        $fy = $years->currentYear($companyId);
        if ($fy) {
            $daysToYearEnd = $today->diffInDays(Carbon::parse($fy->end_date), false);
            $nextExists = FinFiscalYear::where('company_id', $companyId)
                ->whereDate('start_date', Carbon::parse($fy->end_date)->addDay()->toDateString())->exists();
            if ($daysToYearEnd > 0 && $daysToYearEnd <= 30 && ! $nextExists) {
                $lines[] = "Financial year {$fy->label} ends in {$daysToYearEnd} days — create the next financial year.";
            }
        }

        return $lines;
    }
}
