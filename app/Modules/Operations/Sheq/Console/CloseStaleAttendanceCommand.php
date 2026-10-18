<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\SiteAttendanceService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Close the stays nobody signed out of (CON-16).
 *
 * People go home without scanning. It is not a fault anybody can design away — a reader fails, a
 * gate is propped open at the end of a shift, somebody leaves through the yard. What matters is
 * that the register says so.
 *
 * Without this, every forgotten exit stays open for ever and the roll-call is permanently
 * over-counted. Within a month it reads as hundreds of people on a site that holds forty, and at
 * that point it is ignored by exactly the people it exists for. During an evacuation an ignored
 * roll-call is worse than none, because somebody still has to account for the names on it.
 *
 * The stay is closed at the cut-off, not at the moment the sweep happens to run, so a report of
 * hours on site is not inflated by how late the scheduler fired. And it is closed with
 * `NOT_SIGNED_OUT` rather than silently: a site where nobody signs out has a problem worth
 * seeing, and hiding it behind a tidy register is how it goes unfixed.
 */
class CloseStaleAttendanceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:attendance:close-stale
                            {--company= : Restrict to one company id}
                            {--hours=14 : How long a stay may run before it counts as forgotten}';

    protected $description = 'Close site attendance nobody signed out of, so the roll-call stays honest';

    public function handle(TenantContext $tenants, SiteAttendanceService $attendance): int
    {
        /*
         * Fourteen hours by default: longer than any single shift including overtime, shorter
         * than two. A twelve-hour default would close night shift out from under itself, which
         * is the same failure in the other direction — a roll-call that says a site is empty
         * while people are working in it.
         */
        $hours = max(1, (int) $this->option('hours'));

        $total = 0;

        // `--company` is read by the trait from the option itself, so it is not passed again.
        $this->eachTenant($tenants, function (int $companyId) use ($attendance, $hours, &$total): void {
            $closed = $attendance->closeStale($companyId, $hours);

            $total += $closed;

            if ($closed > 0) {
                $this->line("  company {$companyId}: closed {$closed}");
            }
        });

        $this->info($total === 0
            ? 'Nothing to close — everybody signed out.'
            : "Closed {$total} stays nobody signed out of.");

        return self::SUCCESS;
    }
}
