<?php

namespace Tests\Feature\SupplierManagement;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * How a supplier is addressed, and what travels with it.
 *
 * Two things were wrong, and the second is the serious one.
 *
 * Suppliers were addressed by row id. `/suppliers/1` tells anybody who sees it how many suppliers a
 * tenant has and invites them to try `/suppliers/2`; the authorisation check behind each page is
 * what actually stops them, but a sequential id makes the guess cheap and makes an accidental leak —
 * a pasted link, a screenshot, a referrer header — carry information it did not need to.
 *
 * And every payload carried two credentials. `portal_token` IS the supplier portal's authentication:
 * the portal has no login, the token in the link is the whole of it. The directory renders every
 * supplier, so any internal user who could open that page was handed a working key to every
 * supplier's portal — where banking details are changed. `tcs_pin` is cast `encrypted`, which
 * protects it at rest and not at all on the way out, because serialising the model decrypts it.
 */
class SupplierAddressingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);

        // Every supplier page sits behind `module.active:supplier_management`, which redirects when
        // the module row is absent — so without this the status assertions below would be measuring
        // the middleware rather than the route.
        if (! DB::table('security_modules')->where('code_name', 'supplier_management')->where('active', 1)->exists()) {
            $categoryId = DB::table('security_categories')->value('id')
                ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('security_modules')->updateOrInsert(
                ['code_name' => 'supplier_management'],
                ['uuid' => (string) Str::uuid(), 'name' => 'Supplier Management', 'path' => 'suppliers',
                    'category_id' => $categoryId, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        Cache::flush();
    }

    private function supplier(): Supplier
    {
        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(),
            'company_name' => 'Addressed '.Str::random(5),
            'payment_terms' => '30 days',
            'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true,
            'health_safety_compliance' => true,
            'is_active' => 1,
            'is_preferred' => false,
        ]);

        $supplier->forceFill([
            'portal_token' => 'tok-'.Str::random(40),
            'tcs_pin' => '1234567890',
        ])->saveQuietly();

        return $supplier->refresh();
    }

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'addr+'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
        ]);
    }

    // ── Addressing ───────────────────────────────────────────────────────────

    public function test_a_supplier_url_carries_its_uuid_not_its_row_id(): void
    {
        $supplier = $this->supplier();

        $url = route('suppliers.show', $supplier);

        $this->assertStringContainsString($supplier->uuid, $url);
        $this->assertStringNotContainsString("/suppliers/{$supplier->id}", $url);
    }

    public function test_the_row_id_no_longer_opens_a_supplier(): void
    {
        /*
         * The point of the change: a number in the address bar must stop resolving, or the old
         * enumerable URLs keep working alongside the new ones and nothing has actually been closed.
         */
        $supplier = $this->supplier();

        /*
         * Asserted on the component, not the status: this application answers an unmatched URL with
         * a 404 *page* served at HTTP 200 (`Route::fallback` in web.php renders Pages/Found/404).
         * That soft 404 is a separate problem and an app-wide one; what matters here is that a row
         * id no longer reaches a supplier.
         */
        $this->actingAs($this->user())
            ->get('/suppliers/'.$supplier->id)
            ->assertInertia(fn ($page) => $page->component('Pages/Found/404'));
    }

    public function test_the_uuid_opens_it(): void
    {
        $supplier = $this->supplier();

        $this->actingAs($this->user())
            ->get(route('suppliers.show', $supplier))
            ->assertOk();
    }

    public function test_the_passport_and_scorecard_are_addressed_the_same_way(): void
    {
        // Three screens for one supplier; a mix of id and uuid would be worse than either alone.
        $supplier = $this->supplier();

        foreach (['suppliers.passport', 'suppliers.scorecard'] as $name) {
            $this->assertStringContainsString($supplier->uuid, route($name, $supplier));
        }
    }

    public function test_no_supplier_route_still_demands_a_number(): void
    {
        /*
         * `whereNumber('supplier')` appeared on 49 routes. One left behind is a 404 on a page that
         * looks correct everywhere else, which is the hardest kind of break to attribute.
         */
        $numeric = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_contains($route->uri(), '{supplier}')) {
                continue;
            }

            if (($route->wheres['supplier'] ?? null) === '[0-9]+') {
                $numeric[] = $route->uri();
            }
        }

        $this->assertSame([], $numeric,
            "These routes still require a numeric supplier and will 404 on a uuid:\n  "
            . implode("\n  ", $numeric));
    }

    // ── What travels with it ─────────────────────────────────────────────────

    public function test_the_portal_token_never_reaches_the_browser(): void
    {
        /*
         * The portal has no login — this token is the whole of the authentication, and it opens the
         * screen where a supplier changes its banking details.
         */
        $supplier = $this->supplier();

        $this->assertArrayNotHasKey('portal_token', $supplier->toArray());

        $this->actingAs($this->user())
            ->get(route('suppliers.index'))
            ->assertDontSee($supplier->portal_token, false);
    }

    public function test_the_tax_pin_never_reaches_the_browser(): void
    {
        // Encrypted at rest is no protection on the way out: serialising the model decrypts it.
        $supplier = $this->supplier();

        $this->assertArrayNotHasKey('tcs_pin', $supplier->toArray());
        $this->assertSame('1234567890', $supplier->tcs_pin, 'the server must still be able to read it');
    }

    public function test_hiding_them_does_not_break_reading_them_server_side(): void
    {
        // Hiding affects serialisation only. The portal link and the tax check still need the values.
        $supplier = $this->supplier();

        $this->assertNotEmpty($supplier->portal_token);
        $this->assertStringContainsString($supplier->portal_token, route('supplier.portal.show', $supplier->portal_token));
    }
}
