<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The `api` middleware group resolves a tenant — for every API route, not just PM's.
 *
 * This is a platform-wide behaviour change and it is deliberate. Until now the group was
 * `throttle` + `SubstituteBindings`, so API requests ran with no tenant: the ORM's CompanyScope did
 * not filter, and an unset `app.company_id` makes the row-level-security policy
 * (`app.company_id IS NULL OR company_id = app.company_id`) match every row. Endpoints such as the
 * HelpDesk mobile controller — which contains no company filtering of its own — were relying on
 * nothing at all.
 *
 * The tests use a throwaway route so they assert the *group's* contract rather than any one
 * module's fixtures, which is what makes them a regression guard for HelpDesk, Fleet, CRM and
 * anything added later.
 */
class ApiTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/_tenant-probe', function (CurrentCompany $current) {
            return response()->json(['company_id' => $current->id()]);
        });
    }

    public function test_an_api_request_resolves_the_tenant_from_the_token(): void
    {
        $company = Company::create(['name' => 'Alpha Co']);
        $user = User::factory()->create(['status' => 1, 'company_id' => $company->id]);

        Sanctum::actingAs($user);

        $this->getJson('/api/_tenant-probe')
            ->assertOk()
            ->assertJsonPath('company_id', $company->id);
    }

    public function test_the_tenant_cannot_be_chosen_by_the_client(): void
    {
        $mine = Company::create(['name' => 'Alpha Co']);
        $theirs = Company::create(['name' => 'Bravo Co']);
        $user = User::factory()->create(['status' => 1, 'company_id' => $mine->id]);

        Sanctum::actingAs($user);

        // A parameter naming another company changes nothing: the tenant comes from the token.
        $this->getJson("/api/_tenant-probe?company_id={$theirs->id}")
            ->assertOk()
            ->assertJsonPath('company_id', $mine->id);
    }

    public function test_an_unauthenticated_api_request_resolves_no_tenant(): void
    {
        // Public and service-token endpoints still work; they simply carry no tenant, exactly as
        // before. Routes that must not be served without one say so (see RequireTenantContext).
        $this->getJson('/api/_tenant-probe')
            ->assertOk()
            ->assertJsonPath('company_id', null);
    }

    public function test_api_tables_are_row_level_protected_so_the_new_context_scopes_them(): void
    {
        // The CRM REST API reads crm_deals; row-level security is what isolates it once
        // `app.company_id` is set — which is what the new middleware does. This asserts the
        // database half of that pair is in place, since the test environment deliberately leaves
        // the session variable unset (see ResolveCompanyFromToken::stampDbSession).
        $policy = DB::selectOne(
            "SELECT polname FROM pg_policy WHERE polrelid = 'crm_deals'::regclass AND polname = 'tenant_isolation'"
        );

        $this->assertNotNull($policy, 'crm_deals must keep its tenant_isolation policy.');

        $forced = DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE relname = 'crm_deals'");
        $this->assertTrue((bool) $forced->relforcerowsecurity);
    }
}
