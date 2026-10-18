<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\BomLine;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Shared setup for the Manufacturing suite: an entitled company, an active module, the permission
 * set, and five people at deliberately different grant levels.
 *
 * The roles are separated the way the real jobs are. The engineer drafts but cannot approve, the
 * approver signs off but cannot draft, the planner reads, the configurator sets up the plant, and
 * the outsider is authenticated with nothing. Several tests depend on the engineer NOT holding
 * mfg.bom.approve -- that separation is the control the BOM approval requirement exists to impose.
 */
abstract class ManufacturingTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $engineer;

    protected User $approver;

    protected User $planner;

    protected User $configurator;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about controllers, policies and payloads, not asset bundling.
        $this->withoutVite();

        $this->activateModule();

        foreach (RoleCatalog::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->company = Company::create(['name' => 'Foundry & Forge']);
        $this->entitleCompany($this->company);

        $this->createUsers();

        foreach ([$this->engineer, $this->approver, $this->planner, $this->configurator, $this->outsider] as $user) {
            $this->grantModuleTier($user);
        }

        // Units, sequences and the default policy -- what mfg:install-reference-data does.
        $this->asCompany($this->company, fn () => app(ManufacturingReferenceData::class)->installFor($this->company->id));
    }

    protected function activateModule(): void
    {
        $categoryId = DB::table('security_categories')->where('name', 'Operations & Support')->value('id')
            ?? DB::table('security_categories')->insertGetId([
                'name' => 'Operations & Support', 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'manufacturing'],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Manufacturing & Production',
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        Cache::forget('module_active_manufacturing');
    }

    protected function entitleCompany(Company $company): void
    {
        DB::table('company_modules')->updateOrInsert(
            ['company_id' => $company->id, 'module_code' => 'manufacturing'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    protected function createUsers(): void
    {
        $engineerRole = Role::firstOrCreate(['name' => 'mfg test engineer', 'guard_name' => 'web']);
        $engineerRole->syncPermissions([
            RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ITEMS, RoleCatalog::VIEW_BOMS,
            RoleCatalog::MANAGE_ITEMS, RoleCatalog::MANAGE_BOMS,
            RoleCatalog::VIEW_ROUTINGS, RoleCatalog::MANAGE_ROUTINGS,
        ]);

        $approverRole = Role::firstOrCreate(['name' => 'mfg test approver', 'guard_name' => 'web']);
        $approverRole->syncPermissions([
            RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ITEMS, RoleCatalog::VIEW_BOMS,
            RoleCatalog::APPROVE_BOMS, RoleCatalog::OBSOLETE_BOMS, RoleCatalog::APPROVE_SUBSTITUTIONS,
            RoleCatalog::VIEW_ROUTINGS, RoleCatalog::APPROVE_ROUTINGS,
        ]);

        $plannerRole = Role::firstOrCreate(['name' => 'mfg test planner', 'guard_name' => 'web']);
        $plannerRole->syncPermissions([
            RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ITEMS, RoleCatalog::VIEW_BOMS,
            RoleCatalog::VIEW_ROUTINGS,
        ]);

        $configRole = Role::firstOrCreate(['name' => 'mfg test configurator', 'guard_name' => 'web']);
        $configRole->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::CONFIGURE]);

        $this->engineer = $this->makeUser('mfg-engineer@example.test', $engineerRole);
        $this->approver = $this->makeUser('mfg-approver@example.test', $approverRole);
        $this->planner = $this->makeUser('mfg-planner@example.test', $plannerRole);
        $this->configurator = $this->makeUser('mfg-config@example.test', $configRole);
        $this->outsider = $this->makeUser('mfg-outsider@example.test', null);
    }

    protected function makeUser(string $email, ?Role $role): User
    {
        $user = User::factory()->create(['email' => $email, 'status' => 1, 'company_id' => $this->company->id]);

        if ($role) {
            $user->assignRole($role);
        }

        // Spatie caches the permission map; without this the role is invisible to the first can().
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Hub tier and permissions are separate gates; give everyone the top tier so a tier redirect is never mistaken for a policy decision. */
    protected function grantModuleTier(User $user, int $level = 5): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'manufacturing')->value('id');

        DB::table('security_module_access')->updateOrInsert(
            ['module_id' => $moduleId, 'user_id' => $user->id],
            ['active' => 1, 'access_level' => $level, 'created_at' => now(), 'updated_at' => now()]
        );

        Cache::forget('hub_module_id:manufacturing');
    }

    /** Run a callback with a company as the active tenant. */
    protected function asCompany(Company $company, callable $callback): mixed
    {
        $current = app(CurrentCompany::class);
        $current->set($company->id);

        try {
            return $callback();
        } finally {
            $current->clear();
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────────────────

    protected function uom(string $code): Uom
    {
        return $this->asCompany($this->company, fn () => Uom::where('code', $code)->firstOrFail());
    }

    /** A finance stock item, plus its engineering extension of the given kind. */
    protected function product(string $code, string $kind = 'FINISHED', string $uom = 'kg'): Item
    {
        return $this->asCompany($this->company, function () use ($code, $kind, $uom) {
            $stock = FinStockItem::create([
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => "{$code} (test)",
                'uom' => $uom,
                'standard_cost' => 10,
                'is_active' => true,
            ]);

            return Item::create([
                'company_id' => $this->company->id,
                'stock_item_id' => $stock->id,
                'kind' => $kind,
                'default_uom_id' => $this->uom($uom)->id,
                'is_active' => true,
            ]);
        });
    }

    /** A bought-in component's stock item. Components are finance records; they need no engineering row. */
    protected function material(string $code, string $uom = 'kg'): FinStockItem
    {
        return $this->asCompany($this->company, fn () => FinStockItem::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => "{$code} (test)",
            'uom' => $uom,
            'standard_cost' => 5,
            'is_active' => true,
        ]));
    }

    /** A draft BOM with the given components: [[stockItem, qtyPer, scrap%, uomCode, extra]...]. */
    protected function draftBom(Item $item, array $components = [], float $yield = 1, array $attributes = []): Bom
    {
        return $this->asCompany($this->company, function () use ($item, $components, $yield, $attributes) {
            $bom = Bom::create(array_merge([
                'company_id' => $this->company->id,
                'item_id' => $item->id,
                'revision' => (int) Bom::withoutGlobalScopes()->where('item_id', $item->id)->max('revision') + 1,
                'status' => Bom::DRAFT,
                'yield_quantity' => $yield,
                'yield_uom_id' => $item->default_uom_id,
                'process_loss_percent' => 0,
                'created_by' => $this->engineer->id,
            ], $attributes));

            foreach ($components as $i => $c) {
                [$stock, $qty, $scrap, $uom, $extra] = array_pad($c, 5, null);

                BomLine::create(array_merge([
                    'company_id' => $this->company->id,
                    'bom_id' => $bom->id,
                    'line_no' => ($i + 1) * 10,
                    'component_stock_item_id' => $stock->id,
                    'quantity_per' => $qty,
                    'uom_id' => $this->uom($uom ?? 'kg')->id,
                    'scrap_percent' => $scrap ?? 0,
                    'line_kind' => BomLine::COMPONENT,
                ], $extra ?? []));
            }

            return $bom->fresh();
        });
    }

    /** An approved, default BOM, bypassing the approval workflow (for tests that are not about it). */
    protected function approvedBom(Item $item, array $components, float $yield = 1, array $attributes = []): Bom
    {
        $bom = $this->draftBom($item, $components, $yield, $attributes);

        // Direct write is allowed on the header's status: only the recipe fields are guarded.
        DB::table('mfg_boms')->where('id', $bom->id)->update([
            'status' => Bom::APPROVED, 'is_default' => true, 'approved_by' => $this->approver->id,
            'approved_at' => now(), 'effective_from' => now()->toDateString(),
        ]);

        return $bom->fresh();
    }

    /**
     * A routing with operations: [[name, setup, run, extra]...]. Drafted, or approved directly when
     * $approved, bypassing the workflow for tests that are not about it.
     */
    protected function routing(Item $item, array $operations, array $attributes = [], bool $approved = false): \App\Modules\Operations\Manufacturing\Models\Routing
    {
        return $this->asCompany($this->company, function () use ($item, $operations, $attributes, $approved) {
            $routing = \App\Modules\Operations\Manufacturing\Models\Routing::create(array_merge([
                'company_id' => $this->company->id, 'item_id' => $item->id,
                'revision' => (int) \App\Modules\Operations\Manufacturing\Models\Routing::withoutGlobalScopes()->where('item_id', $item->id)->max('revision') + 1,
                'status' => 'DRAFT', 'standard_yield_percent' => 100, 'created_by' => $this->engineer->id,
            ], $attributes));

            foreach ($operations as $i => $op) {
                [$name, $setup, $run, $extra] = array_pad($op, 4, []);
                $routing->operations()->create(array_merge([
                    'company_id' => $this->company->id, 'sequence' => ($i + 1) * 10, 'name' => $name,
                    'machine_type' => 'GENERIC', 'setup_minutes' => $setup, 'run_minutes' => $run,
                ], $extra ?? []));
            }

            if ($approved) {
                DB::table('mfg_routings')->where('id', $routing->id)->update([
                    'status' => 'APPROVED', 'is_default' => true, 'approved_by' => $this->approver->id,
                    'approved_at' => now(), 'effective_from' => now()->toDateString(),
                ]);
            }

            return $routing->fresh();
        });
    }

    /**
     * Books the stock ledger can post to: the company's control accounts (installed the way a real
     * tenant gets them, through finance:ensure-chart) and an open period.
     */
    protected function openBooks(): void
    {
        if (! \App\Modules\Finance\Accounting\Models\FinAccount::withoutGlobalScopes()->whereNull('company_id')->where('code', '1310')->exists()) {
            (new \Database\Seeders\FinanceGlSeeder())->run();
        }

        $this->artisan('finance:ensure-chart', ['--company' => $this->company->id, '--install' => true]);

        if (! \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::forDate(now(), null)) {
            \App\Modules\Finance\Accounting\Models\FinFiscalPeriod::create([
                'company_id' => null, 'fiscal_year' => (int) now()->year, 'period_no' => (int) now()->month,
                'start_date' => now()->startOfMonth(), 'end_date' => now()->endOfMonth(), 'status' => 'open',
            ]);
        }
    }

    /** A stock item the ledger can post (accounts attached), in this tenant. */
    protected function stockItem(string $code, string $uom = 'kg', float $cost = 10): \App\Modules\Finance\Accounting\Models\FinStockItem
    {
        return $this->asCompany($this->company, fn () => app(\App\Modules\Finance\Accounting\Services\InventoryService::class)->createItem([
            'company_id' => $this->company->id, 'code' => $code, 'name' => "{$code} (test)", 'uom' => $uom,
            'cost_method' => 'weighted_avg', 'standard_cost' => $cost, 'is_active' => true,
        ]));
    }

    protected function warehouse(string $code = 'WH1'): \App\Modules\Finance\Accounting\Models\FinWarehouse
    {
        return $this->asCompany($this->company, fn () => \App\Modules\Finance\Accounting\Models\FinWarehouse::create([
            'company_id' => $this->company->id, 'code' => $code, 'name' => "Warehouse {$code}", 'is_active' => true,
        ]));
    }

    protected function factory(string $code = 'JHB1'): Factory
    {
        return $this->asCompany($this->company, fn () => Factory::create([
            'company_id' => $this->company->id, 'code' => $code, 'name' => "Plant {$code}",
            'timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR', 'measurement_system' => 'METRIC',
            'is_active' => true,
        ]));
    }
}
