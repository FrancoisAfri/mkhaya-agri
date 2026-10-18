<?php

namespace Tests\Feature\Onboarding;

use App\Core\License\LicenseService;
use App\Modules\Tenancy\Services\CompanyEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ActivatesBoardingModule;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * The boarding module on both editions, and the isolation of everything added to it.
 *
 * Two kinds of claim are checked here, both of which fail silently when wrong.
 *
 * Edition: nothing in the module branches on saas vs self_hosted — the licence decides whether it
 * appears at all, not which parts of it work. That is a property worth pinning, because the day
 * somebody adds an `if (Edition::isSelfHosted())` inside a controller is the day the two builds
 * start diverging without anybody noticing.
 *
 * Tenancy: this session added two tables with hand-written RLS policies and made company_id
 * fillable on six models where it had been silently discarded. A wrong policy does not error — it
 * shows one tenant another tenant's import history.
 */
class BoardingEditionAndTenancyTest extends TestCase
{
    use RefreshDatabase, MakesCompanies, ActivatesBoardingModule;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('RESET app.company_id');
        Cache::flush();
        $this->activateBoardingModule();
    }

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

    // ── Edition ──────────────────────────────────────────────────────────────

    /** Gated by the licence, not treated as core and shown regardless. */
    public function test_the_module_is_licence_gated_on_self_hosted(): void
    {
        config(['edition.edition' => 'self_hosted']);

        $this->assertTrue(
            DB::table('billing_modules')->where('code', 'boarding')->where('is_sellable', true)->exists(),
            'A module missing from billing_modules is treated as core, and the licence never applies to it.'
        );

        $this->licensing(['boarding']);
        $this->assertTrue(app(CompanyEntitlements::class)->allowsNavModule('boarding'));

        $this->licensing(['sheq']);
        $this->assertFalse(app(CompanyEntitlements::class)->allowsNavModule('boarding'));
    }

    /**
     * Nothing added this session branches on edition.
     *
     * Asserted against the source because the failure is architectural rather than behavioural:
     * a single edition check inside a controller is invisible until the two builds disagree.
     */
    public function test_no_boarding_code_branches_on_edition(): void
    {
        $offenders = [];

        foreach ([app_path('Modules/HR/Onboarding'), app_path('Modules/HR/PreBoarding')] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                if (str_contains($source, 'isSelfHosted') || str_contains($source, "edition.edition")) {
                    $offenders[] = str_replace(app_path(), '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'The licence decides whether the module appears, not which parts of it work.');
    }

    // ── Tenancy of the tables added this session ─────────────────────────────

    /** @return array<int, string> */
    private function newTables(): array
    {
        return ['onboarding_bulk_imports', 'onboarding_policy_acknowledgements'];
    }

    public function test_the_new_tables_have_forced_row_level_security(): void
    {
        foreach ($this->newTables() as $table) {
            $row = DB::selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ?',
                [$table]
            );

            $this->assertTrue((bool) $row->relrowsecurity, "{$table} does not have RLS enabled.");
            $this->assertTrue(
                (bool) $row->relforcerowsecurity,
                "{$table} does not FORCE RLS, so the owning role bypasses it — which is the role the app connects as."
            );
        }
    }

    public function test_a_tenant_cannot_see_another_tenants_rows_in_the_new_tables(): void
    {
        $mine = $this->makeCompany('Tenant A');
        $theirs = $this->makeCompany('Tenant B');
        $author = \App\Core\User\Models\User::factory()->create()->id;

        DB::statement('RESET app.company_id');

        DB::table('onboarding_bulk_imports')->insert([
            'uuid' => (string) Str::uuid(), 'filename' => 'theirs.csv',
            'created_by' => $author, 'company_id' => $theirs,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::statement("SET app.company_id = '{$mine}'");

        $this->assertSame(
            0,
            DB::table('onboarding_bulk_imports')->count(),
            'One tenant can read another tenant\'s import history.'
        );

        DB::statement("SET app.company_id = '{$theirs}'");
        $this->assertSame(1, DB::table('onboarding_bulk_imports')->count(), 'The owner should still see their own.');

        DB::statement('RESET app.company_id');
    }

    /**
     * The catalogue is the deliberate exception: readable by everyone, writable by nobody.
     *
     * Every other table added is strictly per-tenant, so this asserts the exception is exactly one
     * table wide rather than a policy somebody copied.
     */
    public function test_only_the_template_catalogue_is_globally_readable(): void
    {
        $globalReadPolicies = DB::select(
            "select tablename from pg_policies where policyname = 'global_catalogue_read' order by tablename"
        );

        $this->assertSame(
            ['onboarding_template_tasks', 'onboarding_templates'],
            array_column($globalReadPolicies, 'tablename'),
            'Something other than the shared catalogue has been made globally readable.'
        );
    }

    /**
     * company_id is mass-assignable everywhere the module writes it.
     *
     * It was not, on six models, so every create() passing it had it silently dropped — leaving
     * rows belonging to no tenant, which the RLS policy then hides from everyone including their
     * owner. Cheap to assert, and it has already gone wrong four times.
     */
    public function test_company_id_is_fillable_on_every_tenant_scoped_model(): void
    {
        $models = [
            \App\Modules\HR\Onboarding\Models\OnboardingPlan::class,
            \App\Modules\HR\Onboarding\Models\OnboardingTask::class,
            \App\Modules\HR\Onboarding\Models\OnboardingDocument::class,
            \App\Modules\HR\Onboarding\Models\OnboardingProvisioningTask::class,
            \App\Modules\HR\Onboarding\Models\OnboardingTemplate::class,
            \App\Modules\HR\Onboarding\Models\OnboardingTemplateTask::class,
            \App\Modules\HR\Onboarding\Models\OnboardingBulkImport::class,
            \App\Modules\HR\PreBoarding\Models\HrPerson::class,
            \App\Modules\HR\PreBoarding\Models\PreBoardingPlan::class,
            \App\Modules\HR\PreBoarding\Models\PreBoardingTask::class,
        ];

        foreach ($models as $class) {
            $this->assertContains(
                'company_id',
                (new $class)->getFillable(),
                class_basename($class) . ' would silently discard company_id on create().'
            );
        }
    }
}
