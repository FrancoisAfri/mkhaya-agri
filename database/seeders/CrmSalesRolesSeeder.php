<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;

/**
 * CRM sales hierarchy roles. These drive which performance dashboard a user
 * lands on (rep → My Dashboard, manager → Team Dashboard, executive → Executive
 * Dashboard) and who gets notified on events like a lost deal.
 */
class CrmSalesRolesSeeder extends Seeder
{
    public const ROLES = ['sales consultant', 'sales manager', 'sales executive'];

    public function run(): void
    {
        $base = Permission::whereIn('name', ['view', 'modify', 'delete'])->pluck('name')->toArray();

        $matrix = [
            'sales consultant' => array_values(array_intersect(['view', 'modify'], $base)),
            'sales manager'    => array_values(array_intersect(['view', 'modify', 'delete'], $base)),
            'sales executive'  => array_values(array_intersect(['view', 'modify', 'delete'], $base)),
        ];

        foreach ($matrix as $name => $perms) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($perms) {
                $role->givePermissionTo($perms);
            }
        }
    }
}
