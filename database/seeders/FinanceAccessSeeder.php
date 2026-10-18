<?php

namespace Database\Seeders;

use App\Modules\Finance\Accounting\Services\PostingAuthorizer;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;

/**
 * Grants the manual-journal posting permission to finance-capable roles. Amount
 * caps are set per-user in Finance › Posting Limits (fin_posting_limits).
 */
class FinanceAccessSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $post = Permission::firstOrCreate(['name' => PostingAuthorizer::PERMISSION, 'guard_name' => 'web']);
        // Approving supplier payment batches (maker-checker) — senior finance roles only.
        $approve = Permission::firstOrCreate(['name' => 'finance.payments.approve', 'guard_name' => 'web']);
        // Writing off bad debts — senior finance roles only.
        $writeoff = Permission::firstOrCreate(['name' => 'finance.writeoff', 'guard_name' => 'web']);

        // Roles that should be able to post manual journals out of the box.
        // (Amount ceilings are applied per-user in Finance › Posting Limits.)
        $postRoles = ['super admin', 'administrator', 'HR', 'finance manager', 'finance officer', 'finance clerk', 'budget manager', 'payroll officer'];
        foreach ($postRoles as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($post);
        }

        $seniorRoles = ['super admin', 'administrator', 'finance manager', 'budget manager'];
        foreach ($seniorRoles as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($approve);
            $role?->givePermissionTo($writeoff);
        }
    }
}
