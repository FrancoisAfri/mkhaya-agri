<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Security\SiteEntitlement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Per-user site entitlement (SEC-2, TC-SEC-02).
 *
 * This was the one requirement in the whole specification that was recorded as uncovered, and the
 * reason it stayed uncovered is the shape of the failure rather than the difficulty of the fix.
 *
 * The derivation already existed and was already used twice — the document library's audience
 * filter and the sync pull both work out a user's sites through `company_unit_user_pivots` joined
 * to `sheq_sites.company_unit_id`. Both read "no unit assignment" as "no sites", which is right
 * for "which site-specific documents apply to you" and catastrophic for "which sites may you
 * open": on this database not one user has a unit assignment, so an access control reading it the
 * same way would lock every person out of every screen while reporting success.
 *
 * So `null` means unrestricted and `[]` means nothing, those are different answers, and the tests
 * below are mostly about keeping them different. The hardest one to get right is
 * `test_a_user_with_no_unit_assignment_is_unrestricted`, which looks like a hole in the control
 * and is the only thing that makes it shippable.
 */
class SiteEntitlementTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $unitId;

    private int $mySiteId;

    private int $otherSiteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->unitId = (int) DB::table('company_units')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Shaft 4 unit',
            'type' => 'department', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->mySiteId = $this->site('Shaft 4', $this->unitId);
        $this->otherSiteId = $this->site('Shaft 9', null);
    }

    private function entitlement(): SiteEntitlement
    {
        // A fresh instance each time: the service caches per request on purpose, and a cached
        // answer from before a setting changed would make these tests agree with themselves
        // rather than with the database.
        return new SiteEntitlement;
    }

    // ── The default: nothing changes ───────────────────────────────────────────────────────────

    public function test_the_restriction_is_off_until_a_host_switches_it_on(): void
    {
        /*
         * Shipping this on would re-segregate every existing installation without being asked. A
         * unit assignment is an HR act — it is the org chart, maintained for payroll reasons —
         * so enforcing on it globally would restrict every user who happens to sit in a unit,
         * with no warning and no way for them to tell why a register had emptied.
         */
        $user = $this->userIn($this->unitId);

        $this->assertNull($this->entitlement()->sitesFor($this->companyId, $user),
            'The restriction is on by default, so every existing customer is re-segregated by an '
            .'upgrade they did not ask for.');
        $this->assertTrue($this->entitlement()
            ->mayAccess($this->companyId, $user, $this->otherSiteId));
    }

    public function test_switching_it_on_restricts_a_user_to_their_own_sites(): void
    {
        $this->restrict();
        $user = $this->userIn($this->unitId);

        $sites = $this->entitlement()->sitesFor($this->companyId, $user);

        $this->assertSame([$this->mySiteId], $sites);
        $this->assertTrue($this->entitlement()
            ->mayAccess($this->companyId, $user, $this->mySiteId));
        $this->assertFalse($this->entitlement()
            ->mayAccess($this->companyId, $user, $this->otherSiteId),
            'TC-SEC-02: a user assigned to one shaft can open another one.');
    }

    // ── The line that makes it shippable ──────────────────────────────────────────────────────

    public function test_a_user_with_no_unit_assignment_is_unrestricted(): void
    {
        /*
         * This looks like a hole and is the single most important behaviour in the class.
         *
         * Nothing has said which sites are theirs, so there is no restriction to apply. Reading
         * it as "no sites" would lock out every user on an installation where nobody maintains
         * the unit tree — which on this database is all of them — and a register that silently
         * empties is read as "the site is clear" rather than as "you may not look".
         */
        $this->restrict();
        $user = $this->userIn(null);

        $this->assertNull($this->entitlement()->sitesFor($this->companyId, $user),
            'A user with no unit assignment is restricted to nothing, so every register they '
            .'open is empty and reads as a site with no permits, no incidents and nobody on it.');
        $this->assertTrue($this->entitlement()
            ->mayAccess($this->companyId, $user, $this->otherSiteId));
    }

    public function test_an_empty_site_list_and_no_restriction_are_different_answers(): void
    {
        /*
         * The distinction the nullable return type exists for. A user assigned to a unit that has
         * no sites IS restricted and has nothing; a user with no assignment is not restricted at
         * all. Conflating them in either direction is a different bug.
         */
        $this->restrict();

        $emptyUnit = (int) DB::table('company_units')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Head office',
            'type' => 'department', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $assigned = $this->userIn($emptyUnit);

        $this->assertSame([], $this->entitlement()->sitesFor($this->companyId, $assigned),
            'A user assigned to a unit with no sites reads as unrestricted.');
        $this->assertTrue($this->entitlement()->isRestricted($this->companyId, $assigned));
        $this->assertFalse($this->entitlement()
            ->mayAccess($this->companyId, $assigned, $this->mySiteId));
    }

    // ── The escape hatch ──────────────────────────────────────────────────────────────────────

    public function test_a_group_manager_sees_the_whole_estate(): void
    {
        /*
         * A group SHEQ manager sits in head office and must still see every shaft. Without this
         * the control makes the role impossible, and the first thing a frustrated administrator
         * does is turn the whole setting off.
         */
        $this->restrict();

        foreach (['sheq.all_sites', 'sheq.configure'] as $permission) {
            $user = $this->userIn($this->unitId, [$permission]);

            $this->assertNull($this->entitlement()->sitesFor($this->companyId, $user),
                "A user with {$permission} is restricted to their own unit's sites.");
        }
    }

    // ── What a restricted user gets when they ask for nothing ─────────────────────────────────

    public function test_a_user_with_one_site_gets_theirs_without_naming_it(): void
    {
        /*
         * Somebody assigned to one shaft opening the permit register means "my shaft". Refusing
         * them for not naming it is friction that teaches them to ask for `sheq.all_sites`,
         * which is how a control gets removed.
         */
        $this->restrict();
        $user = $this->userIn($this->unitId);

        $this->assertSame($this->mySiteId, $this->entitlement()
            ->resolveRequestedSite($this->companyId, $user, null));
    }

    public function test_a_user_with_several_sites_asking_for_none_gets_none(): void
    {
        // The caller then scopes the query instead, which is the right behaviour: they are
        // entitled to both and the register shows both.
        $this->restrict();

        $second = $this->site('Shaft 5', $this->unitId);
        $user = $this->userIn($this->unitId);

        $this->assertNull($this->entitlement()
            ->resolveRequestedSite($this->companyId, $user, null));

        $sites = $this->entitlement()->sitesFor($this->companyId, $user);

        $this->assertContains($this->mySiteId, $sites);
        $this->assertContains($second, $sites);
    }

    public function test_the_group_view_is_refused_to_a_restricted_user(): void
    {
        /*
         * "All sites" is precisely the thing a restricted user is not entitled to. Letting the
         * group view through because it names no site in particular would make the whole control
         * bypassable by deleting a query parameter.
         */
        $this->restrict();
        $user = $this->userIn($this->unitId);

        $this->assertFalse($this->entitlement()->mayAccess($this->companyId, $user, null));
    }

    // ── The refusal ───────────────────────────────────────────────────────────────────────────

    public function test_asking_for_another_sites_register_is_refused_not_emptied(): void
    {
        /*
         * 403, not a silent empty list. A register that quietly returns nothing teaches somebody
         * that the site is clear, and they will act on "no open permits here" and be wrong —
         * which is a worse outcome than being told they may not look.
         */
        $this->restrict();
        $user = $this->userIn($this->unitId);

        try {
            $this->entitlement()->assertMayAccess($this->companyId, $user, $this->otherSiteId);

            $this->fail('A user opened a site that is not theirs.');
        } catch (AccessDeniedHttpException $e) {
            $this->assertStringContainsString('SITE_NOT_PERMITTED', $e->getMessage());
            // And it says what to do, because a refusal with no route forward becomes a support
            // ticket that ends in the setting being switched off.
            $this->assertStringContainsString('sheq.all_sites', $e->getMessage());
        }
    }

    public function test_a_user_with_no_sites_is_told_so_rather_than_shown_an_empty_screen(): void
    {
        $this->restrict();

        $emptyUnit = (int) DB::table('company_units')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Head office',
            'type' => 'department', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->entitlement()->assertMayAccess($this->companyId,
                $this->userIn($emptyUnit), $this->mySiteId);

            $this->fail('A user with no sites opened one.');
        } catch (AccessDeniedHttpException $e) {
            $this->assertStringContainsString('no site on this system', $e->getMessage());
        }
    }

    // ── Query scoping ─────────────────────────────────────────────────────────────────────────

    public function test_a_query_is_narrowed_to_the_users_sites(): void
    {
        $this->restrict();
        $user = $this->userIn($this->unitId);

        $this->permit($this->mySiteId, 'PTW-MINE');
        $this->permit($this->otherSiteId, 'PTW-THEIRS');

        $references = $this->entitlement()->scope(
            DB::table('sheq_permits')->where('company_id', $this->companyId),
            $this->companyId, $user,
        )->pluck('reference')->all();

        $this->assertContains('PTW-MINE', $references);
        $this->assertNotContains('PTW-THEIRS', $references,
            'A register showing several sites at once is not scoped, so the control only covers '
            .'the single-site view and the group view leaks everything.');
    }

    public function test_a_company_wide_row_is_kept(): void
    {
        /*
         * A statutory obligation or a group policy belongs to everybody. Scoping those out hides
         * records a restricted user is entitled to, and is the commonest way a site filter turns
         * into a bug report.
         */
        $this->restrict();
        $user = $this->userIn($this->unitId);

        $this->permit(null, 'PTW-GROUP');
        $this->permit($this->mySiteId, 'PTW-MINE');

        $references = $this->entitlement()->scope(
            DB::table('sheq_permits')->where('company_id', $this->companyId),
            $this->companyId, $user,
        )->pluck('reference')->all();

        $this->assertContains('PTW-GROUP', $references);
        $this->assertContains('PTW-MINE', $references);
    }

    public function test_a_restricted_user_with_no_sites_sees_nothing_rather_than_everything(): void
    {
        /*
         * The one place where failing closed IS right: by here we know the user is restricted and
         * simply has no sites, so an unscoped query would hand them the whole estate.
         */
        $this->restrict();

        $emptyUnit = (int) DB::table('company_units')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Head office',
            'type' => 'department', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->permit($this->mySiteId, 'PTW-MINE');

        $this->assertCount(0, $this->entitlement()->scope(
            DB::table('sheq_permits')->where('company_id', $this->companyId),
            $this->companyId, $this->userIn($emptyUnit),
        )->get(),
            'A restricted user with no sites got an unscoped query, which is the whole estate.');
    }

    public function test_an_unrestricted_user_query_is_not_touched(): void
    {
        $user = $this->userIn(null);

        $this->permit($this->mySiteId, 'PTW-MINE');
        $this->permit($this->otherSiteId, 'PTW-THEIRS');

        $this->assertCount(2, $this->entitlement()->scope(
            DB::table('sheq_permits')->where('company_id', $this->companyId),
            $this->companyId, $user,
        )->get());
    }

    // ── Through the routes ────────────────────────────────────────────────────────────────────

    public function test_the_permit_board_refuses_another_sites_id(): void
    {
        $this->restrict();
        $user = $this->userIn($this->unitId);

        // `/sheq/permits/board` is the filtered view; `/sheq/permits` is the register, which
        // takes no site parameter and is scoped on the query instead.
        $this->actingAs($user)
            ->get('/sheq/permits/board?site_id='.$this->otherSiteId)
            ->assertForbidden();
    }

    public function test_the_permit_board_allows_the_users_own_site(): void
    {
        $this->restrict();
        $user = $this->userIn($this->unitId);

        $this->actingAs($user)
            ->get('/sheq/permits/board?site_id='.$this->mySiteId)
            ->assertOk();
    }

    public function test_a_register_with_no_site_filter_is_scoped_on_the_query(): void
    {
        /*
         * The broader half of the same hole, and the quieter one. `/sheq/permits` takes no site
         * parameter at all — there was nothing to tamper with, because the query simply listed
         * every site in the tenant. A user entitled to one shaft saw the whole estate and nothing
         * about the screen suggested otherwise.
         */
        $this->restrict();
        $user = $this->userIn($this->unitId);

        $this->permit($this->mySiteId, 'PTW-MINE');
        $this->permit($this->otherSiteId, 'PTW-THEIRS');

        $page = $this->actingAs($user)->get('/sheq/permits')
            ->assertOk()->viewData('page')['props'];

        $references = array_column($page['rows'] ?? [], 'reference');

        $this->assertContains('PTW-MINE', $references);
        $this->assertNotContains('PTW-THEIRS', $references,
            'The permit register shows every site in the tenant to a user entitled to one.');
    }

    public function test_every_register_that_takes_a_site_id_goes_through_the_entitlement(): void
    {
        /*
         * Mechanical, against the source. Twelve controllers read a site from the request, and
         * one of them reverting to `$request->integer('site_id')` would reopen the hole in that
         * register alone — which no behavioural test would catch unless somebody thought to add
         * one for that exact screen.
         */
        $controllers = glob(app_path('Modules/Operations/Sheq/Http/Controllers/*.php'));

        $offenders = [];

        foreach ($controllers as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, "integer('site_id')")) {
                continue;
            }

            // The trait's own helper is the one place allowed to read it raw.
            if (str_contains($source, 'ScopesToPermittedSites')) {
                continue;
            }

            $offenders[] = basename($file);
        }

        $this->assertSame([], $offenders,
            'These controllers read a site id without asking whether the user may open it: '
            .implode(', ', $offenders));
    }

    public function test_the_trait_is_the_only_place_that_reads_a_site_id_raw(): void
    {
        $trait = file_get_contents(
            app_path('Modules/Operations/Sheq/Support/ScopesToPermittedSites.php'));

        $this->assertStringContainsString("integer('site_id')", $trait,
            'The trait no longer reads the parameter, so the controllers are reading it '
            .'somewhere else.');
    }

    // ── Tenancy ───────────────────────────────────────────────────────────────────────────────

    public function test_another_tenants_setting_does_not_restrict_this_one(): void
    {
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        DB::table('sheq_settings')->updateOrInsert(['company_id' => $other],
            ['restrict_users_to_sites' => true, 'updated_at' => now()]);

        $this->assertNull($this->entitlement()
            ->sitesFor($this->companyId, $this->userIn($this->unitId)),
            'Another tenant switching the restriction on restricted this one.');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function restrict(): void
    {
        DB::table('sheq_settings')->updateOrInsert(['company_id' => $this->companyId],
            ['restrict_users_to_sites' => true, 'updated_at' => now()]);
    }

    private function site(string $name, ?int $unitId): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::upper(Str::random(5)), 'name' => $name,
            'site_type' => 'MINE', 'timezone' => 'Africa/Johannesburg',
            'company_unit_id' => $unitId, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function permit(?int $siteId, string $reference): void
    {
        DB::table('sheq_permits')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => $reference, 'permit_type' => 'HOT_WORK',
            'site_id' => $siteId, 'work_description' => 'Welding', 'status' => 'ACTIVE',
            'valid_from' => now()->subHour(), 'valid_to' => now()->addHours(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function userIn(?int $unitId, array $permissions = []): User
    {
        $userId = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'ent-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($unitId !== null) {
            // `company_unit_user_pivots.company_id` holds the UNIT id, not the company — the
            // naming trap this schema has carried for a long time.
            DB::table('company_unit_user_pivots')->insert([
                'user_id' => $userId, 'company_id' => $unitId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }
}
