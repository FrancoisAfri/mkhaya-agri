<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\Models\DueDiligenceProvider;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A shipped call profile, so a customer supplies credentials and nothing else.
 *
 * The catalogue said what a source can answer and what credentials it needs, and nothing about where
 * to call or how to read the reply. That left the endpoint and the field map to the customer — a
 * developer's task wearing a buyer's hat — so enabling CIPC and pasting a key produced an enabled
 * provider that called nothing, and read as a broken integration rather than an unfinished one.
 *
 * The profile sits UNDER the tenant's settings rather than over them, which is the whole design: a
 * commercial contract's response shape varies by tier, so the customer's own documentation has to
 * win whenever the two disagree, while a customer with no documentation still gets a working source.
 */
class ProviderProfileTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);
        ProviderCatalogue::install();
    }

    private function profile(array $checks): void
    {
        DueDiligenceProvider::query()->where('code', 'searchworks')->update([
            'profile' => ['checks' => $checks],
            'profile_source' => 'test fixture',
        ]);
    }

    private function enable(array $settings = []): void
    {
        TenantVerificationProvider::updateOrCreate(
            ['provider_code' => 'searchworks'],
            [
                'is_enabled' => true,
                'credentials' => ['api_key' => 'k'],
                'enabled_checks' => [CheckType::COMPANY_REGISTRY],
                'settings' => $settings,
            ],
        );

        app(ProviderRegistry::class)->forget('searchworks');
    }

    // ── Credentials alone are enough ────────────────────────────────────────

    public function test_a_source_with_a_profile_works_on_credentials_alone(): void
    {
        /*
         * The point of the whole thing. Before this, a tenant who entered only credentials got a
         * source that reported itself enabled and answered no check.
         */
        $this->profile([CheckType::COMPANY_REGISTRY => [
            'endpoint' => 'https://api.example.test/company/{registration_number}',
            'map' => ['company_name' => 'data.name'],
        ]]);
        $this->enable();   // no settings at all

        $provider = app(ProviderRegistry::class)->forCheck(CheckType::COMPANY_REGISTRY);

        $this->assertNotNull($provider, 'a profiled source must resolve without the tenant configuring an endpoint');
        $this->assertTrue($provider->supports(CheckType::COMPANY_REGISTRY));
    }

    public function test_a_source_with_no_profile_and_no_settings_answers_nothing(): void
    {
        /*
         * And it must stay that way. Resolving to a provider that cannot call anywhere would have
         * the engine record an answer nobody obtained.
         */
        $this->profile([]);
        $this->enable();

        $this->assertNull(app(ProviderRegistry::class)->forCheck(CheckType::COMPANY_REGISTRY));
    }

    // ── The tenant's own documentation wins ─────────────────────────────────

    public function test_a_tenants_endpoint_overrides_the_shipped_one(): void
    {
        // A contract tier with a different base address must not require the field map to be retyped.
        $this->profile([CheckType::COMPANY_REGISTRY => [
            'endpoint' => 'https://shipped.example.test/{registration_number}',
            'method' => 'GET',
            'map' => ['company_name' => 'data.name', 'status' => 'data.status'],
        ]]);
        $this->enable(['checks' => [CheckType::COMPANY_REGISTRY => [
            'endpoint' => 'https://their-tier.example.test/{registration_number}',
        ]]]);

        $provider = app(ProviderRegistry::class)->forCheck(CheckType::COMPANY_REGISTRY);
        $spec = $this->specFor($provider, CheckType::COMPANY_REGISTRY);

        $this->assertSame('https://their-tier.example.test/{registration_number}', $spec['endpoint']);
        // Canonicalising, not identical: the profile is stored as jsonb, which does not preserve
        // key order, so comparing order here would be asserting on Postgres rather than on us.
        $this->assertEqualsCanonicalizing(
            ['company_name' => 'data.name', 'status' => 'data.status'],
            $spec['map'],
            'overriding the address must not cost the shipped field map',
        );
    }

    public function test_overriding_one_check_leaves_the_others_shipped(): void
    {
        // Merged per check, so a tenant correcting one endpoint does not lose the other five.
        $this->profile([
            CheckType::COMPANY_REGISTRY => ['endpoint' => 'https://shipped.test/a', 'map' => ['x' => 'y']],
            CheckType::COMPANY_DIRECTORS => ['endpoint' => 'https://shipped.test/b', 'map' => ['p' => 'q']],
        ]);
        $this->enable(['checks' => [CheckType::COMPANY_REGISTRY => ['endpoint' => 'https://theirs.test/a']]]);

        $provider = app(ProviderRegistry::class)->forCheck(CheckType::COMPANY_REGISTRY);

        $this->assertSame('https://theirs.test/a', $this->specFor($provider, CheckType::COMPANY_REGISTRY)['endpoint']);
        $this->assertSame('https://shipped.test/b', $this->specFor($provider, CheckType::COMPANY_DIRECTORS)['endpoint']);
    }

    public function test_a_check_with_no_endpoint_on_either_side_is_not_claimed(): void
    {
        /*
         * Claiming it would have the registry resolve this source for a check it cannot make, and
         * the engine record an unavailable answer against a provider that was never asked.
         */
        $this->profile([CheckType::COMPANY_REGISTRY => ['map' => ['x' => 'y']]]);   // no endpoint
        $this->enable();

        $this->assertNull(app(ProviderRegistry::class)->forCheck(CheckType::COMPANY_REGISTRY));
    }

    // ── Installing one ──────────────────────────────────────────────────────

    public function test_a_profile_that_would_call_nothing_is_refused(): void
    {
        /*
         * The failure guarded against is specific: a source that is configured, enabled, reports
         * itself healthy and silently answers no check reads to everybody as "the provider is down"
         * rather than as a typo in a key.
         */
        $file = tempnam(sys_get_temp_dir(), 'profile').'.json';
        file_put_contents($file, json_encode(['checks' => [
            CheckType::COMPANY_REGISTRY => ['endpoint' => 'https://x.test/{registration_number}'],
        ]]));

        $this->artisan('suppliers:providers:profile', ['code' => 'searchworks', 'file' => $file])
            ->expectsOutputToContain('no field map')
            ->assertExitCode(1);

        unlink($file);
    }

    public function test_a_profile_for_a_check_the_source_cannot_answer_is_refused(): void
    {
        // The catalogue is what the registry consults when choosing a source, so such a profile
        // would never be reached — installing it would be a silent no-op.
        $file = tempnam(sys_get_temp_dir(), 'profile').'.json';
        file_put_contents($file, json_encode(['checks' => [
            'not.a.real.check' => ['endpoint' => 'https://x.test/a', 'map' => ['a' => 'b']],
        ]]));

        $this->artisan('suppliers:providers:profile', ['code' => 'searchworks', 'file' => $file])
            ->assertExitCode(1);

        unlink($file);
    }

    public function test_a_valid_profile_installs_and_records_where_it_came_from(): void
    {
        /*
         * The source matters: a reader has to be able to tell a profile written from published
         * documentation from one reverse-engineered out of a sample response.
         */
        $file = tempnam(sys_get_temp_dir(), 'profile').'.json';
        file_put_contents($file, json_encode(['checks' => [
            CheckType::COMPANY_REGISTRY => [
                'endpoint' => 'https://api.example.test/company/{registration_number}',
                'map' => ['company_name' => 'data.registeredName'],
            ],
        ]]));

        $this->artisan('suppliers:providers:profile', [
            'code' => 'searchworks', 'file' => $file, '--source' => 'SearchWorks API v2',
        ])->assertExitCode(0);

        $provider = DueDiligenceProvider::where('code', 'searchworks')->first();

        $this->assertSame('SearchWorks API v2', $provider->profile_source);
        $this->assertArrayHasKey(CheckType::COMPANY_REGISTRY, $provider->profile['checks']);

        unlink($file);
    }

    public function test_the_catalogue_ships_no_invented_profiles(): void
    {
        /*
         * A profile can only be written against a provider's real documentation. One invented from a
         * guess produces a connector that returns plausible answers, which is the single most
         * dangerous thing this module could contain — so the catalogue ships none, and adding one is
         * a data change made from a document somebody is holding.
         */
        $this->assertSame(
            0,
            DueDiligenceProvider::query()->whereNotNull('profile')->count(),
            'a shipped profile must come from documentation, not from the catalogue definitions',
        );
    }

    /** @return array<string, mixed> */
    private function specFor(object $provider, string $check): array
    {
        $method = (new \ReflectionClass($provider))->getMethod('checkSpecs');
        $method->setAccessible(true);

        return $method->invoke($provider)[$check] ?? [];
    }
}
