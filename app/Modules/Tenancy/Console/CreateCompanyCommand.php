<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Services\CompanyProvisioner;
use Illuminate\Console\Command;

/**
 * Platform-admin: onboard a tenant from the CLI.
 *   php artisan tenancy:create-company "Acme (Pty) Ltd" admin@acme.co.za --plan=standard
 */
class CreateCompanyCommand extends Command
{
    protected $signature = 'tenancy:create-company {name} {email} {--password=} {--admin-name=} {--plan=standard}';

    protected $description = 'Create a new tenant company with its first admin user';

    public function handle(CompanyProvisioner $provisioner): int
    {
        $password = $this->option('password') ?: str()->random(12);

        $result = $provisioner->provision([
            'company_name' => $this->argument('name'),
            'admin_name' => $this->option('admin-name') ?: $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $password,
            'plan' => $this->option('plan'),
        ]);

        $this->info("Company #{$result['company']->id} '{$result['company']->name}' created (slug: {$result['company']->slug}).");
        $this->info("Admin: {$this->argument('email')}");
        if (! $this->option('password')) {
            $this->warn("Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
