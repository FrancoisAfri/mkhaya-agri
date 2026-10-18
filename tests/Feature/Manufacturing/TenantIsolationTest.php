<?php

namespace Tests\Feature\Manufacturing;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * MFG acceptance: "Company-level isolation must apply regardless of the factory selected."
 *
 * Tests are run on a superuser connection, which ignores row-level security -- so these cover the
 * application layer (the company scope, uuid route binding, and policies), and the database layer
 * is asserted from the catalogue instead, which is deterministic whoever is connected.
 */
class TenantIsolationTest extends ManufacturingTestCase
{
    private Company $rival;

    private User $rivalAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rival = Company::create(['name' => 'Rival Castings']);
        $this->entitleCompany($this->rival);

        $role = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'mfg rival admin', 'guard_name' => 'web']);
        $role->syncPermissions(RoleCatalog::all());

        $this->rivalAdmin = User::factory()->create([
            'email' => 'rival-admin@example.test', 'status' => 1, 'company_id' => $this->rival->id,
        ]);
        $this->rivalAdmin->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->grantModuleTier($this->rivalAdmin);
        $this->asCompany($this->rival, fn () => app(\App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData::class)
            ->installFor($this->rival->id));
    }

    private function rivalFactory(): Factory
    {
        return $this->asCompany($this->rival, fn () => Factory::create([
            'company_id' => $this->rival->id, 'code' => 'RIV1', 'name' => 'Rival Plant', 'is_active' => true,
        ]));
    }

    public function test_one_tenants_factory_is_invisible_to_another(): void
    {
        $theirs = $this->rivalFactory();

        $props = $this->actingAs($this->configurator)
            ->get(route('manufacturing.setup.factories'))->assertOk()->viewData('page')['props'];

        $this->assertNotContains($theirs->uuid, array_column($props['factories'], 'id'));
    }

    public function test_another_tenants_factory_cannot_be_read_changed_or_deleted_by_guessing_its_uuid(): void
    {
        $theirs = $this->rivalFactory();

        $this->actingAs($this->configurator)
            ->patch(route('manufacturing.setup.factories.update', $theirs->uuid), [
                'code' => 'RIV1', 'name' => 'Taken over', 'timezone' => 'UTC', 'currency' => 'ZAR',
                'measurement_system' => 'METRIC',
            ])->assertNotFound();

        $this->actingAs($this->configurator)
            ->delete(route('manufacturing.setup.factories.destroy', $theirs->uuid))->assertNotFound();

        $this->actingAs($this->configurator)
            ->post(route('manufacturing.setup.departments.store', $theirs->uuid), ['code' => 'X', 'name' => 'X'])
            ->assertNotFound();

        $this->assertSame('Rival Plant', DB::table('mfg_factories')->where('id', $theirs->id)->value('name'));
    }

    public function test_another_tenants_product_and_recipe_are_not_reachable(): void
    {
        // Built in the rival's tenant.
        $stock = $this->asCompany($this->rival, fn () => \App\Modules\Finance\Accounting\Models\FinStockItem::create([
            'company_id' => $this->rival->id, 'code' => 'SECRET-WIDGET', 'name' => 'Secret widget',
            'uom' => 'ea', 'is_active' => true,
        ]));
        $item = $this->asCompany($this->rival, fn () => Item::create([
            'company_id' => $this->rival->id, 'stock_item_id' => $stock->id, 'kind' => 'FINISHED', 'is_active' => true,
        ]));
        $bom = $this->asCompany($this->rival, fn () => Bom::create([
            'company_id' => $this->rival->id, 'item_id' => $item->id, 'revision' => 1,
            'status' => 'DRAFT', 'yield_quantity' => 1,
        ]));

        $this->actingAs($this->engineer)
            ->get(route('manufacturing.engineering.products.show', $item->uuid))->assertNotFound();
        $this->actingAs($this->engineer)
            ->get(route('manufacturing.engineering.boms.show', $bom->uuid))->assertNotFound();
        $this->actingAs($this->approver)
            ->post(route('manufacturing.engineering.boms.approve', $bom->uuid))->assertNotFound();

        // And it is not in anybody's list.
        $products = $this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.products.index'))->viewData('page')['props']['items']['data'];
        $this->assertNotContains('SECRET-WIDGET', array_column($products, 'code'));

        $this->assertSame('DRAFT', DB::table('mfg_boms')->where('id', $bom->id)->value('status'));
    }

    public function test_a_tenant_cannot_attach_its_work_centre_to_anothers_line(): void
    {
        $mine = $this->factory('MINE');
        $theirs = $this->rivalFactory();
        $theirLine = $this->asCompany($this->rival, fn () => $theirs->productionLines()->create([
            'company_id' => $this->rival->id, 'code' => 'RL1', 'name' => 'Rival line', 'is_active' => true,
        ]));

        // The foreign line's integer id is a valid row, so `exists` alone would accept it.
        $this->actingAs($this->configurator)->post(route('manufacturing.setup.work-centres.store'), [
            'factory_uuid' => $mine->uuid, 'code' => 'STEAL', 'name' => 'Steal',
            'production_line_id' => $theirLine->id,
        ])->assertSessionHasErrors('production_line_id');

        $this->assertSame(0, DB::table('mfg_work_centres')->where('code', 'STEAL')->count());
    }

    public function test_every_manufacturing_table_is_under_forced_row_level_security(): void
    {
        $tables = collect(DB::select(
            "select c.relname, c.relrowsecurity as rls, c.relforcerowsecurity as forced
               from pg_class c join pg_namespace n on n.oid = c.relnamespace
              where n.nspname = 'public' and c.relkind = 'r' and c.relname like 'mfg\\_%'"
        ));

        $this->assertGreaterThanOrEqual(22, $tables->count());

        foreach ($tables as $t) {
            $this->assertTrue((bool) $t->rls, "{$t->relname} has no row-level security.");
            $this->assertTrue((bool) $t->forced, "{$t->relname} does not FORCE it, so the table owner bypasses it.");
        }

        $policied = collect(DB::select(
            "select tablename from pg_policies where policyname = 'tenant_isolation' and tablename like 'mfg\\_%'"
        ))->pluck('tablename');

        $this->assertEqualsCanonicalizing($tables->pluck('relname')->all(), $policied->all(),
            'A manufacturing table has row-level security but no tenant_isolation policy.');
    }

    public function test_a_tenant_not_entitled_to_the_module_is_refused(): void
    {
        $unentitled = Company::create(['name' => 'No Licence Ltd']);
        $user = User::factory()->create([
            'email' => 'nolicence@example.test', 'status' => 1, 'company_id' => $unentitled->id,
        ]);
        $user->givePermissionTo(RoleCatalog::all());
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->grantModuleTier($user);

        // A JSON request is refused outright; a plain browser navigation is sent home with the
        // reason. Either way the module's screen is never rendered.
        $this->actingAs($user->fresh())->getJson(route('manufacturing.dashboard'))->assertForbidden();
        $this->actingAs($user->fresh())->getJson(route('manufacturing.engineering.boms.index'))->assertForbidden();

        $this->actingAs($user->fresh())->get(route('manufacturing.dashboard'))
            ->assertRedirect(route('dashboard'))->assertSessionHas('error');

        // And a write is refused too, not just a read.
        $this->actingAs($user->fresh())
            ->postJson(route('manufacturing.setup.factories.store'), [
                'code' => 'NOPE', 'name' => 'Nope', 'timezone' => 'UTC', 'currency' => 'ZAR',
                'measurement_system' => 'METRIC',
            ])->assertForbidden();
    }
}
