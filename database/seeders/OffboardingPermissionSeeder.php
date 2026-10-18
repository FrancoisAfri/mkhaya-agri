<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Access permission for the Offboarding module — terminating employees, signing
 * clearances and reading exit interviews is HR-admin work, gated on the routes
 * by `offboarding.manage`. Granted to admin/HR roles by default; extend via
 * Roles & Permissions.
 */
class OffboardingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // boarding.manage gates onboarding/pre-boarding (candidate conversion,
        // ID-document approval); offboarding.manage gates termination.
        $permissions = ['offboarding.manage', 'boarding.manage'];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['administrator', 'super admin', 'hr manager'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
