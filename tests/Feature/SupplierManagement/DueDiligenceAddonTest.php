<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Services\DueDiligenceVerdict;
use App\Modules\SupplierManagement\DueDiligence\Services\PersonalDataGuard;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use Illuminate\Support\Facades\DB;

/**
 * The four decisions taken about how this ships: sold as an add-on, multi-provider, POPIA-bound, and
 * consumable by another module without either becoming dependent on the other.
 *
 * The add-on tests matter most. This is going out to tenants who are already paying for Supplier
 * Management, and the guarantee is that a tenant without the add-on keeps the module exactly as it
 * was — not a degraded version of it, and not a version with empty risk panels that invite somebody
 * to conclude a supplier is clean.
 */
class DueDiligenceAddonTest extends SupplierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->activateModule($this->companyId, DueDiligenceAddon::CODE);
        $this->withoutVite();
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'company_name' => 'ABC Holdings (Pty) Ltd',
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'is_active' => 1,
        ]);
    }

    /** Take the add-on away from this tenant. */
    private function withoutAddon(): void
    {
        DB::table('company_modules')
            ->where('company_id', $this->companyId)
            ->where('module_code', DueDiligenceAddon::CODE)
            ->delete();
    }

    // ── Sold as an add-on ───────────────────────────────────────────────────

    public function test_the_addon_is_in_the_billing_catalogue_as_a_sellable_operations_module(): void
    {
        $this->assertDatabaseHas('billing_modules', [
            'code' => DueDiligenceAddon::CODE,
            'category' => 'Operations',
            'is_sellable' => true,
            'is_active' => true,
        ]);
    }

    public function test_a_tenant_with_the_addon_gets_the_due_diligence_panels(): void
    {
        $supplier = $this->supplier();

        $this->get("/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertArrayHasKey('dueDiligence', $props);
                $this->assertArrayHasKey('ownership', $props);
                $this->assertArrayHasKey('risk', $props);
            });
    }

    public function test_a_tenant_without_the_addon_keeps_the_module_exactly_as_it_was(): void
    {
        $supplier = $this->supplier();
        $this->withoutAddon();

        $this->get("/suppliers/{$supplier->uuid}")
            ->assertOk()   // the supplier page itself still works
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                // Absent, not empty: an empty risk panel invites somebody to conclude the supplier is
                // clean, which is worse than no panel at all.
                $this->assertArrayNotHasKey('dueDiligence', $props);
                $this->assertArrayNotHasKey('ownership', $props);
                $this->assertArrayNotHasKey('risk', $props);

                // And what they pay for is untouched.
                $this->assertArrayHasKey('supplier', $props);
                $this->assertArrayHasKey('riskFlags', $props);
                $this->assertArrayHasKey('documentTypes', $props);
            });
    }

    public function test_due_diligence_routes_are_closed_without_the_addon(): void
    {
        $supplier = $this->supplier();
        $this->withoutAddon();

        foreach ([
            ['post', "/suppliers/{$supplier->uuid}/risk/assess"],
            ['post', "/suppliers/{$supplier->uuid}/verifications/record"],
            ['post', "/suppliers/{$supplier->uuid}/ownership"],
            ['get', '/suppliers/settings/data-sources'],
        ] as [$method, $url]) {
            $response = $this->{$method}($url);

            $this->assertContains($response->status(), [402, 403, 404, 302],
                "{$url} was reachable without the add-on (status {$response->status()}).");
        }
    }

    public function test_the_ordinary_supplier_screens_still_work_without_the_addon(): void
    {
        $this->supplier();
        $this->withoutAddon();

        // The module they bought is untouched.
        $this->get('/suppliers/list')->assertOk();
        $this->get('/suppliers/kyc')->assertOk();
        $this->get('/suppliers/settings')->assertOk();
    }

    // ── Multi-provider ──────────────────────────────────────────────────────

    public function test_every_capability_offers_a_choice_of_providers(): void
    {
        $byCapability = ProviderCatalogue::byCapability();

        // The decision was "top 5 in the market, the client connects whichever they hold" — so the
        // checks a customer will actually license must each offer real alternatives.
        foreach ([
            CheckType::COMPANY_REGISTRY,
            CheckType::CREDIT_REPORT,
            CheckType::SCREENING_SANCTIONS,
            CheckType::BANK_ACCOUNT,
            CheckType::MEDIA_ADVERSE,
        ] as $check) {
            // Five named providers, plus the manual fallback.
            $this->assertGreaterThanOrEqual(6, count($byCapability[$check] ?? []),
                "{$check} does not offer a choice of providers.");
        }
    }

    public function test_each_social_platform_is_its_own_switch(): void
    {
        $codes = collect(ProviderCatalogue::definitions())->pluck('code');

        // One switch for "social" would mean turning on the platforms you may not use in order to
        // get the ones you may — the terms differ per platform, so the switches do too.
        foreach (['linkedin', 'x', 'facebook', 'instagram', 'youtube', 'tiktok', 'reddit'] as $platform) {
            $this->assertContains("social_{$platform}", $codes);
        }

        $social = collect(ProviderCatalogue::definitions())->firstWhere('code', 'social_linkedin');
        $this->assertEquals([CheckType::SOCIAL_REPUTATION], $social['capabilities']);
        // The constraint is stated where somebody enabling it will read it.
        $this->assertStringContainsString('prohibited by their terms', $social['access_note']);
    }

    public function test_every_provider_says_what_credentials_it_needs(): void
    {
        foreach (ProviderCatalogue::definitions() as $definition) {
            if (! ($definition['requires_credentials'] ?? true)) {
                continue;
            }

            $this->assertNotEmpty($definition['credential_fields'] ?? [],
                "{$definition['code']} needs credentials but does not say which.");
            $this->assertNotEmpty($definition['access_note'],
                "{$definition['code']} does not explain what access it needs.");
        }
    }

    // ── POPIA ───────────────────────────────────────────────────────────────

    public function test_identifiers_never_reach_an_ai_provider(): void
    {
        $guard = app(PersonalDataGuard::class);

        $redacted = $guard->redact([
            'company_name' => 'ABC Holdings',
            'directors' => [
                ['name' => 'Thandi Mokoena', 'id_number' => '8501015800085', 'date_of_birth' => '1985-01-01'],
            ],
            'bank_account_number' => '62012345678',
        ]);

        $encoded = json_encode($redacted);
        $this->assertStringNotContainsString('8501015800085', $encoded);
        $this->assertStringNotContainsString('62012345678', $encoded);
        // The name stays — that is what the model is for.
        $this->assertStringContainsString('Thandi Mokoena', $encoded);
        // And the fact that an identifier exists survives, which is what a model needs to reason
        // about completeness.
        $this->assertEquals('[redacted]', $redacted['directors'][0]['id_number']);
    }

    public function test_identifiers_are_stripped_out_of_free_text_too(): void
    {
        $guard = app(PersonalDataGuard::class);

        $text = $guard->redactText('Tax clearance for Thandi Mokoena, ID 850101 5800 085, account 62012345678.');

        $this->assertStringNotContainsString('850101 5800 085', $text);
        $this->assertStringNotContainsString('62012345678', $text);
        $this->assertStringContainsString('Thandi Mokoena', $text);
    }

    public function test_a_person_is_recorded_with_a_lawful_basis_and_a_purpose(): void
    {
        $supplier = $this->supplier();

        $person = SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Thandi', 'surname' => 'Mokoena',
            'role' => 'director', 'id_number' => '8501015800085',
        ]);

        // POPIA s13: recorded when collected, not reconstructed by a lawyer afterwards.
        $this->assertNotEmpty($person->lawful_basis);
        $this->assertNotEmpty($person->collection_purpose);
        $this->assertNotNull($person->identifiers_captured_at);
        $this->assertArrayHasKey($person->lawful_basis, PersonalDataGuard::lawfulBases());
    }

    public function test_identifiers_can_be_erased_while_the_audit_trail_survives(): void
    {
        $supplier = $this->supplier();
        $person = SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Thandi', 'surname' => 'Mokoena',
            'role' => 'director', 'id_number' => '8501015800085', 'date_of_birth' => '1985-01-01',
            'email' => 'thandi@example.test',
        ]);

        app(VerificationEngine::class)->recordManual(
            $supplier, CheckType::SCREENING_PEP, VerificationStatus::VERIFIED,
            subjectType: 'person', subjectId: $person->id, subjectLabel: $person->name(),
        );

        $person->purgeIdentifiers('Data subject erasure request.');
        $person->refresh();

        // The personal information is gone…
        $this->assertNull($person->id_number);
        $this->assertNull($person->date_of_birth);
        $this->assertNull($person->email);
        $this->assertNotNull($person->identifiers_purged_at);
        $this->assertFalse($person->is_screening_subject);

        // …and the proof that a check happened is not, because procurement law needs it.
        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'subject_id' => $person->id,
            'check_type' => CheckType::SCREENING_PEP,
        ]);
    }

    public function test_records_past_their_retention_period_are_findable(): void
    {
        $supplier = $this->supplier();

        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Long', 'surname' => 'Gone',
            'role' => 'director', 'id_number' => '8501015800085',
            'purge_after' => now()->subDay()->toDateString(),
        ]);
        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Still', 'surname' => 'Current',
            'role' => 'director', 'purge_after' => now()->addYear()->toDateString(),
        ]);

        // "Kept indefinitely because nobody chose" is the position POPIA exists to end.
        $this->assertEquals(1, SupplierPerson::where('supplier_id', $supplier->id)->duePurge()->count());
    }

    // ── Reusable by another module, separable from it ───────────────────────

    public function test_another_module_gets_a_real_verdict_when_the_addon_is_on(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);

        $verdict = app(DueDiligenceVerdict::class)->for($supplier->fresh());

        $this->assertTrue($verdict['available']);
        $this->assertTrue($verdict['assessed']);
        $this->assertIsFloat($verdict['score']);
        $this->assertNotEmpty($verdict['blockers']);
        $this->assertFalse($verdict['cleared']);
    }

    public function test_an_unassessed_supplier_is_never_reported_as_cleared(): void
    {
        $supplier = $this->supplier();

        $verdict = app(DueDiligenceVerdict::class)->for($supplier);

        $this->assertTrue($verdict['available']);
        $this->assertFalse($verdict['assessed']);
        $this->assertFalse($verdict['cleared'], 'Silence was read as clearance.');
        $this->assertEquals('never_assessed', $verdict['reason']);
    }

    public function test_without_the_addon_the_answer_is_no_opinion_rather_than_no(): void
    {
        $supplier = $this->supplier();
        $this->withoutAddon();

        $verdict = app(DueDiligenceVerdict::class)->for($supplier);

        $this->assertFalse($verdict['available']);
        $this->assertEquals('not_installed', $verdict['reason']);

        // null, not false: a false would quietly block every supplier on a tenant that never bought
        // the add-on, so the caller must be able to tell "no opinion" from "no".
        $this->assertNull(app(DueDiligenceVerdict::class)->permits($supplier));
        $this->assertNull(app(DueDiligenceVerdict::class)->summary($supplier));
        $this->assertNull(app(DueDiligenceVerdict::class)->hasPassed($supplier, CheckType::COMPANY_REGISTRY));
    }

    public function test_a_live_sanctions_match_overrides_a_clean_score_for_other_modules(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);

        ScreeningHit::create([
            'supplier_id' => $supplier->id, 'subject_type' => 'supplier', 'subject_name' => $supplier->company_name,
            'list_type' => ScreeningHit::TYPE_SANCTIONS, 'matched_name' => 'ABC Holdings',
            'match_score' => 95, 'provider_code' => 'manual', 'status' => ScreeningHit::STATUS_CONFIRMED,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $verdict = app(DueDiligenceVerdict::class)->for($supplier->fresh());

        $this->assertTrue($verdict['sanctions_hit']);
        $this->assertFalse($verdict['cleared']);
        $this->assertStringContainsString('sanctions', (string) app(DueDiligenceVerdict::class)->summary($supplier->fresh()));
    }
}
