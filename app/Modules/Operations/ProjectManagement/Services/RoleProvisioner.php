<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\PmRole;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;

/**
 * Seeds a tenant's copy of the built-in roles (PM-1021).
 *
 * Each tenant gets its own rows so it can clone and edit them (PM-1022) without touching anyone
 * else's. Re-running is safe and is how a newly-added permission reaches existing tenants: built-in
 * rows are refreshed from the catalogue, tenant clones are never touched.
 *
 * Seeding happens on demand — the first time the module reads roles for a company — so a tenant
 * provisioned before this module existed does not need a migration to catch up.
 */
class RoleProvisioner
{
    public function __construct(private readonly CurrentCompany $current)
    {
    }

    /** Ensure the active tenant has its built-in roles. Returns the number written or refreshed. */
    public function ensure(): int
    {
        $companyId = $this->current->id();
        $written = 0;

        foreach (RoleCatalog::builtIn() as $key => $definition) {
            $role = PmRole::query()->where('key', $key)->first();

            if ($role && ! $role->is_builtin) {
                // A tenant has replaced this key with a role of their own. Their choice wins.
                continue;
            }

            if ($role) {
                // Refresh the permission set so upgrades reach existing tenants, but leave a
                // renamed role's name alone — someone chose that wording.
                if ($role->permissions !== $definition['permissions']) {
                    $role->update(['permissions' => $definition['permissions']]);
                    $written++;
                }

                continue;
            }

            PmRole::create([
                'company_id'  => $companyId,
                'key'         => $key,
                'name'        => $definition['name'],
                'description' => $definition['description'],
                'permissions' => $definition['permissions'],
                'is_builtin'  => true,
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * Clone a role so a tenant can edit it (PM-1022).
     *
     * The clone is not built-in, so `ensure()` will never overwrite it — that is the entire point.
     */
    public function clone(PmRole $source, string $key, string $name, ?array $permissions = null): PmRole
    {
        return PmRole::create([
            'company_id'     => $this->current->id(),
            'key'            => $key,
            'name'           => $name,
            'description'    => $source->description,
            'permissions'    => $permissions ?? $source->permissions,
            'is_builtin'     => false,
            'cloned_from_id' => $source->id,
        ]);
    }
}
