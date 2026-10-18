<?php

namespace App\Console\Commands\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Services\ExpiryService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * The daily sweep: warn about what is about to expire, mark what has.
 *
 * Runs per tenant, which is not optional. A sweep that ran once without a company set would see
 * every company's documents at once through the same query and email one organisation about
 * another's certificates — row-level security is what prevents that, and it only engages when the
 * session knows which company it is acting for.
 *
 *   php artisan documents:expiry-sweep                 # every company
 *   php artisan documents:expiry-sweep --company=1
 *   php artisan documents:expiry-sweep --as-of=2026-12-01 --dry-run
 */
class SweepDocumentExpiry extends Command
{
    use RunsPerTenant;

    protected $signature = 'documents:expiry-sweep
        {--company= : Only this company.}
        {--as-of= : Pretend today is this date, for checking what a future run would do.}
        {--dry-run : Report what would be sent without sending anything.}';

    protected $description = 'Send document expiry reminders and mark expired documents.';

    public function handle(TenantContext $tenants, ExpiryService $expiry): int
    {
        $asOf = $this->option('as-of') ? \Illuminate\Support\Carbon::parse($this->option('as-of')) : null;

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing is sent and nothing is marked.');
            $this->eachTenant($tenants, fn () => $this->report($expiry, $asOf));

            return self::SUCCESS;
        }

        $totals = ['reminded' => 0, 'expired' => 0, 'notified' => 0];

        $approvals = 0;

        $this->eachTenant($tenants, function () use ($expiry, $asOf, &$totals, &$approvals) {
            $result = $expiry->sweep($asOf);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            // Approval deadlines ride the same sweep: they run at the same time for the same
            // reason, and one command is one place to look when nobody was reminded.
            $approvals += $expiry->remindApprovers();
        });

        $this->info(sprintf(
            'Reminded on %d document(s) to %d recipient(s); marked %d expired; nudged %d approver(s).',
            $totals['reminded'], $totals['notified'], $totals['expired'], $approvals,
        ));

        return self::SUCCESS;
    }

    /** What a real run would do, without doing it. */
    private function report(ExpiryService $expiry, ?\Illuminate\Support\Carbon $asOf): void
    {
        $summary = $expiry->summary();

        $this->line(sprintf(
            '  expired %d · within 7 days %d · within 30 %d · within 90 %d · thresholds %s',
            $summary['expired'], $summary['within_7'], $summary['within_30'], $summary['within_90'],
            implode(', ', $expiry->thresholds()),
        ));
    }
}
