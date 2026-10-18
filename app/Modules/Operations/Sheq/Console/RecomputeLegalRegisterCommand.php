<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\LegalRegisterService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Recomputes every legal register line from its evidence (SHEQ-LEG-3).
 *
 *   php artisan sheq:legal-register:recompute
 *   php artisan sheq:legal-register:recompute --company=7
 *
 * The register is also recomputed when the page is opened, so this exists for the case that matters
 * more: nobody opened the page. A schedule that lapsed overnight makes a line non-compliant whether
 * or not anyone looked, and the dashboard, the alerts and the exec report all read the stored
 * status. Without this the register is only true for whoever last visited it.
 *
 * Per-tenant fan-out, because row-level security is bound to `app.company_id` and an unscoped sweep
 * would both see and rewrite every tenant's rows.
 */
class RecomputeLegalRegisterCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:legal-register:recompute
                            {--company= : Restrict to one company id}';

    protected $description = 'Recompute SHEQ legal register compliance from linked evidence';

    public function handle(TenantContext $tenants, LegalRegisterService $register): int
    {
        $only = $this->option('company') ? (int) $this->option('company') : null;
        $totals = [];

        $recompute = function (int $companyId) use ($register, &$totals): void {
            $tally = $register->recomputeAll($companyId);

            if ($tally === []) {
                return;
            }

            foreach ($tally as $status => $count) {
                $totals[$status] = ($totals[$status] ?? 0) + $count;
            }

            $summary = implode(', ', array_map(
                fn ($status, $count) => "{$count} {$status}",
                array_keys($tally), array_values($tally)
            ));

            $this->components->twoColumnDetail("Company {$companyId}", $summary);
        };

        if ($only !== null) {
            $tenants->run($only, fn () => $recompute($only));
        } else {
            $this->eachTenant($tenants, function ($companyId) use ($recompute) {
                if ($companyId !== null) {
                    $recompute((int) $companyId);
                }
            });
        }

        if ($totals === []) {
            $this->components->info('No legal register lines to recompute.');

            return self::SUCCESS;
        }

        $attention = ($totals['NON_COMPLIANT'] ?? 0) + ($totals['REVIEW_REQUIRED'] ?? 0);

        $this->components->info(sprintf(
            'Legal register recomputed: %d lines, %d compliant, %d needing attention.',
            array_sum($totals), $totals['COMPLIANT'] ?? 0, $attention
        ));

        return self::SUCCESS;
    }
}
