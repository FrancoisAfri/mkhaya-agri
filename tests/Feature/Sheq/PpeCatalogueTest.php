<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The PPE catalogue, and the thing that makes it worth having a page of its own.
 *
 * Configuration used to live in a collapsible panel on the issue register: two forms, no list, no
 * edit, no delete. A typo in a catalogue code was permanent, and the only way to inspect stock was
 * to add another row and hope the upsert matched. What is tested here is mostly the *corrections*
 * that shape could not make — and the one refusal that protects the record an inspector asks for
 * after an injury.
 */
class PpeCatalogueTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $moduleId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        // The module gate sits in front of every route here, so a user without module access is
        // redirected long before any of this is reached — see ScreenReachabilityTest, which exists
        // because the whole module once 403'd for exactly this class of reason.
        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');
        $this->moduleId = (int) $module->id;

        $this->user = $this->makeUser(['sheq.configure']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** @param  list<string>  $permissions */
    private function makeUser(array $permissions): User
    {
        $id = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'ppe-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_module_access')->insert([
            'module_id' => $this->moduleId, 'user_id' => $id,
            // ADMIN, so a refusal cannot be explained away as "they were only a reader".
            'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScopes()->findOrFail($id);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user->syncPermissions($permissions);

        return $user->fresh();
    }

    private function makeItem(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();

        DB::table('sheq_ppe_items')->insert(array_merge([
            'uuid' => $uuid,
            'company_id' => $this->companyId,
            'code' => 'BOOT-'.Str::upper(Str::random(4)),
            'name' => 'Safety boots',
            'category' => 'FOOT',
            'requires_size' => true,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        return $uuid;
    }

    // ── The corrections the old panel could not make ─────────────────────────────────────────

    public function test_an_item_can_be_corrected_after_it_was_added(): void
    {
        // The plain reason this page exists: a typo used to be permanent.
        $uuid = $this->makeItem(['code' => 'BOOT-TYPO', 'name' => 'Saftey boots']);

        $this->post("/sheq/settings/ppe/items/{$uuid}", [
            'code' => 'BOOT-01', 'name' => 'Safety boots', 'category' => 'FOOT',
            'lifespan_months' => 24, 'requires_size' => true,
        ])->assertRedirect();

        $item = DB::table('sheq_ppe_items')->where('uuid', $uuid)->first();

        $this->assertSame('BOOT-01', $item->code);
        $this->assertSame('Safety boots', $item->name);
        $this->assertSame(24, (int) $item->lifespan_months);
    }

    public function test_two_items_in_one_company_cannot_share_a_code(): void
    {
        // The code is what tells two similar items apart in the issue dropdown.
        $this->makeItem(['code' => 'HELM-01']);

        $this->post('/sheq/settings/ppe/items', [
            'code' => 'HELM-01', 'name' => 'Another helmet',
        ])->assertSessionHasErrors('code');
    }

    public function test_another_company_may_use_the_same_code(): void
    {
        /*
         * Uniqueness is per company, not global. Two customers both calling their boots "BOOT-01"
         * is completely normal, and a global constraint would have the first customer to sign up
         * quietly reserving codes for everybody else.
         */
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'PPE Fixture Co',
            'slug' => 'ppe-fixture-'.Str::random(6), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_ppe_items')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other, 'code' => 'BOOT-01',
            'name' => 'Their boots', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post('/sheq/settings/ppe/items', [
            'code' => 'BOOT-01', 'name' => 'Our boots',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    // ── The refusal that protects the issue record ───────────────────────────────────────────

    public function test_an_item_that_has_been_issued_cannot_be_deleted(): void
    {
        /*
         * PPE issue records are what an inspector asks for after an injury. Deleting the catalogue
         * entry would take that history with it, so the delete is refused and the message says to
         * retire instead — a refusal that only says "no" teaches somebody to force it.
         */
        $uuid = $this->makeItem();
        $itemId = DB::table('sheq_ppe_items')->where('uuid', $uuid)->value('id');

        DB::table('sheq_ppe_issues')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'ppe_item_id' => $itemId, 'quantity' => 1, 'issued_on' => now()->toDateString(),
            'status' => 'ISSUED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->delete("/sheq/settings/ppe/items/{$uuid}")->assertSessionHasErrors('item');

        $this->assertNull(
            DB::table('sheq_ppe_items')->where('uuid', $uuid)->value('deleted_at'),
            'An item with issue history was deleted.',
        );
    }

    public function test_retiring_keeps_the_item_and_its_history(): void
    {
        // The route out of the refusal above.
        $uuid = $this->makeItem();

        $this->post("/sheq/settings/ppe/items/{$uuid}/toggle")->assertRedirect();

        $item = DB::table('sheq_ppe_items')->where('uuid', $uuid)->first();

        $this->assertFalse((bool) $item->is_active);
        $this->assertNull($item->deleted_at);
    }

    public function test_an_unissued_item_can_be_deleted_and_takes_its_stock_with_it(): void
    {
        $uuid = $this->makeItem();
        $itemId = DB::table('sheq_ppe_items')->where('uuid', $uuid)->value('id');

        $this->post('/sheq/settings/ppe/stock', [
            'ppe_item_id' => $itemId, 'size' => '8', 'quantity_on_hand' => 10, 'reorder_level' => 2,
        ])->assertRedirect();

        $this->delete("/sheq/settings/ppe/items/{$uuid}")->assertRedirect();

        $this->assertNotNull(DB::table('sheq_ppe_items')->where('uuid', $uuid)->value('deleted_at'));
        $this->assertSame(0, DB::table('sheq_ppe_stock')->where('ppe_item_id', $itemId)->count(),
            'Stock lines were orphaned by deleting their item.');
    }

    // ── Stock ────────────────────────────────────────────────────────────────────────────────

    public function test_a_sized_item_will_not_take_a_stock_line_without_a_size(): void
    {
        /*
         * Sized PPE counted without its size is one anonymous pile, and no size can be issued from
         * it — the register would show stock and then refuse every issue.
         */
        $uuid = $this->makeItem(['requires_size' => true]);
        $itemId = DB::table('sheq_ppe_items')->where('uuid', $uuid)->value('id');

        $this->post('/sheq/settings/ppe/stock', [
            'ppe_item_id' => $itemId, 'quantity_on_hand' => 25,
        ])->assertSessionHasErrors('size');
    }

    public function test_setting_stock_twice_for_one_size_replaces_rather_than_duplicates(): void
    {
        // The count is absolute — it is filled in after counting the shelf, not accumulated.
        $uuid = $this->makeItem();
        $itemId = DB::table('sheq_ppe_items')->where('uuid', $uuid)->value('id');

        foreach ([10, 7] as $count) {
            // assertRedirect, not assertSessionHasNoErrors: a 500 has no session errors either,
            // and that is precisely how an undefined-key crash sat here looking green.
            $this->post('/sheq/settings/ppe/stock', [
                'ppe_item_id' => $itemId, 'size' => '9', 'quantity_on_hand' => $count,
            ])->assertRedirect();
        }

        $rows = DB::table('sheq_ppe_stock')->where('ppe_item_id', $itemId)->where('size', '9')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows->first()->quantity_on_hand);
    }

    public function test_stock_cannot_be_set_for_another_companys_item(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'PPE Other Co',
            'slug' => 'ppe-other-'.Str::random(6), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $theirItem = DB::table('sheq_ppe_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $other, 'code' => 'X-1',
            'name' => 'Their gloves', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post('/sheq/settings/ppe/stock', [
            'ppe_item_id' => $theirItem, 'quantity_on_hand' => 5,
        ])->assertSessionHasErrors('ppe_item_id');
    }

    // ── Access ───────────────────────────────────────────────────────────────────────────────

    public function test_configuring_needs_the_configure_entitlement(): void
    {
        // The page is readable by anyone who can reach SHEQ; changing it is not.
        $this->actingAs($this->makeUser([]));

        $this->post('/sheq/settings/ppe/items', ['code' => 'X-9', 'name' => 'Nope'])
            ->assertForbidden();
    }

    // ── The wiring the whole change is about ────────────────────────────────────────────────

    public function test_the_register_reads_the_catalogue_rather_than_managing_it(): void
    {
        /*
         * The point of the move. The register may no longer carry the forms that configure it —
         * two ways to edit the same configuration is how they drift — and it has to send somebody
         * to where the forms now live, or an empty catalogue is a dead end again.
         */
        $register = file_get_contents(base_path('resources/js/Modules/Sheq/Registers/Ppe.vue'));

        $this->assertStringNotContainsString("post('/sheq/ppe/items'", $register,
            'The register still writes to the catalogue.');
        $this->assertStringNotContainsString("post('/sheq/ppe/stock'", $register,
            'The register still writes stock levels.');
        $this->assertStringContainsString('/sheq/settings/ppe', $register,
            'The register does not link to where the catalogue is now managed.');
    }

    public function test_the_old_configuration_endpoints_are_gone(): void
    {
        /*
         * Not merely unused — removed. A second, unreachable way to write the same configuration is
         * how two code paths drift apart: one gets the validation and the size rule, the other keeps
         * whatever it had on the day the UI stopped calling it.
         */
        $routes = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->all();

        foreach (['sheq/ppe/items', 'sheq/ppe/stock'] as $gone) {
            $this->assertNotContains($gone, $routes, "{$gone} is still routable.");
        }

        // The register keeps exactly what it needs: showing the page, and issuing.
        $this->assertContains('sheq/ppe', $routes);
        $this->assertContains('sheq/ppe/issue', $routes);
    }

    public function test_the_catalogue_page_loads_and_carries_what_it_needs(): void
    {
        $this->makeItem();

        $this->get('/sheq/settings/ppe')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Sheq/Settings/PpeCatalogue')
                ->has('items')
                ->has('stock')
                ->has('sites')
                ->has('categories')
                ->where('canManage', true));
    }
}
