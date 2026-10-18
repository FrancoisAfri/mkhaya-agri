<?php

namespace App\Modules\Finance\Tax\Console;

use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Services\ComplianceCalendarService;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * The daily compliance pass (§14): generate the coming periods (and their deadlines) for every
 * confirmed registration, then send due reminders and escalate what has gone overdue.
 *
 * Runs inline per tenant — production has no queue worker, so a dispatched job would sit unrun.
 * Each tenant runs in its own context so RLS scopes every read and write; one tenant's failure is
 * reported and the rest still run.
 */
class RunTaxComplianceCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'tax:compliance {--company= : Only this company id}';

    protected $description = 'Generate tax periods and send compliance-calendar reminders and escalations';

    protected function tenantFailureLabel(): string
    {
        return 'Tax compliance pass';
    }

    public function handle(TenantContext $tenants, TaxPeriodService $periods, ComplianceCalendarService $calendar): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($periods, $calendar) {
            $created = 0;
            $until = now()->addMonthsNoOverflow((int) config('tax.generate_months_ahead', 1))->endOfMonth();
            foreach (TaxRegistration::query()->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class)
                ->where('status', 'active')->where('applicability_confirmed', true)->get() as $registration) {
                $created += $periods->generate($registration, $until);
            }
            $result = $calendar->run();
            $chased = app(\App\Modules\Finance\Tax\Services\TaxReturnService::class)->chaseUnconfirmed();
            $this->line(sprintf('  company %d: %d period(s) created, %d reminder(s), %d escalation(s), %d unconfirmed submission(s) chased',
                $companyId, $created, $result['reminders'], $result['escalations'], $chased));
        });

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
