<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;

/**
 * Creates permissions and the lms_pa_admin role for the
 * LMS ↔ Performance Appraisal training integration (Phase 14).
 *
 * Run: php artisan db:seed --class=LmsPaTrainingRoleSeeder
 */
class LmsPaTrainingRoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Permissions ───────────────────────────────────────────────────────
        $permissions = [
            // Read access — dashboard, recommendations list, dev plans list, plan detail
            'lms.pa.view',

            // View manager-facing training progress report
            'lms.pa.reports.view',

            // Generate training recommendations from appraisal KPI gaps / skill gaps
            'lms.pa.recommend',

            // Create development training plans from PA development plans + sync progress
            'lms.pa.plans.manage',

            // Compute and persist promotion readiness scores
            'lms.pa.readiness.compute',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // ── lms_pa_admin role — full access ──────────────────────────────────
        $adminRole = Role::firstOrCreate(['name' => 'lms_pa_admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions($permissions);

        // ── Existing roles: grant read + report permissions ───────────────────
        $readRoles = ['manager', 'senior manager', 'executive', 'HR', 'super admin', 'administrator'];
        foreach ($readRoles as $roleName) {
            $role = Role::firstWhere(['name' => $roleName, 'guard_name' => 'web']);
            if ($role) {
                $role->givePermissionTo(['lms.pa.view', 'lms.pa.reports.view']);
            }
        }

        // ── HR / super admin / administrator — full write access ─────────────
        $writeRoles = ['HR', 'super admin', 'administrator'];
        foreach ($writeRoles as $roleName) {
            $role = Role::firstWhere(['name' => $roleName, 'guard_name' => 'web']);
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        $this->command->info('LMS PA Training role and permissions seeded.');
        $this->command->table(
            ['Permission', 'Roles'],
            collect($permissions)->map(fn ($p) => [
                $p,
                Role::permission($p)->pluck('name')->implode(', '),
            ])->toArray()
        );
    }
}
