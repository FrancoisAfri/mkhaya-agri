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
 * Every control on a supplier screen is handed the identifier its route actually wants.
 *
 * Moving suppliers from a row id to a uuid broke twenty-two controls across this module, and each
 * one failed in a way nobody would notice from the code: Ziggy builds the URL happily, the server
 * either 404s or — before the routes were constrained — asks Postgres for `uuid = 714` and raises a
 * 500. Activating a supplier, refusing one, rotating its portal token, opening a case, running a
 * check, sweeping one from the monitoring inbox: all of them, silently, until somebody pressed the
 * button.
 *
 * Two things are held here. A screen must pass a uuid to any route that binds `{supplier}`, and a
 * page that builds such a route must be given a uuid in its payload — the passport constructed its
 * own supplier array and omitted it, which is the same failure one layer earlier.
 */
class SupplierScreenWiringTest extends TestCase
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

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'wire+'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'uuid' => (string) Str::uuid(), 'company_name' => 'Wired '.Str::random(5),
            'payment_terms' => '30 days', 'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true, 'health_safety_compliance' => true,
            'is_active' => 1, 'is_preferred' => false,
        ]);
    }

    /** Route names whose URI contains `{supplier}`, so whose first argument must be a uuid. */
    private function supplierBoundRoutes(): array
    {
        $names = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() && str_contains($route->uri(), '{supplier}')) {
                $names[$route->getName()] = true;
            }
        }

        return $names;
    }

    /** @return list<string> every .vue under the module */
    private function screens(): array
    {
        $base = resource_path('js/Modules/SupplierManagement');
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_no_screen_hands_a_row_id_to_a_route_that_wants_a_uuid(): void
    {
        $bound = $this->supplierBoundRoutes();
        $offenders = [];
        $checked = 0;

        foreach ($this->screens() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all("/route\('([a-z0-9.\-]+)',\s*([^)]+)\)/", $source, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                [, $name, $args] = $match;

                if (! isset($bound[$name])) {
                    continue;
                }

                $checked++;
                $first = trim(explode(',', ltrim(trim($args), '['))[0]);

                /*
                 * `.uuid` or a variable whose name ends in `uuid` — the panels receive the value as
                 * a prop, and a prop called `supplierId` holding a uuid is exactly the trap this
                 * module just fell into, so the name has to carry it too.
                 */
                if (! str_ends_with($first, '.uuid') && ! str_ends_with(strtolower($first), 'uuid')) {
                    $offenders[] = str_replace(resource_path('js/Modules/SupplierManagement').'/', '', $path)
                        .": route('{$name}', {$first})";
                }
            }
        }

        $this->assertGreaterThan(20, $checked, 'the sweep found almost no supplier-bound calls — it is looking in the wrong place');
        $this->assertSame([], array_values(array_unique($offenders)),
            "These controls pass a row id to a route keyed on the uuid. Each one 404s the moment "
            ."somebody presses it:\n  ".implode("\n  ", array_unique($offenders)));
    }

    public function test_every_page_that_addresses_a_supplier_is_given_its_uuid(): void
    {
        /*
         * The same failure one layer earlier: the passport built its own supplier array, left the
         * uuid out, and three controls on it threw — opening the record, opening a case, moving one.
         * A hand-built payload has to say what the screen needs.
         */
        $supplier = $this->supplier();
        $user = $this->user();

        foreach (['suppliers.show', 'suppliers.passport'] as $name) {
            $this->actingAs($user)
                ->get(route($name, $supplier))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where(
                    'supplier.uuid',
                    $supplier->uuid,
                ));
        }
    }

    public function test_the_passport_can_run_a_check_from_the_tile_that_reports_it(): void
    {
        // A tile reading "never checked, can be run" with no way to run it sends somebody to another
        // screen to do what they were just told was possible.
        $this->actingAs($this->user())
            ->get(route('suppliers.passport', $this->supplier()))
            ->assertInertia(fn ($page) => $page->where('tiles', fn ($tiles) => collect($tiles)
                ->every(fn ($tile) => array_key_exists('has_source', $tile) && array_key_exists('key', $tile))));
    }

    public function test_the_passport_tiles_carry_a_translation_key(): void
    {
        // Eight hardcoded English words down the middle of a page read in twelve languages.
        $this->actingAs($this->user())
            ->get(route('suppliers.passport', $this->supplier()))
            ->assertInertia(fn ($page) => $page->where('tiles', fn ($tiles) => collect($tiles)
                ->every(fn ($tile) => filled($tile['label_key'] ?? null))));
    }
}
