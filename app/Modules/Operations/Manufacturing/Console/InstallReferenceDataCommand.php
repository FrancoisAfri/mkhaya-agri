<?php

namespace App\Modules\Operations\Manufacturing\Console;

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;

/**
 * Installs each tenant's manufacturing reference data: units of measure, document sequences and
 * the company-level policy defaults (MFG-001.05/.06/.07).
 *
 * Per tenant, through TenantContext. `CompanyScope` is inactive in the console and the RLS policy
 * reads an unset session variable as "every row", so a command that wrote these without entering
 * a tenant would stamp rows with whatever company_id the default expression resolved to — which,
 * with no session variable set, is NULL, and a NULL company_id row is invisible to every tenant
 * afterwards. The install would report success and the units would be nowhere.
 */
class InstallReferenceDataCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'mfg:install-reference-data {--company= : Only this company id}';

    protected $description = 'Install manufacturing units of measure, numbering and policy defaults per tenant';

    protected function tenantFailureLabel(): string
    {
        return 'Manufacturing reference data install';
    }

    public function handle(TenantContext $tenants, ManufacturingReferenceData $reference): int
    {
        $this->eachTenant($tenants, function (int $companyId) use ($reference) {
            $result = $reference->installFor($companyId);

            $this->line(sprintf(
                '  company %d: %d units, %d sequences, policy %s',
                $companyId,
                $result['units'],
                $result['sequences'],
                $result['policy'] ? 'created' : 'already present',
            ));
        });

        // RunsPerTenant::run() already turns any tenant failure into a non-zero exit; this
        // keeps the intent visible at the call site rather than only in the trait.
        return $this->hadTenantFailures() ? self::FAILURE : self::SUCCESS;
    }
}
