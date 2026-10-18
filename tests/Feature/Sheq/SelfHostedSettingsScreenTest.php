<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The screen the self-hosted dead end was actually reported on.
 *
 * The trait is unit-tested next door, but that would not have caught this: what the user saw was
 * SHEQ Settings telling a self-hosted administrator to "switch to a company" on an installation
 * that has exactly one and offers no way to switch. This drives the real route so the refusal is
 * asserted where it was visible.
 */
class SelfHostedSettingsScreenTest extends TestCase
{
    use DatabaseTransactions;

    private string $originalEdition;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $this->originalEdition = (string) config('edition.edition', 'saas');
    }

    protected function tearDown(): void
    {
        config(['edition.edition' => $this->originalEdition]);
        app(CurrentCompany::class)->clear();

        parent::tearDown();
    }

    /** A platform admin: the account that has no company of its own, and so hit the dead end. */
    private function platformAdmin(): User
    {
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'selfhosted-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => null,
            'is_platform_admin' => true,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    private function skipUnlessSingleCompany(): void
    {
        if (DB::table('companies')->count() !== 1) {
            $this->markTestSkipped('This database does not hold exactly one company.');
        }
    }

    public function test_self_hosted_settings_screen_resolves_the_sole_company(): void
    {
        $this->skipUnlessSingleCompany();
        config(['edition.edition' => 'self_hosted']);

        $this->actingAs($this->platformAdmin())
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Sheq/Settings/Index')
                ->where('noCompany', false));
    }

    public function test_saas_still_asks_the_admin_to_pick_a_company(): void
    {
        config(['edition.edition' => 'saas']);

        $this->actingAs($this->platformAdmin())
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('noCompany', true));
    }

    public function test_the_edition_reaches_the_page_so_the_notice_can_word_itself(): void
    {
        config(['edition.edition' => 'self_hosted']);

        $this->actingAs($this->platformAdmin())
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('edition', 'self_hosted'));
    }
}
