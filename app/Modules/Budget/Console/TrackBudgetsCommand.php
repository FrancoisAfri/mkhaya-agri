<?php

namespace App\Modules\Budget\Console;

use App\Modules\Budget\Services\BudgetTrackingService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Evaluate every budget against its company's alert rules, nightly.
 *
 * The failure this catches is the ordinary one: a budget crosses 90% on a Tuesday and nobody looks
 * at the page until the purchase order bounces. Rules written and never evaluated are a preference
 * nobody acts on.
 *
 * Per-tenant fan-out is mandatory, not an optimisation. The rules, the budgets and the people to
 * notify are all tenant-scoped, and a sweep run outside a company context would read every tenant's
 * budgets through one company's rules.
 */
class TrackBudgetsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'budgets:track
                            {--company= : Only this company id}
                            {--quiet-alerts : Raise and resolve without notifying anybody}';

    protected $description = 'Evaluate budgets against alert rules, raising and resolving alerts.';

    public function handle(TenantContext $tenants, BudgetTrackingService $tracking): int
    {
        $notify = ! $this->option('quiet-alerts');
        $totals = ['raised' => 0, 'resolved' => 0, 'notified' => 0];

        $sweep = function () use ($tracking, $notify, &$totals) {
            $result = $tracking->sweep($notify);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        };

        if ($company = $this->option('company')) {
            $tenants->run((int) $company, $sweep);
        } else {
            $this->eachTenant($tenants, $sweep);
        }

        $this->info("{$totals['raised']} raised, {$totals['resolved']} resolved, {$totals['notified']} notified.");

        return self::SUCCESS;
    }
}
