<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Http\Controllers\ApprovalController;
use App\Modules\Operations\Sheq\Services\SheqReportService;
use App\Modules\Operations\Sheq\Support\SheqHubs;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SHEQ with nothing else installed.
 *
 * The module's own ModuleBridge advertises HR as optional — "Employees, line managers, medicals" —
 * so a customer who buys SHEQ alone is a supported configuration, not a hypothetical. What makes
 * that easy to get wrong is that a missing neighbour is not a degraded feature: an unguarded join
 * against `hr_people` throws a fatal, and the screen does not load at all.
 *
 * The table is genuinely dropped rather than mocked. Postgres DDL is transactional, so
 * DatabaseTransactions rolls it back — and a check that only asserted a `hasTable()` call exists
 * would pass just as happily against code that then joins the table anyway.
 */
class StandaloneTest extends TestCase
{
    use DatabaseTransactions;

    /** What ModuleBridge advertises as optional and a customer can genuinely buy without. */
    private const OPTIONAL_NEIGHBOURS = ['lms_enrollments', 'suppliers', 'assets', 'fleet_vehicles'];

    private int $companyId;

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
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** Run $body on an install where HR was never present. */
    /**
     * Run $body on an install where SHEQ's optional neighbours were never present.
     *
     * `hr_people` is deliberately **not** in this list, and that distinction cost me a wrong
     * conclusion. SHEQ's own ModuleBridge lists HR as optional, which is true of its *features* —
     * line managers, medicals — but not of the table: `User::getNameAttribute()` reads it to
     * resolve a display name, and that accessor runs in the global Inertia props on every
     * authenticated request. Remove it and no page in the platform renders, SHEQ or otherwise.
     * A test that removed it was testing a configuration that cannot exist.
     *
     * These four are genuinely optional: a customer can buy SHEQ without Learning Management,
     * Assets, Procurement or Fleet, and the module has to degrade rather than fail.
     */
    private function withoutNeighbours(callable $body): void
    {
        foreach (self::OPTIONAL_NEIGHBOURS as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }

        foreach (self::OPTIONAL_NEIGHBOURS as $table) {
            $this->assertFalse(DB::getSchemaBuilder()->hasTable($table),
                "The fixture did not remove {$table}, so this test proves nothing.");
        }

        $body();
    }

    public function test_the_approver_picker_still_works_standalone(): void
    {
        /*
         * This screen joins hr_people to show names instead of email addresses. Without HR there
         * are no names — but "no names" must mean addresses, not a 500.
         */
        $userId = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'standalone-'.Str::random(6).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::withoutGlobalScopes()->findOrFail($userId));

        $this->withoutNeighbours(function () use ($userId) {
            $method = new ReflectionMethod(ApprovalController::class, 'selectableUsers');
            $method->setAccessible(true);

            $list = $method->invoke(app(ApprovalController::class), $this->companyId);
            $mine = array_values(array_filter($list, fn ($r) => (int) $r['id'] === $userId));

            $this->assertNotEmpty($mine, 'The picker returned nobody on a SHEQ-only install.');
        });
    }

    public function test_every_report_still_runs_standalone(): void
    {
        /*
         * Four of the twenty-four reports name a person — PPE issued, replacements due, competency
         * expiry, medicals. Those are exactly the ones that reach into HR, and a compliance report
         * that 500s is worse than one that shows a blank column.
         */
        $this->withoutNeighbours(function () {
            $service = app(SheqReportService::class);
            $broken = [];

            foreach (array_keys($service->catalogue()) as $key) {
                try {
                    $service->run($key, $this->companyId, Carbon::now()->subYear(), Carbon::now(), null);
                } catch (\Throwable $e) {
                    $broken[] = $key.': '.$e->getMessage();
                }
            }

            $this->assertSame([], $broken,
                "These reports need an optional module installed:\n".implode("\n", $broken));
        });
    }

    public function test_the_ppe_catalogue_has_no_cross_module_dependency(): void
    {
        // Catalogue and stock are entirely SHEQ's own — worth pinning so a future join does not
        // quietly acquire the dependency.
        $this->withoutNeighbours(function () {
            $source = file_get_contents(base_path(
                'app/Modules/Operations/Sheq/Http/Controllers/PpeCatalogueController.php'));

            $this->assertStringNotContainsString('hr_people', $source);
        });
    }

    public function test_every_sheq_screen_still_opens_standalone(): void
    {
        /*
         * The proof, rather than an argument. Sixteen queries in this module joined `hr_people`
         * unguarded, and a missing neighbour is a fatal there, not a blank column — so the only
         * honest check is to remove HR and open every screen the navigation advertises.
         *
         * Modelled on ScreenReachabilityTest, which exists because the whole module once 403'd and
         * no unit test could see it.
         */
        $user = $this->moduleUser();
        $this->actingAs($user);

        $paths = [];

        foreach (SheqHubs::direct() as $entry) {
            $paths[] = $entry['path'] ?? null;
        }

        foreach (SheqHubs::hubs() as $hub) {
            $paths[] = $hub['path'] ?? null;

            foreach ($hub['cards'] ?? [] as $card) {
                $paths[] = $card['path'] ?? null;
            }
        }

        $paths = array_values(array_unique(array_filter($paths)));
        $this->assertNotEmpty($paths);

        $this->withoutNeighbours(function () use ($paths) {
            $broken = [];

            foreach ($paths as $path) {
                /*
                 * Each screen gets its own savepoint.
                 *
                 * Without it the first failure aborts the enclosing Postgres transaction and every
                 * screen after it reports "current transaction is aborted" — which reads as forty
                 * broken screens when it is one broken screen and thirty-nine casualties. That
                 * mistake turns a precise finding into a scary useless one.
                 */
                DB::beginTransaction();

                try {
                    $response = $this->get('/'.ltrim($path, '/'));
                    $status = $response->getStatusCode();
                } catch (\Throwable $e) {
                    $broken[] = $path.': '.mb_substr($e->getMessage(), 0, 90);
                    DB::rollBack();

                    continue;
                }

                if ($status >= 500) {
                    // The message, not just the code: "HTTP 500" on forty-four screens says
                    // nothing about whether it is one shared cause or forty-four separate ones.
                    $why = $response->exception?->getMessage() ?? 'HTTP '.$status;
                    $broken[] = $path.': '.mb_substr($why, 0, 100);
                }

                DB::rollBack();
            }

            $this->assertSame([], $broken,
                "These screens need an optional module installed:\n".implode("\n", $broken));
        });
    }

    private function moduleUser(): User
    {
        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');

        $id = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'standalone-screens-'.Str::random(6).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId,
            'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_module_access')->insert([
            'module_id' => $module->id, 'user_id' => $id, 'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }
}
