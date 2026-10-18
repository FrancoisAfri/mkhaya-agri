<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\DueDiligence\Services\OnboardingVerification;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Jobs\VerifyNewSupplierJob;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;
use App\Modules\SupplierManagement\Services\SupplierService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A source that answers every check the same way, so the service's own behaviour is what is tested. */
class StubProvider implements VerificationProvider
{
    public array $asked = [];

    public function __construct(private readonly string $status = VerificationStatus::VERIFIED) {}

    public function code(): string { return 'stub'; }
    public function capabilities(): array { return OnboardingVerification::CHECKS; }
    public function supports(string $checkType): bool { return in_array($checkType, OnboardingVerification::CHECKS, true); }
    public function health(): ProviderHealth { return new ProviderHealth(true, 'ok'); }

    public function verify(VerificationRequest $request): VerificationResult
    {
        $this->asked[] = $request->checkType;

        return new VerificationResult(
            status: $this->status,
            observed: ['checked' => true],
            confidence: 100,
            summary: 'stubbed',
        );
    }
}

/**
 * A supplier is checked the moment it registers.
 *
 * Everything needed to verify a supplier existed — the provider registry, the engine, the risk
 * scoring, the case workflow, the nightly sweep — and nothing called any of it at the one moment it
 * matters most. A company filled in the registration form, landed in the directory as inactive, and
 * sat there unchecked until a person opened the record and pressed a button, or until the overnight
 * sweep happened to reach it. Until then the buyer's only evidence the company was real was the
 * company's own word for it.
 *
 * What these hold is the shape of that first pass: that it happens at all, that it happens on both
 * roads into the directory, that a check nobody can run is counted apart from a check that failed,
 * and that something needing a human ends up in a queue rather than on a record nobody opens.
 */
class OnboardingVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);

        // The registry picks its candidates from the catalogue, so without it every check resolves to
        // "no data source" and the whole service reports a clean pass by having nothing to run.
        ProviderCatalogue::install();
    }

    /** A supplier carrying every claim the checks need. */
    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_name' => 'Checked '.Str::random(5),
            'company_registration_number' => '2019/123456/07',
            'tax_reference_number' => '9012345678',
            'vat_registration_number' => '4012345678',
            'bank_name' => 'Standard Bank',
            'account_holder_name' => 'Checked Co',
            'bank_account_number' => '1234567890',
            'branch_code' => '051001',
            'payment_terms' => '30 days',
            'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true,
            'health_safety_compliance' => true,
            'is_active' => 0,
            'is_preferred' => false,
        ], $overrides));
    }

    /**
     * The catalogue code that answers each check, so the registry resolves through its real path.
     *
     * A faked instance alone is not enough: the registry picks candidates from the tenant's enabled
     * providers and the catalogue's capabilities, so a code that is not configured is never asked.
     * That is the behaviour under test as much as anything — a tenant with nothing configured must
     * get "could not look", not "looked and it was fine".
     */
    private const SOURCES = [
        CheckType::COMPANY_REGISTRY => 'cipc',
        CheckType::TAX_COMPLIANCE => 'sars_tcs',
        CheckType::TAX_VAT_VENDOR => 'sars_vat',
        CheckType::BANK_ACCOUNT => 'avs_bankserv',
        CheckType::SCREENING_SANCTIONS => 'screening_worldcheck',
        CheckType::SCREENING_PEP => 'screening_worldcheck',
    ];

    private function useProvider(string $status = VerificationStatus::VERIFIED): StubProvider
    {
        $provider = new StubProvider($status);

        foreach (self::SOURCES as $check => $code) {
            TenantVerificationProvider::updateOrCreate(
                ['provider_code' => $code],
                [
                    'is_enabled' => true,
                    'credentials' => ['api_key' => 'test-key'],
                    'enabled_checks' => array_keys(array_filter(self::SOURCES, fn ($c) => $c === $code)),
                    'settings' => ['checks' => [$check => ['endpoint' => 'https://example.test/{registration_number}']]],
                ],
            );

            app(ProviderRegistry::class)->fake($code, $provider);
        }

        return $provider;
    }

    private function forgetProviders(): void
    {
        foreach (array_unique(array_values(self::SOURCES)) as $code) {
            app(ProviderRegistry::class)->forget($code);
        }

        TenantVerificationProvider::query()->delete();
    }

    // ── It happens at all ────────────────────────────────────────────────────

    public function test_registering_a_supplier_queues_its_first_verification(): void
    {
        /*
         * Hooked on `createSupplier` rather than on a controller, because the registration form and
         * an administrator adding a supplier by hand both arrive there — hooking either caller alone
         * leaves the other unchecked.
         */
        Queue::fake();

        $supplier = app(SupplierService::class)->createSupplier([
            'company_name' => 'Fresh '.Str::random(5),
            'payment_terms' => '30 days',
        ]);

        Queue::assertPushed(VerifyNewSupplierJob::class,
            fn (VerifyNewSupplierJob $job) => $job->supplierId === $supplier->id);
    }

    public function test_the_job_carries_the_tenant_because_a_worker_has_none(): void
    {
        /*
         * A tenant-scoped read from a worker with no company set returns nothing and reports success,
         * which here would look like a supplier that passed every check by having none to run.
         */
        Queue::fake();

        $supplier = app(SupplierService::class)->createSupplier([
            'company_name' => 'Tenanted '.Str::random(5), 'payment_terms' => '30 days',
        ]);

        Queue::assertPushed(VerifyNewSupplierJob::class,
            fn (VerifyNewSupplierJob $job) => $job->companyId === $supplier->company_id);
    }

    // ── What it checks, and what it declines to ──────────────────────────────

    public function test_it_runs_the_checks_the_registration_can_answer(): void
    {
        $provider = $this->useProvider();

        $result = app(OnboardingVerification::class)->run($this->supplier());

        $this->assertSame(count(OnboardingVerification::CHECKS), $result['checked']);
        $this->assertSame(count(OnboardingVerification::CHECKS), $result['passed']);
        $this->assertSame(0, $result['attention']);
        $this->assertEqualsCanonicalizing(OnboardingVerification::CHECKS, $provider->asked);
    }

    public function test_a_check_the_supplier_gave_nothing_for_is_skipped_not_failed(): void
    {
        /*
         * "We could not look" must never read as "we looked and it was fine". A supplier with no VAT
         * number has nothing for the VAT check to ask about, and asking anyway spends a paid lookup
         * to be told so.
         */
        $this->useProvider();

        $supplier = $this->supplier([
            'vat_registration_number' => null,
            'bank_account_number' => null,
            'bank_name' => null,
            'branch_code' => null,
            'account_holder_name' => null,
        ]);

        $plan = app(OnboardingVerification::class)->plan($supplier);

        $this->assertContains(CheckType::TAX_VAT_VENDOR, $plan['no_data']);
        $this->assertNotContains(CheckType::TAX_VAT_VENDOR, $plan['runnable']);

        $result = app(OnboardingVerification::class)->run($supplier);
        $this->assertSame(0, $result['attention'], 'a missing claim is not a failed check');
        $this->assertGreaterThan(0, $result['skipped']);
    }

    public function test_a_check_with_no_configured_source_is_skipped_not_failed(): void
    {
        // Nothing is faked, so the tenant has no data source for anything.
        $this->forgetProviders();

        $result = app(OnboardingVerification::class)->run($this->supplier());

        $this->assertSame(0, $result['checked']);
        $this->assertSame(0, $result['attention'], 'an unconfigured source is not a failed check');
        $this->assertSame(count(OnboardingVerification::CHECKS), $result['skipped']);
    }

    // ── What it does with a bad answer ───────────────────────────────────────

    public function test_a_check_that_fails_is_recorded_against_the_supplier(): void
    {
        $this->useProvider(VerificationStatus::MISMATCH);
        $supplier = $this->supplier();

        $result = app(OnboardingVerification::class)->run($supplier);

        $this->assertSame(count(OnboardingVerification::CHECKS), $result['attention']);
        $this->assertGreaterThan(0, SupplierVerification::where('supplier_id', $supplier->id)->count());
    }

    public function test_something_needing_a_human_opens_a_case(): void
    {
        /*
         * A verification sitting at `mismatch` on a record nobody has opened is the same as no
         * verification at all. The case is the thing that appears in somebody's queue.
         */
        $this->useProvider(VerificationStatus::MISMATCH);

        $this->assertTrue(app(OnboardingVerification::class)->run($this->supplier())['opened_case']);
    }

    public function test_a_clean_pass_does_not_open_a_case(): void
    {
        // Opening one for every registration would make the queue meaningless within a week.
        $this->useProvider();

        $this->assertFalse(app(OnboardingVerification::class)->run($this->supplier())['opened_case']);
    }

    // ── It never costs the registration ──────────────────────────────────────

    public function test_a_provider_that_throws_does_not_stop_the_other_checks(): void
    {
        /*
         * A sanctions screen is worth having even when the registry lookup timed out, and the
         * opposite is just as true.
         */
        $exploding = new class implements VerificationProvider {
            public function code(): string { return 'boom'; }
            public function capabilities(): array { return OnboardingVerification::CHECKS; }
            public function supports(string $checkType): bool { return true; }
            public function health(): ProviderHealth { return new ProviderHealth(false, 'down'); }
            public function verify(VerificationRequest $request): VerificationResult
            {
                throw new \RuntimeException('provider exploded');
            }
        };

        foreach (self::SOURCES as $check => $code) {
            TenantVerificationProvider::updateOrCreate(
                ['provider_code' => $code],
                [
                    'is_enabled' => true,
                    'credentials' => ['api_key' => 'test-key'],
                    'enabled_checks' => array_keys(array_filter(self::SOURCES, fn ($c) => $c === $code)),
                    'settings' => ['checks' => [$check => ['endpoint' => 'https://example.test/x']]],
                ],
            );
            app(ProviderRegistry::class)->fake($code, $exploding);
        }

        $result = app(OnboardingVerification::class)->run($this->supplier());

        /*
         * Every check was attempted rather than the first failure taking the whole pass down — and
         * every one landed as inconclusive rather than passed, which is the distinction that matters:
         * a provider outage counted as a clean pass is how a supplier gets activated on evidence
         * nobody ever collected.
         */
        $this->assertSame(count(OnboardingVerification::CHECKS), $result['checked']);
        $this->assertSame(count(OnboardingVerification::CHECKS), $result['inconclusive']);
        $this->assertSame(0, $result['passed'], 'a provider that fell over has not verified anything');
    }

    public function test_the_supplier_is_still_created_when_verification_cannot_run(): void
    {
        // The applicant filled in a long form. A provider being down must delay the decision, not
        // lose the registration.
        Queue::fake();

        $supplier = app(SupplierService::class)->createSupplier([
            'company_name' => 'Survives '.Str::random(5), 'payment_terms' => '30 days',
        ]);

        $this->assertNotNull($supplier->fresh());
        $this->assertSame(0, (int) $supplier->is_active, 'a new supplier stays inactive until a person says otherwise');
    }
}
