<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\Support\LandingPage;
use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A project client can log in again.
 *
 * Reported from production: setting the password worked and signing in afterwards did not. Both
 * halves are true. Accepting the invitation signs the new account in and redirects it straight to
 * the portal, so that path never touches the dashboard. The next ordinary login went to the
 * dashboard, EnforceCrudPermissions derived [dashboard, dashboard.view, view] from the route name,
 * and the account held none of them — MemberInviteService creates it with no role and no module
 * access on purpose, because portal access is meant to come from project membership.
 *
 * So the client could reach the portal exactly once, through a single-use link.
 */
class PortalUserLandingTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (int) (DB::table('companies')->min('id')
            ?? Company::create(['name' => 'Landing Test Co'])->id);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'status' => 1,
            'company_id' => $this->companyId,
        ]);
    }

    /** A client or contractor on a live project — what the invitation produces. */
    private function portalMemberFor(User $user, string $role = 'client'): ProjectMember
    {
        // pm_projects.calendar_id is NOT NULL and references a real calendar.
        $calendarId = DB::table('pm_calendars')->value('id') ?? DB::table('pm_calendars')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Standard', 'timezone' => 'Africa/Johannesburg',
            'work_week' => json_encode([1, 2, 3, 4, 5]), 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $projectId = DB::table('pm_projects')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'P' . random_int(1000, 9999), 'name' => 'A project',
            // Every one of these is NOT NULL on pm_projects.
            'lens_code' => 'generic', 'status' => 'active', 'stage' => 'delivery',
            'priority' => 'medium', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            'is_billable' => true, 'timesheet_approval_required' => false,
            'calendar_id' => $calendarId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ProjectMember::create([
            'uuid'       => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'project_id' => $projectId,
            'user_id'    => $user->id,
            'role_key'   => $role,
            'is_active'  => true,
        ]);
    }

    public function test_a_project_client_lands_on_the_portal_rather_than_the_dashboard(): void
    {
        // The bug, stated plainly. This account holds no permission at all, which is what the
        // invitation creates and what the portal is designed around.
        $client = $this->user();
        $this->portalMemberFor($client);

        $this->assertSame(route('project-management.portal'), LandingPage::for($client));
    }

    public function test_a_contractor_lands_on_the_portal_too(): void
    {
        $contractor = $this->user();
        $this->portalMemberFor($contractor, 'contractor');

        $this->assertSame(route('project-management.portal'), LandingPage::for($contractor));
    }

    public function test_staff_still_land_on_the_dashboard(): void
    {
        $staff = $this->user();
        Permission::firstOrCreate(['name' => 'view', 'guard_name' => 'web']);
        $staff->givePermissionTo('view');

        $this->assertSame(route('dashboard'), LandingPage::for($staff->fresh()));
    }

    public function test_a_staff_member_who_is_also_a_client_on_a_project_still_lands_on_the_dashboard(): void
    {
        /*
         * The reason the dashboard is checked first. Internal people do appear as clients on
         * projects, and sending them to a cut-down portal because of it would be a worse bug than
         * the one being fixed — they would lose the whole application.
         */
        $staff = $this->user();
        Permission::firstOrCreate(['name' => 'view', 'guard_name' => 'web']);
        $staff->givePermissionTo('view');
        $this->portalMemberFor($staff);

        $this->assertSame(route('dashboard'), LandingPage::for($staff->fresh()));
    }

    public function test_a_platform_admin_lands_on_the_dashboard(): void
    {
        $admin = $this->user(['is_platform_admin' => true]);

        $this->assertSame(route('dashboard'), LandingPage::for($admin));
    }

    public function test_a_membership_that_has_been_ended_no_longer_decides_the_landing(): void
    {
        // A client whose engagement is over is not sent to a portal with nothing in it.
        $client = $this->user();
        $this->portalMemberFor($client)->update(['is_active' => false]);

        $this->assertSame(route('dashboard'), LandingPage::for($client));
    }

    public function test_an_internal_project_role_is_not_treated_as_a_portal_user(): void
    {
        // Only client and contractor are external. A project manager with no dashboard permission
        // has a permissions problem, and quietly rerouting them would hide it.
        $manager = $this->user();
        $this->portalMemberFor($manager, 'project_manager');

        $this->assertSame(route('dashboard'), LandingPage::for($manager));
    }

    public function test_a_permission_that_does_not_exist_does_not_break_the_login(): void
    {
        // Spatie throws on an unknown permission rather than returning false, and a throw here
        // would turn a redirect decision into a failed login.
        Permission::query()->delete();

        $client = $this->user();
        $this->portalMemberFor($client);

        $this->assertSame(route('project-management.portal'), LandingPage::for($client));
    }
}
