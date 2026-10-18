<?php

namespace App\Console\Commands\Payroll;

use App\Modules\HR\Payroll\Models\Bureau\SaTaxYear;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mid/late-February rollover: make sure the upcoming SARS year of assessment
 * exists so payroll can run from 1 March. It does NOT scrape figures off the
 * web — auto-fetched tax numbers could be wrong and would silently feed the
 * live PAYE engine. Instead it carries the latest CONFIRMED year forward as a
 * PROVISIONAL row (known-good prior figures) and flags it for a payroll admin
 * to reconcile against the budget speech and then confirm on the Tax Tables page.
 *
 * sa_tax_years is national (SARS) reference data with no company_id, so this is
 * a single global run — no per-tenant fan-out.
 */
class EnsureNextTaxYear extends Command
{
    protected $signature = 'tax:ensure-next-year
                            {--country=* : Limit to these ISO country codes (default: all present, e.g. ZA)}';

    protected $description = 'Ensure the upcoming SARS tax year exists as a provisional carried-forward row for admin confirmation.';

    public function handle(): int
    {
        $countries = $this->option('country') ?: SaTaxYear::query()->distinct()->pluck('country')->all();
        if (empty($countries)) {
            $countries = ['ZA'];
        }

        $created = 0;

        foreach ($countries as $country) {
            $latest = SaTaxYear::query()
                ->confirmed()
                ->where('country', $country)
                ->orderByDesc('tax_year')
                ->first();

            if (! $latest) {
                $this->warn("No confirmed tax year found for {$country} — nothing to carry forward.");
                continue;
            }

            $next = (int) $latest->tax_year + 1;

            if (SaTaxYear::where('country', $country)->where('tax_year', $next)->exists()) {
                $this->line("Tax year {$next} for {$country} already exists — skipping.");
                continue;
            }

            $row = $latest->replicate();
            $row->tax_year     = $next;
            $row->starts_on    = $latest->starts_on?->copy()->addYear();
            $row->ends_on      = $latest->ends_on?->copy()->addYear();
            $row->source       = 'carried_forward';
            $row->confirmed_at = null; // provisional until an admin reconciles the budget figures
            $row->save();

            $created++;
            $msg = "Created PROVISIONAL SARS tax year {$next} for {$country} by carrying {$latest->tax_year} forward. "
                 . 'Reconcile against the budget speech and confirm on the Tax Tables page before running that year\'s payroll.';
            $this->warn($msg);
            Log::warning("tax:ensure-next-year — {$msg}");
        }

        $this->info("Done. {$created} provisional tax year(s) created.");

        return self::SUCCESS;
    }
}
