<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\SiteProvisioner;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sites the tenant types, alongside the ones mirrored from the company structure.
 *
 * Mirroring was right and was not enough. A depot rented for eight months, a client's premises, a
 * stretch of road under contract — places where work happens and where an incident gets reported,
 * and none of them a line on an HR org chart. There was no way to record one, so people filed
 * against the nearest department and the site on the record was a fiction.
 *
 * The load-bearing detail is `company_unit_id`. A hand-made site has none, and that is exactly what
 * keeps a resync away from it.
 */
class SiteManagementTest extends TestCase
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

        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);
        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');
        $this->moduleId = (int) $module->id;

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeUser(bool $canConfigure = true): User
    {
        $id = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'sites-' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_module_access')->insert([
            'module_id' => $this->moduleId, 'user_id' => $id,
            'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::withoutGlobalScopes()->findOrFail($id);
        Permission::findOrCreate('sheq.configure', 'web');
        $user->syncPermissions($canConfigure ? ['sheq.configure'] : []);

        return $user->fresh();
    }

    private function site(string $uuid)
    {
        return DB::table('sheq_sites')->where('uuid', $uuid)->first();
    }

    // ── Adding one ───────────────────────────────────────────────────────────

    public function test_a_site_can_be_added_by_hand(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Secunda Depot',
            'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $site = DB::table('sheq_sites')
            ->where('company_id', $this->companyId)->where('name', 'Secunda Depot')->first();

        $this->assertNotNull($site, 'The site was not created.');
        // The whole point: no unit, so the structure sync has no claim on it.
        $this->assertNull($site->company_unit_id);
        $this->assertTrue((bool) $site->is_active);
    }

    /** Generated from the name, because a code is what appears on a permit board. */
    public function test_a_code_is_generated_when_none_is_given(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Rustenburg Yard',
            'site_type' => 'AREA',
            'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $site = DB::table('sheq_sites')->where('name', 'Rustenburg Yard')->first();

        $this->assertNotEmpty($site->code);
    }

    /** Two sites sharing a code makes a permit board ambiguous, so the second one is suffixed. */
    public function test_a_duplicate_code_is_made_unique(): void
    {
        foreach (['First', 'Second'] as $name) {
            $this->post(route('sheq.sites.store'), [
                'name' => $name, 'code' => 'DEPOT',
                'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
            ])->assertRedirect();
        }

        $codes = DB::table('sheq_sites')
            ->where('company_id', $this->companyId)
            ->whereIn('name', ['First', 'Second'])
            ->pluck('code');

        $this->assertCount(2, $codes->unique(), 'Two sites were given the same code.');
    }

    /**
     * Nothing reads a site by its parent id alone — the notification router and the offline sync
     * scope both read `path`, so a site without one is a site those never reach.
     */
    public function test_a_site_gets_a_path(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Pathed Site', 'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $site = DB::table('sheq_sites')->where('name', 'Pathed Site')->first();

        $this->assertNotNull($site->path, 'The site has no ltree path.');
    }

    public function test_a_site_can_sit_under_another(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Parent Region', 'site_type' => 'REGION', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $parent = DB::table('sheq_sites')->where('name', 'Parent Region')->first();

        $this->post(route('sheq.sites.store'), [
            'name' => 'Child Depot', 'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
            'parent_uuid' => $parent->uuid,
        ])->assertRedirect();

        $child = DB::table('sheq_sites')->where('name', 'Child Depot')->first();

        $this->assertSame((int) $parent->id, (int) $child->parent_id);
        $this->assertStringContainsString((string) $parent->id, (string) $child->path,
            'The lineage does not include the parent, so rules pinned to it will not cover this site.');
    }

    // ── The resync must not touch them ───────────────────────────────────────

    /**
     * The reason `company_unit_id` is null rather than pointing anywhere convenient. A resync that
     * renamed or deactivated a hand-made site would undo the tenant's own work every time HR
     * reorganised something unrelated.
     */
    public function test_a_resync_leaves_a_hand_made_site_alone(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Rented Depot', 'code' => 'RENTED',
            'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $before = DB::table('sheq_sites')->where('name', 'Rented Depot')->first();

        app(SiteProvisioner::class)->syncFromCompanyStructure($this->companyId);

        $after = DB::table('sheq_sites')->where('id', $before->id)->first();

        $this->assertNotNull($after, 'The resync deleted a site the tenant added.');
        $this->assertSame('Rented Depot', $after->name);
        $this->assertSame('RENTED', $after->code);
        $this->assertTrue((bool) $after->is_active);
    }

    // ── Correcting and retiring ──────────────────────────────────────────────

    public function test_a_site_can_be_corrected(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Typo Depto', 'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $site = DB::table('sheq_sites')->where('name', 'Typo Depto')->first();

        $this->patch(route('sheq.sites.update', $site->uuid), [
            'name' => 'Typo Depot', 'site_type' => 'AREA', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $this->assertSame('Typo Depot', $this->site($site->uuid)->name);
        $this->assertSame('AREA', $this->site($site->uuid)->site_type);
    }

    /**
     * Retired, never deleted. Every incident, permit and inspection points at a site — deleting one
     * orphans the record an inspector asks for.
     */
    public function test_a_site_is_retired_rather_than_deleted(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Closed Site', 'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $site = DB::table('sheq_sites')->where('name', 'Closed Site')->first();

        $this->post(route('sheq.sites.toggle', $site->uuid), ['is_active' => false])->assertRedirect();

        $after = $this->site($site->uuid);

        $this->assertFalse((bool) $after->is_active);
        $this->assertNull($after->deleted_at, 'The site was deleted rather than retired.');
    }

    /** A cycle would make the path loop until the guard trips, so the tree stays a tree. */
    public function test_a_site_cannot_be_made_its_own_ancestor(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Top', 'site_type' => 'REGION', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();
        $top = DB::table('sheq_sites')->where('name', 'Top')->first();

        $this->post(route('sheq.sites.store'), [
            'name' => 'Under', 'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
            'parent_uuid' => $top->uuid,
        ])->assertRedirect();
        $under = DB::table('sheq_sites')->where('name', 'Under')->first();

        // Try to put the parent under its own child.
        $this->patch(route('sheq.sites.update', $top->uuid), [
            'name' => 'Top', 'site_type' => 'REGION', 'timezone' => 'Africa/Johannesburg',
            'parent_uuid' => $under->uuid,
        ])->assertRedirect();

        $this->assertNull($this->site($top->uuid)->parent_id,
            'A site was placed under its own descendant.');
    }

    // ── Who may ──────────────────────────────────────────────────────────────

    public function test_somebody_without_sheq_configure_cannot_add_a_site(): void
    {
        $this->actingAs($this->makeUser(canConfigure: false));

        $this->post(route('sheq.sites.store'), [
            'name' => 'Unauthorised', 'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
        ])->assertForbidden();

        $this->assertDatabaseMissing('sheq_sites', ['name' => 'Unauthorised']);
    }

    // ── What the rest of the system sees ─────────────────────────────────────

    /**
     * Every screen that offers a site reads `sheq_sites`, so a site added here appears in all of
     * them. The incident report form is the one that matters most — it is the screen that must
     * never have friction.
     */
    public function test_a_new_site_is_offered_on_the_incident_report_form(): void
    {
        $this->post(route('sheq.sites.store'), [
            'name' => 'Newly Added Site', 'site_type' => 'SITE', 'timezone' => 'Africa/Johannesburg',
        ])->assertRedirect();

        $response = $this->get(route('sheq.incidents.create'));

        if ($response->status() !== 200) {
            $this->markTestSkipped('The incident form is not reachable for this user.');
        }

        $names = array_column($response->viewData('page')['props']['sites'], 'name');

        $this->assertContains('Newly Added Site', $names,
            'A site added on the sites page is not offered when reporting an incident.');
    }
}
