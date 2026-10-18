<?php

namespace App\Console\Commands\Finance;

use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollLedgerPoster;
use Illuminate\Console\Command;

/**
 * Post historical released payroll runs to the GL (for runs released before the
 * Finance posting hook existed). Idempotent — safe to re-run.
 */
class BackfillPayrollPostings extends Command
{
    protected $signature = 'finance:backfill-payroll {--dry-run}';
    protected $description = 'Post already-released payroll runs to the General Ledger';

    public function handle(PayrollLedgerPoster $poster): int
    {
        $runs = PayrollRun::where('status', 'released')->orderBy('id')->get();
        $this->info("Found {$runs->count()} released run(s).");

        foreach ($runs as $run) {
            if ($this->option('dry-run')) {
                $this->line("  would post run #{$run->id} — {$run->name} (gross {$run->total_gross})");
                continue;
            }
            $poster->postRun($run);
            $this->line("  posted run #{$run->id} — {$run->name}");
        }

        $this->info('Done. Run `finance:parity` to confirm.');

        return self::SUCCESS;
    }
}
