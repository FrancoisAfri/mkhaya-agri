<?php

namespace Database\Seeders;

use App\Core\Category\Models\Category;
use App\Core\Category\Models\CategoryAccess;
use App\Core\Module\Models\ModuleAccess;
use App\Core\Module\Models\Modules;
use App\Core\Ribbon\Models\Ribbon;
use App\Core\Ribbon\Models\RibbonAccess;
use App\Support\Hubs\HubRegistry;
use App\Core\User\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;

/**
 * Creates the "Supply Chain & Procurement Management" category with two modules
 * (Contract Management, Supplier Management), their sidebar ribbons, the user
 * access rows for the first/admin user, and the module permissions (granted to
 * the administrator + super admin roles).
 */
class SupplyChainNavigationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Grant navigation access to every user so the activated modules are
            // visible in the sidebar (actual actions are still gated by the
            // module permissions + policies). Admins can refine per-user later.
            $userIds = User::query()->pluck('id')->all() ?: [1];

            $category = Category::firstOrCreate(
                ['name' => 'Supply Chain Management'],
                [
                    'active'      => 1,
                    'description' => 'Contracts, suppliers, procurement and vendor lifecycle management',
                    'icon'        => 'truck',
                ]
            );
            foreach ($userIds as $uid) {
                $this->grantCategoryAccess($category->id, $uid);
            }

            foreach ($this->modules() as $data) {
                $module = Modules::updateOrCreate(
                    ['code_name' => $data['code_name']],
                    [
                        'uuid'         => Str::uuid(),
                        'active'       => 1,
                        'category_id'  => $category->id,
                        'name'         => $data['name'],
                        'path'         => $data['path'],
                        'font_awesome' => $data['icon'],
                    ]
                );
                foreach ($userIds as $uid) {
                    $this->grantModuleAccess($module->id, $uid);
                }

                foreach ($this->ribbonsFor($data) as $order => $ribbon) {
                    $row = Ribbon::updateOrCreate(
                        ['module_id' => $module->id, 'ribbon_path' => $ribbon['path']],
                        [
                            'uuid'         => Str::uuid(),
                            'active'       => 1,
                            'sort_order'   => $order + 1,
                            'ribbon_name'  => $ribbon['name'],
                            'description'  => $ribbon['name'],
                            'font_awesome' => $ribbon['icon'],
                            'access_level' => $ribbon['access_level'] ?? 1,
                        ]
                    );
                    foreach ($userIds as $uid) {
                        $this->grantRibbonAccess($row->id, $uid);
                    }
                }

                // Anything this module used to have that is no longer in its ribbon list. Without
                // this a hubbed module ends up with both shapes at once — a "Budgets" hub ribbon
                // beside a flat "Budgets" one pointing somewhere else — and the sidebar shows the
                // same feature twice under two different names.
                $this->pruneStaleRibbons($module->id, $this->ribbonsFor($data));
            }

            $this->seedPermissions();
        });
    }


    /**
     * The ribbons a module should actually have.
     *
     * A module that has been converted to hubs declares its navigation in its ModuleHubs class, and
     * that class is the single source of truth — `hubs:sync` rebuilds from it and prunes anything
     * else. Seeding this file's flat list alongside it produced two competing shapes on every fresh
     * install, which is only invisible because most people run `hubs:sync` afterwards without
     * realising they are repairing something.
     *
     * @param  array{code_name:string, ribbons:array}  $data
     * @return array<int, array{name:string, path:string, icon:string, access_level?:int}>
     */
    private function ribbonsFor(array $data): array
    {
        $hubs = HubRegistry::for($data['code_name']);

        return $hubs ? $hubs::navRibbons() : $data['ribbons'];
    }

    /**
     * Remove ribbons that are no longer declared, re-pointing their how-tos first.
     *
     * The re-point matters: `module_how_tos` cascade-deletes with the ribbon, so dropping a retired
     * ribbon without moving its content silently destroys the help articles written against it.
     */
    private function pruneStaleRibbons(int $moduleId, array $keep): void
    {
        $keepPaths = collect($keep)->pluck('path')->all();

        $stale = Ribbon::where('module_id', $moduleId)->whereNotIn('ribbon_path', $keepPaths)->get();

        if ($stale->isEmpty()) {
            return;
        }

        $fallback = Ribbon::where('module_id', $moduleId)->whereIn('ribbon_path', $keepPaths)->value('id');

        foreach ($stale as $ribbon) {
            if ($fallback) {
                DB::table('module_how_tos')->where('ribbon_id', $ribbon->id)
                    ->update(['ribbon_id' => $fallback, 'updated_at' => now()]);
            }

            $ribbon->delete();
        }
    }

    private function modules(): array
    {
        return [
            [
                'name'      => 'Contract Management',
                'code_name' => 'contract_management',
                'path'      => '/contracts',
                'icon'      => 'file-contract',
                'ribbons'   => [
                    ['name' => 'Dashboard',    'path' => 'contracts',           'icon' => 'tachometer-alt'],
                    ['name' => 'Contracts',    'path' => 'contracts/list',      'icon' => 'file-signature'],
                    ['name' => 'New Contract', 'path' => 'contracts/create',    'icon' => 'plus-circle'],
                    ['name' => 'Bulk Upload',  'path' => 'contracts/bulk-upload','icon' => 'upload'],
                    ['name' => 'Templates',    'path' => 'contracts/templates', 'icon' => 'template'],
                    ['name' => 'Search',       'path' => 'contracts/search',    'icon' => 'search'],
                    ['name' => 'Requests',     'path' => 'contracts/requests',  'icon' => 'inbox'],
                    ['name' => 'Approvals',    'path' => 'contracts/approvals', 'icon' => 'check-double'],
                    ['name' => 'Clause Library', 'path' => 'contracts/clauses', 'icon' => 'book'],
                    ['name' => 'Appraisals',   'path' => 'contracts/appraisals','icon' => 'star'],
                    ['name' => 'AI Copilot',   'path' => 'contracts/copilot',   'icon' => 'robot'],
                    ['name' => 'Reports',      'path' => 'contracts/reports',   'icon' => 'chart-bar'],
                    ['name' => 'Analytics',    'path' => 'contracts/analytics', 'icon' => 'chart-pie'],
                    ['name' => 'Scheduled Reports', 'path' => 'contracts/scheduled-reports', 'icon' => 'calendar-time'],
                    ['name' => 'Settings',     'path' => 'contracts/settings',  'icon' => 'cog'],
                ],
            ],
            [
                'name'      => 'Supplier Management',
                'code_name' => 'supplier_management',
                'path'      => '/suppliers',
                'icon'      => 'truck-loading',
                'ribbons'   => [
                    ['name' => 'Dashboard',     'path' => 'suppliers',            'icon' => 'tachometer-alt'],
                    ['name' => 'Suppliers',     'path' => 'suppliers/list',       'icon' => 'building'],
                    ['name' => 'Add Supplier',  'path' => 'suppliers/create',     'icon' => 'plus-circle'],
                    ['name' => 'Onboarding',    'path' => 'suppliers/onboarding', 'icon' => 'user-plus'],
                    ['name' => 'KYC Approvals', 'path' => 'suppliers/kyc',        'icon' => 'shield-alt'],
                    ['name' => 'Performance',   'path' => 'suppliers/appraisals', 'icon' => 'star'],
                    ['name' => 'Corrective Actions', 'path' => 'suppliers/scars', 'icon' => 'alert-triangle'],
                    ['name' => 'Sourcing (RFx)', 'path' => 'suppliers/sourcing',  'icon' => 'gavel'],
                    ['name' => 'Purchase Orders', 'path' => 'suppliers/purchase-orders', 'icon' => 'file-invoice'],
                    ['name' => 'Invoices',      'path' => 'suppliers/invoices',   'icon' => 'receipt'],
                    ['name' => 'Spend Analysis', 'path' => 'suppliers/spend/report', 'icon' => 'chart-pie'],
                    ['name' => 'Reports',       'path' => 'suppliers/reports',    'icon' => 'chart-bar'],
                    ['name' => 'Analytics',     'path' => 'suppliers/analytics',  'icon' => 'chart-dots'],
                    ['name' => 'Scheduled Reports', 'path' => 'suppliers/scheduled-reports', 'icon' => 'calendar-time'],
                    ['name' => 'Search',        'path' => 'suppliers/search',     'icon' => 'search'],
                    ['name' => 'Settings',      'path' => 'suppliers/settings',   'icon' => 'cog'],
                ],
            ],
            [
                'name'      => 'Budget Management',
                'code_name' => 'budget_management',
                'path'      => '/budgets',
                'icon'      => 'wallet',
                // Flat ribbons for a fresh install; the hub migration consolidates them into the
                // groups declared in BudgetHubs, which is the single source of truth for the nav.
                'ribbons'   => [
                    ['name' => 'Dashboard',   'path' => 'budgets',          'icon' => 'tachometer-alt'],
                    ['name' => 'Budgets',     'path' => 'budgets/list',     'icon' => 'cash'],
                    ['name' => 'New Budget',  'path' => 'budgets/create',   'icon' => 'plus-circle'],
                    ['name' => 'Settings',    'path' => 'budgets/settings', 'icon' => 'cog'],
                ],
            ],
            [
                'name'      => 'Purchase Requests',
                'code_name' => 'procurement_requests',
                'path'      => '/purchase-requests',
                'icon'      => 'shopping-cart',
                'ribbons'   => [
                    ['name' => 'Dashboard',    'path' => 'procurement/dashboard',    'icon' => 'tachometer-alt'],
                    ['name' => 'Requests',     'path' => 'purchase-requests',        'icon' => 'list'],
                    ['name' => 'New Request',  'path' => 'purchase-requests/create', 'icon' => 'plus-circle'],
                    ['name' => 'Approvals',    'path' => 'procurement/approvals',    'icon' => 'check-double'],
                    ['name' => 'Requisitions', 'path' => 'requisitions',             'icon' => 'clipboard-list'],
                    ['name' => 'Purchase Orders', 'path' => 'purchase-orders',       'icon' => 'file-invoice'],
                    ['name' => 'Invoices',     'path' => 'invoices',                 'icon' => 'receipt'],
                    ['name' => 'Spend Intelligence', 'path' => 'procurement/intelligence', 'icon' => 'brain'],
                    ['name' => 'Policy Rules', 'path' => 'procurement/policies',     'icon' => 'gavel'],
                ],
            ],
        ];
    }

    private function seedPermissions(): void
    {
        $permissions = [
            'contracts.view', 'contracts.create', 'contracts.update', 'contracts.delete', 'contracts.approve',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.delete', 'suppliers.approve',
            'budgets.view', 'budgets.create', 'budgets.update', 'budgets.delete', 'budgets.approve',
            'procurement.view', 'procurement.create', 'procurement.update', 'procurement.delete', 'procurement.approve',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['administrator', 'super admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permissions);
        }

        $this->seedProcurementDefaults();
    }

    /** Default procurement policy rules (the approval matrix + thresholds). */
    private function seedProcurementDefaults(): void
    {
        $rules = [
            ['name' => 'Manager approval ≥ R10,000',   'type' => 'approval_threshold', 'min_amount' => 10000,  'role' => 'manager',    'sort_order' => 1],
            ['name' => 'Finance approval ≥ R50,000',   'type' => 'approval_threshold', 'min_amount' => 50000,  'role' => 'finance',    'sort_order' => 2],
            ['name' => 'Executive approval ≥ R100,000', 'type' => 'approval_threshold', 'min_amount' => 100000, 'role' => 'executive', 'sort_order' => 3],
            ['name' => 'IT approval for IT assets ≥ R25,000', 'type' => 'approval_threshold', 'min_amount' => 25000, 'role' => 'IT', 'params' => ['category' => 'IT'], 'sort_order' => 4],
            ['name' => 'Three quotations ≥ R30,000',   'type' => 'quotation_threshold', 'min_amount' => 30000, 'params' => ['quotes' => 3], 'sort_order' => 5],
            ['name' => 'Emergency requires justification', 'type' => 'emergency_justification', 'sort_order' => 6],
            ['name' => 'Approved budget required',      'type' => 'budget_required', 'sort_order' => 7],
            ['name' => 'No self-approval',             'type' => 'self_approval', 'sort_order' => 8],
        ];

        foreach ($rules as $r) {
            // Set uuid explicitly so seeding works regardless of the deployed
            // model version (the table's uuid column is NOT NULL).
            \App\Modules\Procurement\Models\ProcurementPolicyRule::firstOrCreate(
                ['name' => $r['name']],
                array_merge(['active' => true, 'uuid' => (string) Str::uuid()], $r)
            );
        }

        $statuses = [
            ['key' => 'draft', 'label' => 'Draft', 'sort_order' => 1],
            ['key' => 'pending_approval', 'label' => 'Pending Approval', 'sort_order' => 2],
            ['key' => 'validation_failed', 'label' => 'Validation Failed', 'sort_order' => 3],
            ['key' => 'approved', 'label' => 'Approved', 'sort_order' => 4],
            ['key' => 'rejected', 'label' => 'Rejected', 'sort_order' => 5, 'is_terminal' => true],
            ['key' => 'cancelled', 'label' => 'Cancelled', 'sort_order' => 6, 'is_terminal' => true],
            ['key' => 'converted', 'label' => 'Converted to Requisition', 'sort_order' => 7, 'is_terminal' => true],
        ];
        foreach ($statuses as $s) {
            \App\Modules\Procurement\Models\PurchaseRequestStatus::firstOrCreate(['key' => $s['key']], $s);
        }
    }

    private function grantCategoryAccess(int $categoryId, int $userId): void
    {
        CategoryAccess::firstOrCreate(
            ['category_id' => $categoryId, 'user_id' => $userId],
            ['active' => 1, 'access_level' => 5]
        );
    }

    private function grantModuleAccess(int $moduleId, int $userId): void
    {
        ModuleAccess::firstOrCreate(
            ['module_id' => $moduleId, 'user_id' => $userId],
            ['active' => 1, 'access_level' => 5]
        );
    }

    private function grantRibbonAccess(int $ribbonId, int $userId): void
    {
        RibbonAccess::firstOrCreate(
            ['ribbon_id' => $ribbonId, 'user_id' => $userId],
            ['active' => 1, 'access_level' => 5]
        );
    }
}
