<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The payslip portal an employee of a bureau client reaches from the link they are sent.
 *
 * There is one thing to prove here and it is the thing that was broken: the portal must open
 * without a Hub login. These people have no account and the whole point of the portal is that they
 * never need one — so a redirect to a login page is not a small inconvenience, it is the feature
 * not working at all, silently, for everybody.
 *
 * It broke because the routes were declared inside a route file that is required within an
 * authenticated group, and inherited `auth` without anything in the file saying so. A test that
 * only ever calls the route while signed in would never have noticed, which is why this one
 * deliberately does not sign in.
 */
class BureauPayslipPortalTest extends TestCase
{
    use DatabaseTransactions;

    private BureauEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        app(CurrentCompany::class)->set((int) $companyId);

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('security_modules')->updateOrInsert(['code_name' => 'payroll_bureau'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Payroll Bureau', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Cache::flush();

        $agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'portal+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
        ]);

        $client = BureauCompany::create([
            'agent_id' => $agent->id, 'name' => 'Portal Co', 'created_by' => $agent->id,
        ]);

        $this->employee = BureauEmployee::create([
            'bureau_company_id' => $client->id,
            'employee_number'   => 'PP' . Str::padLeft((string) random_int(1, 999999), 6, '0'),
            'first_name'        => 'Thandi', 'last_name' => 'Mokoena',
            'status'            => BureauEmployee::STATUS_ACTIVE,
            'start_date'        => '2024-01-01', 'basic_salary' => 25000,
        ]);

        $this->employee->ensurePortalToken();
    }

    public function test_the_portal_opens_without_a_hub_login(): void
    {
        // Deliberately not signed in. An employee of a bureau client has no Hub account, and the
        // link they were emailed is the only credential they will ever have.
        $this->assertGuest();

        $this->get(route('payroll.bureau.portal.show', $this->employee->portal_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/HR/Payroll/Bureau/PayslipPortal')
                ->where('employee.name', 'Thandi Mokoena'));
    }

    public function test_the_portal_route_carries_no_authentication_middleware(): void
    {
        // Asserted against the router rather than only through a request, because the way this
        // broke was inheritance from an enclosing group — invisible in the file that declares it.
        $route = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->getName() === 'payroll.bureau.portal.show');

        $this->assertNotNull($route);
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains(\Illuminate\Auth\Middleware\Authenticate::class, $route->gatherMiddleware());
    }

    public function test_a_token_nobody_issued_is_not_found(): void
    {
        $this->get(route('payroll.bureau.portal.show', 'not-a-real-token-at-all'))->assertNotFound();
    }

    public function test_the_download_route_is_reachable_without_a_login_too(): void
    {
        // No finalised payslip, so 404 is the right answer — but it must be the controller's 404
        // rather than a redirect to a login page.
        $this->get(route('payroll.bureau.portal.download', [$this->employee->portal_token, (string) Str::uuid()]))
            ->assertNotFound();
    }

    public function test_the_token_is_long_enough_to_be_the_credential_it_is(): void
    {
        $this->assertSame(48, strlen($this->employee->portal_token));

        // Issued once and not rolled on every read — the link in an old email must keep working.
        $again = $this->employee->fresh()->ensurePortalToken();
        $this->assertSame($this->employee->portal_token, $again);
    }
}
