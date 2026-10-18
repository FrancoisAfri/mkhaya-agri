<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Operations\Sheq\Support\SheqHubs;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers SHEQ under "Operations & Support": the module row, its nav ribbons, the permission set,
 * and access for the users who should already have it. Idempotent — safe to re-run, and re-running
 * prunes ribbons retired from SheqHubs.
 */
class SheqModuleSeeder extends Seeder
{
    private const LEVEL = 5; // SUPERUSER

    private const MODULE_CODE = 'sheq';

    /**
     * Permissions are grouped by the seat classes in SHEQ-ENT-1, mapped onto platform tiers
     * (SHEQ-ADR-003). Reporting permissions sit lowest deliberately: every worker must be able to
     * raise a hazard, and gating that behind a paid capability suppresses the reporting the module
     * exists to encourage.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [
            // REPORTER
            'sheq.dashboard.view',
            'sheq.hazard.report',
            'sheq.incident.report',
            // CONTRIBUTOR
            'sheq.inspection.perform',
            'sheq.action.complete',
            'sheq.permit.request',
            // PRACTITIONER
            'sheq.incident.investigate',
            'sheq.incident.classify',
            // Acknowledging an incident and naming who owns it. A practitioner duty rather than a
            // manager one: the value of triage is that it happens within hours, and a permission
            // only a manager holds is a permission that waits for a manager.
            'sheq.incident.triage',
            // Writing and issuing a safety alert, and following an injured person back to full
            // duty. Practitioner duties: both are the tail of an investigation, and an alert that
            // needs a manager's signature is an alert that goes out next month.
            'sheq.safety_alert.manage',
            'sheq.injury_case.manage',
            'sheq.hazard.triage',
            'sheq.risk_assessment.author',
            'sheq.permit.approve',
            'sheq.action.verify',
            'sheq.document.manage',
            // Registers that had backends before they had screens, and so had no permission
            // either. Added when the screens landed — an action nobody can be granted is an
            // action nobody can perform, which is how the contractor register 403'd for everyone.
            'sheq.contractor.manage',   // §5.9 — register contractors, attach compliance documents
            'sheq.competency.manage',   // §5.7 — define competencies and appointments
            'sheq.compliance.manage',   // §5.10 — link evidence to the legal register
            'sheq.form.manage',         // SHEQ-REG-6 — generate, amend and finalise statutory forms
            // MANAGER
            'sheq.obligation.submit',
            'sheq.report.view',
            'sheq.configure',
            'sheq.pack.manage',
            // Guarded reads
            'sheq.confidential.read',   // SHEQ-TEN-4
            'sheq.pii.read',            // SHEQ-DATA-4
            'sheq.permit.override',     // SHEQ-COMP-9 — recorded as a risk acceptance
        ];
    }

    public function run(): void
    {
        foreach (self::permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Roles are not created here: role design belongs to the tenant, and inventing one puts
        // users in a group nobody asked for. Existing platform roles that clearly map get the set.
        foreach (['administrator' => self::permissions(), 'super admin' => self::permissions()] as $roleName => $grants) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($grants);
        }

        $category = Category::firstOrCreate(
            ['name' => 'Operations & Support'],
            ['active' => 1, 'description' => 'Operations & Support', 'icon' => 'settings']
        );

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active' => 1,
            'category_id' => $category->id,
            'name' => 'SHEQ',
            'path' => '/sheq',
            'font_awesome' => 'shield-check',
        ])->save();

        // Single source of truth for navigation: the hub config, so nav and tier enforcement can
        // never drift apart.
        $ribbons = SheqHubs::navRibbons();

        $ribbonIds = [];
        foreach ($ribbons as $i => $r) {
            $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => $r['name']]);
            if (! $ribbon->exists) {
                $ribbon->uuid = (string) Str::uuid();
            }
            $ribbon->fill([
                'active' => 1,
                'sort_order' => $i + 1,
                'ribbon_path' => $r['path'],
                'description' => $r['name'],
                'font_awesome' => $r['icon'],
                'access_level' => $r['access_level'] ?? self::LEVEL,
            ])->save();
            $ribbonIds[] = $ribbon->id;
        }

        $stale = Ribbon::where('module_id', $module->id)
            ->whereNotIn('ribbon_name', array_column($ribbons, 'name'))
            ->pluck('id');
        if ($stale->isNotEmpty()) {
            RibbonAccess::whereIn('ribbon_id', $stale)->delete();
            Ribbon::whereIn('id', $stale)->delete();
        }

        $this->grantAccess($category, $module, $ribbonIds);

        $this->command?->info('SHEQ module registered under Operations & Support.');
    }

    /**
     * Grant access to everyone who already reaches another Operations & Support module, so SHEQ
     * appears for the people who work in that area. Falls back to all users on an install where
     * nothing in the category has been granted yet.
     */
    private function grantAccess(Category $category, Modules $module, array $ribbonIds): void
    {
        $categoryModuleIds = Modules::where('category_id', $category->id)->pluck('id');

        $userIds = ModuleAccess::whereIn('module_id', $categoryModuleIds)
            ->where('access_level', '>', 0)
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            $userIds = DB::table('users')->pluck('id');
        }

        // Access rows FK to users; an orphaned id from a since-deleted user would fail the insert.
        $userIds = $userIds->intersect(DB::table('users')->pluck('id'))->values();

        foreach ($userIds as $userId) {
            CategoryAccess::firstOrCreate(
                ['category_id' => $category->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL]
            );
            ModuleAccess::firstOrCreate(
                ['module_id' => $module->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL]
            );
            foreach ($ribbonIds as $ribbonId) {
                RibbonAccess::firstOrCreate(
                    ['ribbon_id' => $ribbonId, 'user_id' => $userId],
                    ['active' => 1, 'access_level' => self::LEVEL]
                );
            }
        }
    }
}
