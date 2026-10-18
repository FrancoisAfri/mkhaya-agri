<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\License\LicenseService;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Support\ProjectHubs;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Project Management on a single-tenant (self-hosted) build.
 *
 * Everything the module relies on to decide what a user may see runs through a different branch
 * here: entitlements come from a signed licence rather than a subscription plan, and there is no
 * tenant switcher to fall back on. None of it is PM code, which is exactly why it is worth a test —
 * PM inherits the behaviour and would inherit a regression in it silently.
 *
 * The failure mode is a dead build rather than a crash: the sidebar simply omits the module, or the
 * nightly crons quietly process nobody, and nothing throws.
 */
class SelfHostedTest extends ProjectManagementTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['edition.edition' => 'self_hosted']);

        // Entitlements memoise the sellable-module list, and PM's registry caches per company.
        Cache::flush();
        app()->forgetInstance(CompanyEntitlements::class);
    }

    /** A licence that grants exactly the module codes given, and is actively enforced. */
    private function licensing(array $modules): void
    {
        $this->mock(LicenseService::class, function ($mock) use ($modules) {
            $mock->shouldReceive('allows')
                ->andReturnUsing(fn (string $code) => in_array('*', $modules, true) || in_array($code, $modules, true));
            $mock->shouldReceive('status')->andReturn([
                'valid' => true, 'enforced' => true, 'reason' => null,
                'payload' => ['modules' => $modules],
            ]);
        });

        app()->forgetInstance(CompanyEntitlements::class);
    }

    private function entitlements(): CompanyEntitlements
    {
        return app(CompanyEntitlements::class);
    }

    /**
     * PM is a sellable module, so on self-hosted the licence — not the company's plan — decides
     * whether the sidebar carries it. A module missing from `billing_modules` would be treated as
     * core and shown regardless, which would be a licensing hole rather than a nav bug.
     */
    public function test_the_module_is_licence_gated_rather_than_treated_as_core(): void
    {
        $this->assertTrue(
            DB::table('billing_modules')->where('code', 'project_management')->where('is_sellable', true)->exists(),
            'PM is not sellable, so allowsNavModule() would short-circuit to true and the licence would never apply.'
        );
    }

    /** With PM on the licence, every one of its ribbons is offered. */
    public function test_a_licence_covering_the_module_shows_it_in_the_sidebar(): void
    {
        $this->licensing(['project_management']);

        $this->assertTrue($this->entitlements()->allowsNavModule('project_management'));
        $this->assertTrue($this->entitlements()->currentAllowsModule('project_management'));
    }

    /** And without it, the module is hidden rather than half-working. */
    public function test_a_licence_without_the_module_hides_it(): void
    {
        $this->licensing(['sheq']);

        $this->assertFalse($this->entitlements()->allowsNavModule('project_management'));
        $this->assertFalse($this->entitlements()->currentAllowsModule('project_management'));
    }

    /**
     * The route gate reads the same verdict, so an unlicensed build cannot be reached by URL.
     *
     * Both shapes matter: a person typing the address gets sent back to the dashboard with a
     * message, while the SPA — which cannot follow a redirect into a Blade page — gets a 403. A
     * self-hosted admin hits the first path, so asserting only the 403 would miss it entirely.
     */
    public function test_an_unlicensed_build_refuses_the_pages_as_well_as_hiding_them(): void
    {
        $this->licensing(['sheq']);

        foreach (['/project-management', '/project-management/projects'] as $path) {
            $this->actingAs($this->manager)->get($path)
                ->assertRedirect(route('dashboard'))
                ->assertSessionHas('error');

            // The version header matters: without it Inertia answers 409 (asset mismatch) before
            // the middleware ever runs, and the test would pass on a build where the gate is gone.
            $this->actingAs($this->manager)
                ->get($path, [
                    'X-Inertia'         => 'true',
                    'X-Inertia-Version' => \Inertia\Inertia::getVersion(),
                ])
                ->assertForbidden();
        }
    }

    /** Licensed, the same user gets in — otherwise the test above proves nothing. */
    public function test_a_licensed_build_serves_the_pages(): void
    {
        $this->licensing(['*']);

        $this->actingAs($this->manager)->get('/project-management')->assertOk();
        $this->actingAs($this->manager)->get('/project-management/projects')->assertOk();
    }

    /**
     * Integrations degrade against the licence, not a plan.
     *
     * PM's providers (client lookup, expenses, documents…) resolve to the Integrated implementation
     * only when the other module is genuinely available. On self-hosted "available" means licensed,
     * so a build that bought PM alone has to fall back to the Local providers rather than reaching
     * for a CRM that is not there.
     */
    public function test_integrations_resolve_against_the_licence(): void
    {
        // The module has to be installed as well as licensed, or "not available" would be proving
        // the wrong thing — an absent security_modules row rather than an absent licence grant.
        $this->installModule('crm');

        $this->licensing(['project_management']);
        Cache::flush();

        $this->assertFalse(
            app(ModuleRegistry::class)->has('crm.customers'),
            'CRM is installed but not licensed, so PM must fall back to its Local client provider.'
        );

        // Licence it and the same installed module becomes reachable — otherwise the assertion
        // above would pass on a build where integrations never resolve at all.
        $this->licensing(['project_management', 'crm']);
        Cache::flush();

        $this->assertTrue(app(ModuleRegistry::class)->has('crm.customers'));
    }

    /** Register a module as installed and active, the way a real deployment would. */
    private function installModule(string $code): void
    {
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => $code],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'name' => ucfirst($code),
                'category_id' => DB::table('security_categories')->value('id'),
                'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        Cache::forget("module_active_{$code}");
    }

    /**
     * The nightly crons reach the single tenant.
     *
     * This is the one that fails silently. `eachCompany()` selects companies whose status is active
     * or trial — a lifecycle driven by subscriptions in SaaS and by nothing at all here. If a
     * self-hosted company ever sat outside that set, every PM cron would run, report success and
     * process nobody.
     */
    public function test_the_per_tenant_cron_fan_out_reaches_a_self_hosted_company(): void
    {
        $company = DB::table('companies')->first();

        $this->assertNotNull($company, 'A self-hosted install always has its one company.');
        $this->assertContains(
            $company->status,
            ['active', 'trial'],
            'A self-hosted company outside active/trial would be skipped by every scheduled command.'
        );

        $seen = [];

        $command = new class extends Command {
            use RunsPerTenant;

            protected $signature = 'pm:test-fan-out';

            public $collect;

            public function handle(TenantContext $tenants): int
            {
                $this->eachTenant($tenants, fn (int $companyId) => ($this->collect)($companyId));

                return self::SUCCESS;
            }
        };

        $command->collect = function (int $id) use (&$seen) {
            $seen[] = $id;
        };

        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);
        $this->artisan('pm:test-fan-out')->assertSuccessful();

        $this->assertContains(
            (int) $company->id,
            $seen,
            'The fan-out skipped the only company on the install, so every PM cron would be a no-op.'
        );
    }

    /**
     * Nothing in the module's own navigation branches on edition.
     *
     * The ribbons, the hub cards and their labels are the same on both builds — the licence decides
     * whether the module appears at all, not which parts of it do. A tier or card that quietly
     * differed per edition would make the two builds diverge with no test to notice.
     */
    public function test_the_navigation_itself_is_the_same_on_both_editions(): void
    {
        config(['edition.edition' => 'self_hosted']);
        $selfHosted = [ProjectHubs::direct(), ProjectHubs::hubs(), ProjectHubs::guards()];

        config(['edition.edition' => 'saas']);
        $saas = [ProjectHubs::direct(), ProjectHubs::hubs(), ProjectHubs::guards()];

        $this->assertEquals($saas, $selfHosted);
    }
}
