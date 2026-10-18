<?php

namespace App\Console\Commands\Finance;

use App\Modules\Finance\Accounting\Services\RecurringInvoiceService;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Turns due recurring-invoice schedules into posted invoices. Run daily.
 *
 * Per tenant, because this command *writes*. CompanyScope is inactive in the console, so the
 * unscoped version read every customer's schedules in one query and then created invoices with no
 * tenant applied — meaning anything the invoice engine resolves by lookup rather than by id was
 * resolved against the whole estate. An invoice is also the kind of record a customer sees, so
 * getting it wrong is not an internal inconsistency somebody quietly repairs later.
 *
 * A tenant whose billing run fails no longer stops the rest: the others are still invoiced, the
 * failure is reported against the company it belongs to, and the command exits non-zero.
 */
class GenerateRecurringInvoices extends Command
{
    use RunsPerTenant;

    protected $signature = 'finance:generate-recurring
                            {--company= : Restrict to one company id}';

    protected $description = 'Generate invoices from recurring schedules that are due';

    public function handle(TenantContext $tenants, RecurringInvoiceService $service): int
    {
        $total = 0;

        $this->eachTenant($tenants, function (int $companyId) use ($service, &$total) {
            $generated = $service->generateDue($companyId);
            $total += $generated;

            if ($generated > 0) {
                $this->components->twoColumnDetail("Company {$companyId}", "{$generated} invoice(s)");
            }
        });

        $this->components->info("Generated {$total} recurring invoice(s).");

        $this->reportTenantFailures('Recurring invoicing');

        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
