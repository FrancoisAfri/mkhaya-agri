<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Support\SheqHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every screen the hubs advertise must actually open.
 *
 * This exists because of a failure that no other test in the module could see. `EnforceCrudPermissions`
 * derives a permission name from the route name — `sheq.forms.index`, `sheq.forms.view` — and SHEQ
 * does not use names of that shape. It declares its own permission set and checks it explicitly in
 * each controller. So the derived names matched nothing, and **every screen in the module returned
 * 403 to every user except a platform administrator**, who bypasses that middleware entirely.
 *
 * Nothing caught it. The service tests call services directly, the API tests exercise a different
 * route group, and a developer testing by hand is usually a platform admin — the one account for
 * which the module worked perfectly. Project Management and Procurement Governance each shipped the
 * same bug before SHEQ did, which is the clearest possible argument for a test that opens the doors.
 *
 * The user here is deliberately ordinary: a real company, module access, no roles, not an admin.
 */
class ScreenReachabilityTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'screens-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);

        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');

        DB::table('security_module_access')->insert([
            'module_id' => $module->id, 'user_id' => $userId,
            // ADMIN, so a refusal cannot be explained away as "they were only a reader".
            'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> every path advertised by a hub card, plus the hub landings */
    private function advertisedPaths(): array
    {
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

        return array_values(array_unique(array_filter($paths)));
    }

    public function test_every_screen_the_hubs_advertise_opens(): void
    {
        $refused = [];

        foreach ($this->advertisedPaths() as $path) {
            $status = $this->actingAs($this->user)->get('/'.ltrim($path, '/'))->status();

            // 200 is the goal. A redirect is acceptable for a screen that bounces somewhere
            // sensible. 403 is not: it means a card is advertised in the navigation and then
            // refuses the person who clicks it, which is how the whole module was broken.
            if ($status === 403 || $status >= 500) {
                $refused[] = "{$path} → {$status}";
            }
        }

        // A sweep that stops finding screens stops proving anything. The count is asserted so a
        // hub that quietly loses its cards fails here rather than passing as a formality.
        $this->assertGreaterThan(30, count($this->advertisedPaths()),
            'The hubs advertise almost nothing, so this proved very little.');

        $this->assertSame([], $refused,
            'These screens are advertised on a hub card but refuse an ordinary user with module '
            ."access:\n  ".implode("\n  ", $refused));
    }

    public function test_an_ordinary_user_is_not_secretly_a_platform_admin(): void
    {
        // The guard on the guard. If the fixture user were an admin, the test above would pass
        // against a module that is completely unusable for everybody else — which is precisely
        // the state SHEQ shipped in until this file existed.
        $this->assertFalse((bool) ($this->user->is_platform_admin ?? false));
        $this->assertSame(0, $this->user->roles()->count());
    }
}
