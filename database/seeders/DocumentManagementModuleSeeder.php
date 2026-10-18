<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Modules\Operations\DocumentManagement\Support\DocumentHubs;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Registers Document Management under "Operations & Support": the module row, its nav ribbons, the
 * permission set, and access for the people who already work in that area. Idempotent, and
 * re-running prunes ribbons retired from DocumentHubs.
 */
class DocumentManagementModuleSeeder extends Seeder
{
    private const LEVEL = 5; // SUPERUSER

    private const MODULE_CODE = 'document_management';

    /**
     * Permissions, graded by what the action costs when it is wrong.
     *
     * Reading and uploading sit lowest: a repository nobody can contribute to is a shared drive
     * with extra steps. The two that destroy things are separate from each other and from
     * everything else — `document.delete` is recoverable, `document.purge` is not, and a role that
     * should be able to tidy up is not automatically a role that should be able to destroy
     * evidence.
     *
     * `document.legal_hold` is its own permission for the same reason: lifting a hold is the act
     * somebody will be asked about later, and it must not ride along with ordinary editing.
     *
     * `document.share` is separate from `document.download` because the two hand a copy to
     * different people: download gives one to somebody with an account here, sharing gives one to
     * somebody without. A role trusted to read everything is not automatically trusted to publish
     * it.
     *
     * `document.retention.manage` decides who answers the disposal queue. It is deliberately not
     * `document.purge`: the person who reviews a retention schedule is rarely the person who should
     * be permanently destroying anything, and approving a disposal is not carrying it out.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        return [
            // Everyday use
            'document.view',
            'document.download',
            'document.upload',
            'document.update',
            'document.replace',
            'document.folder.manage',
            // Reversible housekeeping
            'document.archive',
            'document.restore',
            'document.delete',
            // Governance
            'document.share',
            'document.confidential.manage',
            'document.legal_hold',
            'document.retention.manage',
            'document.activity.view',
            // Irreversible, and configuration that decides what may enter the system at all
            'document.purge',
            'document.settings.manage',
        ];
    }

    public function run(): void
    {
        foreach (self::permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Roles are the tenant's to design; inventing one puts people in a group nobody asked for.
        // The two platform roles that clearly map get the full set.
        foreach (['administrator', 'super admin'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo(self::permissions());
        }

        $category = Category::firstOrCreate(
            ['name' => 'Operations & Support'],
            ['active' => 1, 'description' => 'Operations & Support', 'icon' => 'settings'],
        );

        $module = Modules::firstOrNew(['code_name' => self::MODULE_CODE]);
        if (! $module->exists) {
            $module->uuid = (string) Str::uuid();
        }
        $module->fill([
            'active'       => 1,
            'category_id'  => $category->id,
            'name'         => 'Document Management',
            'path'         => '/document-management',
            'font_awesome' => 'folder-multiple-outline',
        ])->save();

        /*
         * The billing catalogue as well as the security module.
         *
         * A module registered in only one of the two is invisible in a way that takes a while to
         * diagnose: entitlement checks pass, the nav appears, and then nobody can buy it — or it
         * sells and never switches on. Both rows or neither.
         */
        DB::table('billing_modules')->updateOrInsert(
            ['code' => self::MODULE_CODE],
            [
                'name'          => 'Document Management',
                'category'      => 'Operations',
                'price_monthly' => 350.00,
                'is_sellable'   => true,
                'is_active'     => true,
                'sort'          => 53,
                'updated_at'    => now(),
                'created_at'    => now(),
            ],
        );

        // Navigation comes from the hub config so nav and tier enforcement cannot drift apart.
        $ribbons = DocumentHubs::navRibbons();

        $ribbonIds = [];
        foreach ($ribbons as $i => $r) {
            $ribbon = Ribbon::firstOrNew(['module_id' => $module->id, 'ribbon_name' => $r['name']]);
            if (! $ribbon->exists) {
                $ribbon->uuid = (string) Str::uuid();
            }
            $ribbon->fill([
                'active'       => 1,
                'sort_order'   => $i + 1,
                'ribbon_path'  => $r['path'],
                'description'  => $r['name'],
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

        $this->command?->info('Document Management registered under Operations & Support.');
    }

    /**
     * Grants access to everybody who already reaches another Operations & Support module, so the
     * repository appears for the people who work in that area. Falls back to all users on an
     * installation where nothing in the category has been granted yet.
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

        // Access rows reference users; an id left behind by a deleted user would fail the insert.
        $userIds = $userIds->intersect(DB::table('users')->pluck('id'))->values();

        foreach ($userIds as $userId) {
            CategoryAccess::firstOrCreate(
                ['category_id' => $category->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL],
            );
            ModuleAccess::firstOrCreate(
                ['module_id' => $module->id, 'user_id' => $userId],
                ['active' => 1, 'access_level' => self::LEVEL],
            );
            foreach ($ribbonIds as $ribbonId) {
                RibbonAccess::firstOrCreate(
                    ['ribbon_id' => $ribbonId, 'user_id' => $userId],
                    ['active' => 1, 'access_level' => self::LEVEL],
                );
            }
        }
    }
}
