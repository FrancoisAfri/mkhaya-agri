<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Support\SheqHubs;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * No SHEQ screen may dead-end a self-hosted administrator.
 *
 * ScreenReachabilityTest next door proves the doors open for an ordinary tenant user. This proves
 * the other half: on a single-company installation the administrator has no company of their own
 * and no company to switch to, so any screen that refuses on "no active company" is unreachable
 * forever rather than merely awkward. Settings was the one that got reported; this sweep is here so
 * the next one is caught before a user finds it.
 */
class SelfHostedScreenSweepTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private string $originalEdition;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $this->originalEdition = (string) config('edition.edition', 'saas');

        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('This database does not hold exactly one company.');
        }

        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'sweep-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => null,
            'is_platform_admin' => true,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->admin = User::withoutGlobalScopes()->findOrFail($id);

        Cache::forget('module_active_sheq');
        config(['edition.edition' => 'self_hosted']);
    }

    protected function tearDown(): void
    {
        config(['edition.edition' => $this->originalEdition]);
        app(CurrentCompany::class)->clear();

        parent::tearDown();
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

    public function test_no_screen_refuses_a_self_hosted_administrator(): void
    {
        $deadEnds = [];

        foreach ($this->advertisedPaths() as $path) {
            $response = $this->actingAs($this->admin)->get('/'.ltrim($path, '/'));

            if ($response->status() >= 500) {
                $deadEnds[] = "{$path} → {$response->status()}";

                continue;
            }

            $body = $response->getContent() ?: '';

            // The Inertia page object is embedded HTML-escaped in the data-page attribute, so the
            // escaped form is the one that actually appears.
            if (str_contains($body, '&quot;noCompany&quot;:true') || str_contains($body, '"noCompany":true')) {
                $deadEnds[] = "{$path} → no active company";
            }
        }

        $this->assertSame([], $deadEnds,
            'These SHEQ screens dead-end a self-hosted administrator, who has no company to switch '
            ."to:\n  ".implode("\n  ", $deadEnds));
    }
}
