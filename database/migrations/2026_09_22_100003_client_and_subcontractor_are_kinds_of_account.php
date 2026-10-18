<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Two roles, so a client is not filed as an employee.
 *
 * Clients and subcontractors already have logins here, and nothing records that they are not
 * staff. The business record lives in `suppliers` or `sheq_contractors`, the login lives in
 * `users`, and the two are joined only by a matching email address — so the person shows up in
 * Manage Users among the employees, is given the `user` role like everybody else, and is
 * indistinguishable from a member of staff. On this installation four supplier accounts read that
 * way today.
 *
 * A role rather than a column, and not `hr_people.account_type`: that one is already the bank
 * account type on the Tax & Banking tab, next to the account holder and branch. A role also shows
 * in Manage Users with no further work, and carries into the permission system, so a
 * subcontractor cannot wander into staff screens simply because nobody thought to stop them.
 *
 * ── Why they carry the baseline permissions ──────────────────────────────────
 *
 * The user edit screen assigns with `syncRoles([$role])`, which replaces every role rather than
 * adding one. Setting somebody to `client` therefore takes `user` away, and with it the `view`
 * permission that EnforceCrudPermissions falls back on — so a role meant to describe them would
 * instead lock them out of the product. These start life with exactly what `user` has, which
 * makes the change descriptive, which is what was asked for.
 */
return new class extends Migration
{
    /** Read off the `user` role at runtime rather than hardcoded, so the three cannot drift. */
    private const ROLES = ['client', 'subcontractor'];

    public function up(): void
    {
        $baseline = Role::where('name', 'user')->where('guard_name', 'web')->first()
            ?->permissions->pluck('name')->all() ?? ['view'];

        foreach (self::ROLES as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            foreach ($baseline as $permission) {
                Permission::findOrCreate($permission, 'web');
            }

            // Only on creation. An installation that has already tightened or widened one of these
            // keeps its own decision.
            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($baseline);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::whereIn('name', self::ROLES)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
