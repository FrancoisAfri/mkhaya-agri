<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Project Management can actually be bought (billing).
 *
 * Worth its own test because the failure mode is invisible in development: a platform admin
 * bypasses the entitlement check entirely, so a module that no plan includes looks perfectly fine
 * to whoever built it and 403s for every paying customer.
 */
class BillingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * The entitlement check reads the *active* tenant rather than taking one as an argument, so a
     * test has to enter the tenant the way a request does.
     */
    /**
     * These are SaaS billing rules, so they are asserted against a SaaS edition.
     *
     * On a self-hosted installation entitlement comes from the signed licence file and not from a
     * plan or an add-on row — `CompanyEntitlements::currentAllowsModule()` short-circuits to the
     * licence before it looks at any of this. Left unpinned, the whole class failed under
     * `APP_EDITION=self_hosted` while describing behaviour that edition does not have, which makes
     * a self-hosted run report noise instead of news.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['edition.edition' => 'saas']);
    }

    private function entitledAs(Company $company, string $code = 'project_management'): bool
    {
        $current = app(CurrentCompany::class);
        $current->set($company->id);

        try {
            return app(CompanyEntitlements::class)->currentAllowsModule($code);
        } finally {
            $current->clear();
        }
    }

    public function test_it_is_sellable_on_its_own(): void
    {
        $module = DB::table('billing_modules')->where('code', 'project_management')->first();

        $this->assertNotNull($module, 'Project Management is not in the module catalogue at all.');
        $this->assertTrue((bool) $module->is_sellable);
        $this->assertTrue((bool) $module->is_active);
        $this->assertSame('450.00', $module->price_monthly);
    }

    public function test_the_standard_plan_includes_it(): void
    {
        $plan = DB::table('plans')->where('code', 'standard')->first();

        if (! $plan) {
            $this->markTestSkipped('No standard plan in this installation.');
        }

        $this->assertContains('project_management', json_decode($plan->modules, true) ?: []);
    }

    /** A tenant on Standard reaches the module without buying anything extra. */
    public function test_a_standard_tenant_is_entitled(): void
    {
        $company = Company::create(['name' => 'Alpha Construction', 'plan' => 'standard']);

        $this->assertTrue($this->entitledAs($company));
    }

    /**
     * Enterprise carries an empty module list, which the entitlement layer reads as "everything".
     * Adding a code to that list would silently narrow the plan to one module — the migration
     * skips empty lists for exactly this reason.
     */
    public function test_an_enterprise_tenant_is_entitled_without_the_code_being_listed(): void
    {
        $plan = DB::table('plans')->where('code', 'enterprise')->first();

        if (! $plan) {
            $this->markTestSkipped('No enterprise plan in this installation.');
        }

        $this->assertSame([], json_decode($plan->modules, true) ?: [], 'Enterprise should still mean all modules.');

        $company = Company::create(['name' => 'Bravo Civils', 'plan' => 'enterprise']);

        $this->assertTrue($this->entitledAs($company));
    }

    public function test_a_starter_tenant_is_not_entitled_until_they_buy_it(): void
    {
        $company = Company::create(['name' => 'Charlie Plumbing', 'plan' => 'starter']);

        $this->assertFalse($this->entitledAs($company));

        // The à-la-carte route: a company_modules row is what buying the add-on writes.
        DB::table('company_modules')->insert([
            'company_id'    => $company->id,
            'module_code'   => 'project_management',
            'source'        => 'addon',
            'price_monthly' => 450,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->assertTrue($this->entitledAs($company->fresh()));
    }
}
