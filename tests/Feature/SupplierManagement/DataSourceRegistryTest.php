<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;
use Illuminate\Support\Facades\DB;

/**
 * The Data Source Registry screen.
 *
 * Two things have to hold here. The screen must show the coverage *gaps* — a registry that lists
 * only what is connected reads as full coverage, which is the impression that gets a supplier
 * onboarded on checks nobody made. And it must never hand a customer's provider credentials back to
 * the browser, because those are that customer's own account with CIPC or a credit bureau.
 */
class DataSourceRegistryTest extends SupplierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->withoutVite();
    }

    public function test_the_registry_lists_every_source_and_names_the_checks_nobody_can_answer(): void
    {
        $this->get('/suppliers/settings/data-sources')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $codes = collect($props['sources'])->pluck('code');
                $this->assertContains('manual', $codes);
                $this->assertContains('cipc', $codes);
                $this->assertContains('sars_tcs', $codes);

                // Nothing is licensed yet, so every Layer 1 check must be shown as needing a person
                // rather than being omitted from the screen.
                $checks = collect($props['checks']);
                $this->assertNotEmpty($checks);
                $this->assertTrue($checks->every(fn ($c) => $c['automated'] === false),
                    'With no provider configured, no check may claim to be automated.');

                $cipc = collect($props['sources'])->firstWhere('code', 'cipc');
                $this->assertEquals('not_configured', $cipc['state']);
                $this->assertNotEmpty($cipc['access_note'], 'A source must explain what access it needs.');
            });
    }

    public function test_enabling_a_source_makes_its_checks_automated_and_is_recorded_in_the_ledger(): void
    {
        $this->put('/suppliers/settings/data-sources/cipc', [
            'is_enabled' => true,
            'credentials' => ['api_key' => 'super-secret-key'],
            'enabled_checks' => [CheckType::COMPANY_REGISTRY, CheckType::COMPANY_DIRECTORS],
            'default_for' => [CheckType::COMPANY_REGISTRY],
            'monthly_quota' => 100,
            'checks' => [
                CheckType::COMPANY_REGISTRY => ['endpoint' => 'https://gateway.test/company/{registration_number}', 'method' => 'GET'],
            ],
        ])->assertRedirect();

        $config = TenantVerificationProvider::where('provider_code', 'cipc')->firstOrFail();
        $this->assertTrue($config->is_enabled);
        $this->assertEquals(['api_key' => 'super-secret-key'], $config->credentials);
        $this->assertEquals([CheckType::COMPANY_REGISTRY], $config->setting('default_for'));
        $this->assertEquals(100, $config->monthly_quota);

        // Turning a source on is itself a governance event.
        $this->assertDatabaseHas('dd_evidence_entries', [
            'company_id' => $this->companyId,
            'action' => 'provider.enabled',
            'provider_code' => 'cipc',
        ]);

        $this->get('/suppliers/settings/data-sources')->assertInertia(function ($page) {
            $checks = collect($page->toArray()['props']['checks']);

            $registry = $checks->firstWhere('key', CheckType::COMPANY_REGISTRY);
            $this->assertTrue($registry['automated']);
            $this->assertEquals('cipc', $registry['provider']);

            // A check the source was not enabled for stays manual.
            $this->assertFalse($checks->firstWhere('key', CheckType::GOVERNMENT_CSD)['automated']);
        });
    }

    public function test_credential_values_never_reach_the_browser(): void
    {
        $this->put('/suppliers/settings/data-sources/cipc', [
            'is_enabled' => true,
            'credentials' => ['api_key' => 'super-secret-key', 'customer_code' => 'CUST-99'],
            'enabled_checks' => [CheckType::COMPANY_REGISTRY],
        ]);

        $response = $this->get('/suppliers/settings/data-sources')->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('super-secret-key', $body, 'A credential value was rendered into the page.');
        $this->assertStringNotContainsString('CUST-99', $body);

        $response->assertInertia(function ($page) {
            $cipc = collect($page->toArray()['props']['sources'])->firstWhere('code', 'cipc');

            // The names are useful — knowing WHICH keys are set is how you spot a missing one.
            $this->assertEqualsCanonicalizing(['api_key', 'customer_code'], $cipc['credential_keys']);
        });
    }

    public function test_saving_without_retyping_a_credential_keeps_it(): void
    {
        $this->put('/suppliers/settings/data-sources/cipc', [
            'is_enabled' => true,
            'credentials' => ['api_key' => 'first-key', 'customer_code' => 'CUST-1'],
            'enabled_checks' => [CheckType::COMPANY_REGISTRY],
        ]);

        // The screen never receives the values, so a save that posts nothing must not wipe them.
        $this->put('/suppliers/settings/data-sources/cipc', [
            'is_enabled' => true,
            'enabled_checks' => [CheckType::COMPANY_REGISTRY, CheckType::COMPANY_DIRECTORS],
        ]);

        $config = TenantVerificationProvider::where('provider_code', 'cipc')->firstOrFail();
        $this->assertEquals(['api_key' => 'first-key', 'customer_code' => 'CUST-1'], $config->credentials);
    }

    public function test_a_credential_can_be_removed_deliberately(): void
    {
        $this->put('/suppliers/settings/data-sources/cipc', [
            'is_enabled' => true,
            'credentials' => ['api_key' => 'k', 'customer_code' => 'c'],
            'enabled_checks' => [CheckType::COMPANY_REGISTRY],
        ]);

        $this->delete('/suppliers/settings/data-sources/cipc/credential', ['key' => 'customer_code'])
            ->assertRedirect();

        $this->assertEquals(['api_key' => 'k'],
            TenantVerificationProvider::where('provider_code', 'cipc')->first()->credentials);
    }

    public function test_a_source_cannot_be_enabled_for_a_check_it_cannot_answer(): void
    {
        // SARS TCS answers tax compliance. Asking it for a credit report would promise coverage the
        // connector has no way to deliver.
        $this->put('/suppliers/settings/data-sources/sars_tcs', [
            'is_enabled' => true,
            'credentials' => ['api_key' => 'k'],
            'enabled_checks' => [CheckType::TAX_COMPLIANCE, CheckType::CREDIT_REPORT],
        ])->assertRedirect();

        $config = TenantVerificationProvider::where('provider_code', 'sars_tcs')->firstOrFail();
        $this->assertEquals([CheckType::TAX_COMPLIANCE], $config->enabled_checks);
    }

    public function test_an_unknown_source_is_a_404_not_a_new_row(): void
    {
        $this->put('/suppliers/settings/data-sources/not_a_provider', ['is_enabled' => true])
            ->assertNotFound();

        $this->assertDatabaseMissing('company_verification_providers', ['provider_code' => 'not_a_provider']);
    }

    public function test_the_screen_reports_the_evidence_chain_state(): void
    {
        app(\App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine::class)->recordManual(
            supplier: \App\Modules\SupplierManagement\Models\Supplier::create([
                'company_name' => 'Ledger Co', 'company_registration_number' => '2020/000001/07', 'is_active' => 1,
            ]),
            checkType: CheckType::TAX_COMPLIANCE,
            status: \App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus::VERIFIED,
        );

        $this->get('/suppliers/settings/data-sources')->assertInertia(function ($page) {
            $ledger = $page->toArray()['props']['ledger'];

            $this->assertEquals(1, $ledger['entries']);
            $this->assertTrue($ledger['ok']);
        });
    }

    public function test_another_tenant_cannot_see_or_change_this_tenants_configuration(): void
    {
        $this->put('/suppliers/settings/data-sources/cipc', [
            'is_enabled' => true,
            'credentials' => ['api_key' => 'tenant-one-key'],
            'enabled_checks' => [CheckType::COMPANY_REGISTRY],
        ]);

        $otherCompany = $this->makeCompany('Other Tenant');
        $this->activateModule($otherCompany);
        // The other tenant is on the add-on too — this test is about data isolation between two
        // paying customers, not about the entitlement gate.
        $this->activateModule($otherCompany, 'supplier_due_diligence');
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($otherCompany);
        $this->actingAs($this->makeUser($otherCompany));

        $this->get('/suppliers/settings/data-sources')->assertOk()->assertInertia(function ($page) {
            $cipc = collect($page->toArray()['props']['sources'])->firstWhere('code', 'cipc');

            $this->assertEquals('not_configured', $cipc['state'], 'Another tenant\'s provider config leaked.');
            $this->assertEmpty($cipc['credential_keys']);
        });

        // And the catalogue is shared, as intended — one list of sources for the installation.
        $this->assertEquals(1, DB::table('company_verification_providers')->where('provider_code', 'cipc')->count());
    }

    public function test_the_configure_hub_offers_the_card_and_every_locale_can_label_it(): void
    {
        $cards = collect(\App\Support\Hubs\Modules\SupplierHubs::hubs()['insights']['cards']);

        $card = $cards->first(fn ($c) => str_contains((string) ($c['path'] ?? ''), 'settings/data-sources'));
        $this->assertNotNull($card, 'The Configure hub does not offer the data sources card.');

        // A card whose label is missing from a locale file renders the raw key in that language, and
        // no amount of __() will tell you — it returns the key it was handed. So the label is
        // asserted against each locale's JSON, which is the file that is actually short.
        foreach (glob(resource_path('js/utils/locales/modules/*.json')) as $file) {
            $locale = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            $this->assertNotEmpty(
                $locale['supplier_management']['cards']['data_sources']['title'] ?? null,
                basename($file).' has no label for the data sources card.'
            );
        }
    }
}
