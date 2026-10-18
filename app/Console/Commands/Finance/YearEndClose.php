<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Services\YearEndService;
use Illuminate\Console\Command;

/**
 * Close a fiscal year: post the P&L into retained earnings and open next year's
 * periods. Idempotent — re-running returns the existing closing journal.
 */
class YearEndClose extends Command
{
    protected $signature = 'finance:year-end-close {year : Fiscal year to close, e.g. 2026} {--company=} {--no-next-periods}';
    protected $description = 'Close the fiscal year — P&L to retained earnings, next year opened';

    public function handle(YearEndService $service): int
    {
        $year = (int) $this->argument('year');
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        try {
            $r = $service->close($year, $companyId, ! $this->option('no-next-periods'));
        } catch (PostingException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($r['already_closed']) {
            $this->warn("Year {$year} was already closed (journal {$r['journal_no']}).");
        } elseif ($r['journal_no']) {
            $this->info("Closed {$year} with journal {$r['journal_no']}.");
        } else {
            $this->info("Year {$year} had no P&L movement to close.");
        }

        $this->line("  Income closed:  " . number_format($r['income_closed'], 2));
        $this->line("  Expense closed: " . number_format($r['expense_closed'], 2));
        $this->line("  Net " . ($r['net_profit'] >= 0 ? 'profit' : 'loss') . ": " . number_format($r['net_profit'], 2) . " → retained earnings");
        $this->line("  Next-year periods created: {$r['next_periods_created']}");

        return self::SUCCESS;
    }
}
