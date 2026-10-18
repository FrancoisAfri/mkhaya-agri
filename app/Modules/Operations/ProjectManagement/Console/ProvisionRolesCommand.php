<?php

namespace App\Modules\Operations\ProjectManagement\Console;

use App\Modules\Operations\ProjectManagement\Services\RoleProvisioner;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * Seeds (or refreshes) each tenant's built-in Project Management roles (PM-1021).
 *
 * Run after an upgrade that adds a permission: built-in roles are brought in line with the
 * catalogue, and roles a tenant has cloned or replaced are left alone.
 *
 * Runs inside each company's tenant context — without it every role would be written with a NULL
 * company_id that no tenant user could ever see, which is the standard way an untenanted command
 * quietly corrupts a multi-tenant table.
 */
class ProvisionRolesCommand extends Command
{
    protected $signature = 'pm:provision-roles {--company= : Only this company id}';

    protected $description = 'Seed or refresh the built-in Project Management roles for each tenant';

    public function handle(TenantContext $tenants, RoleProvisioner $provisioner): int
    {
        $only = $this->option('company');

        if ($only) {
            $written = $tenants->run((int) $only, fn () => $provisioner->ensure());
            $this->info("Company {$only}: {$written} role(s) written.");

            return self::SUCCESS;
        }

        $companies = 0;
        $total = 0;

        $tenants->eachCompany(function () use ($provisioner, &$companies, &$total) {
            $companies++;
            $total += $provisioner->ensure();
        });

        $this->info("Provisioned Project Management roles for {$companies} companies ({$total} written).");

        return self::SUCCESS;
    }
}
