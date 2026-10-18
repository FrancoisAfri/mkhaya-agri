<?php

use App\Modules\Operations\ProjectManagement\Services\RoleProvisioner;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * Brings existing installs up to date with the access-control model (PM-1020, PM-1021):
 * the two new permissions (`pm.costs.view`, `pm.members.manage`) and each tenant's copy of the
 * nine built-in roles.
 *
 * Both steps are idempotent, so this is also the pattern for later upgrades that add a permission —
 * or you can run `pm:provision-roles` by hand at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Permissions + platform-role grants.
        (new \Database\Seeders\ProjectManagementModuleSeeder())->run();

        // Per-tenant roles. Each company is seeded inside its own tenant context; without that the
        // rows would be written with a NULL company_id and no tenant user could ever see them.
        $tenants = app(TenantContext::class);
        $provisioner = app(RoleProvisioner::class);

        $tenants->eachCompany(fn () => $provisioner->ensure());
    }

    public function down(): void
    {
        // Roles are left in place: a tenant may have cloned or edited them, and dropping the
        // built-ins would strip access from anyone assigned one. The tables themselves are removed
        // by the migration that created them.
    }
};
