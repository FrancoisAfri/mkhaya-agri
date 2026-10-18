<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\ScreeningMatcher;
use App\Modules\SupplierManagement\DueDiligence\Services\ScreeningService;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;

/**
 * Sanctions and PEP screening.
 *
 * Two things decide whether screening is worth having, and neither is the search itself.
 *
 * The first is match quality. Match loosely and every supplier with a common surname raises an alert;
 * within two months people clear hits without reading them, and then the real one gets cleared too.
 * The second is memory: a hit somebody cleared in January that comes back in February makes the whole
 * feature noise. Both are what these tests are about.
 */
class ScreeningTest extends SupplierTestCase
{
    private ScreeningService $screening;

    private ScreeningMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->screening = app(ScreeningService::class);
        $this->matcher = app(ScreeningMatcher::class);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'company_name' => 'ABC Holdings (Pty) Ltd',
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'is_active' => 1,
        ]);
    }

    /** A screening provider that returns a fixed candidate list. */
    private function stubProvider(array $candidates): void
    {
        TenantVerificationProvider::create([
            'provider_code' => 'screening_complyadvantage',
            'is_enabled' => true,
            'credentials' => ['api_key' => 'k'],
            'enabled_checks' => [CheckType::SCREENING_SANCTIONS, CheckType::SCREENING_PEP],
        ]);

        app(ProviderRegistry::class)->fake('screening_complyadvantage', new class($candidates) implements VerificationProvider
        {
            public function __construct(private array $candidates) {}

            public function code(): string
            {
                return 'screening_complyadvantage';
            }

            public function capabilities(): array
            {
                return [CheckType::SCREENING_SANCTIONS, CheckType::SCREENING_PEP];
            }

            public function supports(string $checkType): bool
            {
                return in_array($checkType, $this->capabilities(), true);
            }

            public function verify(VerificationRequest $request): VerificationResult
            {
                // Providers hand back their payload; turning it into hits is the service's job.
                return new VerificationResult(
                    status: VerificationStatus::VERIFIED,
                    observed: ['matches' => $this->candidates],
                    summary: 'Screened.',
                );
            }

            public function health(): ProviderHealth
            {
                return ProviderHealth::ok();
            }
        });
    }

    // ── The matcher ─────────────────────────────────────────────────────────

    public function test_word_order_and_honorifics_do_not_defeat_a_match(): void
    {
        $result = $this->matcher->score(
            ['name' => 'Mr John Andrew Smith'],
            ['name' => 'SMITH, John Andrew'],
        );

        $this->assertGreaterThanOrEqual(90, $result['score']);
        $this->assertTrue($result['reportable']);
    }

    public function test_transliteration_variants_still_match(): void
    {
        // The same person is spelled four ways across list sources; missing that is how a designated
        // individual walks through a screening.
        foreach (['Mohammed Al Rashid', 'Muhammad Al Rashid', 'Mohamed Al-Rashid'] as $variant) {
            $result = $this->matcher->score(['name' => 'Mohammad Al Rashid'], ['name' => $variant]);

            $this->assertTrue($result['reportable'], "{$variant} was not matched.");
        }
    }

    public function test_an_alias_is_matched_as_readily_as_the_primary_name(): void
    {
        $result = $this->matcher->score(
            ['name' => 'Thabo Nkosi'],
            ['name' => 'Someone Completely Different', 'aliases' => ['T. Nkosi', 'Thabo Nkosi']],
        );

        $this->assertGreaterThanOrEqual(90, $result['score']);
    }

    public function test_a_matching_identifier_settles_it_and_a_differing_one_all_but_kills_it(): void
    {
        $confirms = $this->matcher->score(
            ['name' => 'J Smith', 'identifier' => '8501015800085'],
            ['name' => 'John Smith', 'identifier' => '850101 5800 085'],
        );
        $this->assertEquals(100, $confirms['score']);

        $contradicts = $this->matcher->score(
            ['name' => 'John Smith', 'identifier' => '8501015800085'],
            ['name' => 'John Smith', 'identifier' => '9002026000082'],
        );
        $this->assertFalse($contradicts['reportable'], 'A different ID number is a no, not a maybe.');
    }

    public function test_a_date_of_birth_twenty_years_out_stops_a_same_name_hit(): void
    {
        $result = $this->matcher->score(
            ['name' => 'John Smith', 'dob' => '1985-01-01'],
            ['name' => 'John Smith', 'dob' => '1965-06-12'],
        );

        $this->assertFalse($result['reportable']);
        $this->assertContains('contradicts', array_column($result['basis'], 'effect'));
    }

    public function test_the_basis_is_recorded_so_an_adjudicator_can_check_the_reasoning(): void
    {
        $result = $this->matcher->score(
            ['name' => 'Thabo Nkosi', 'dob' => '1980-04-04', 'nationality' => 'ZA'],
            ['name' => 'Thabo Nkosi', 'dob' => '1980-04-04', 'nationality' => 'ZA'],
        );

        $factors = array_column($result['basis'], 'factor');
        $this->assertContains('name', $factors);
        $this->assertContains('date_of_birth', $factors);
        $this->assertContains('nationality', $factors);
        // Every entry says what it meant, not just that it was considered.
        $this->assertNotEmpty($result['basis'][0]['detail']);
    }

    public function test_unrelated_names_are_not_reported(): void
    {
        $this->assertFalse($this->matcher->score(['name' => 'Thabo Nkosi'], ['name' => 'Vladimir Petrov'])['reportable']);
    }

    public function test_ranking_drops_what_is_not_worth_a_persons_time(): void
    {
        $ranked = $this->matcher->rank(['name' => 'Thabo Nkosi'], [
            ['name' => 'Thabo Nkosi'],
            ['name' => 'Completely Unrelated Person'],
            ['name' => 'T Nkosi'],
        ]);

        $this->assertCount(2, $ranked);
        $this->assertEquals('Thabo Nkosi', $ranked[0]['name'], 'The best match should be first.');
        $this->assertArrayHasKey('match_basis', $ranked[0]);
    }

    // ── Screening a supplier ────────────────────────────────────────────────

    public function test_everyone_connected_to_the_supplier_is_screened(): void
    {
        $supplier = $this->supplier();
        SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'Ann', 'surname' => 'Director', 'role' => 'director']);
        SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'Ben', 'surname' => 'Owner', 'role' => 'ubo']);
        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Old', 'surname' => 'Director',
            'role' => 'director', 'resigned_at' => now()->subYear(),
        ]);

        $subjects = $this->screening->subjects($supplier);

        // The company plus its two current people. Somebody who resigned is not a current officer.
        $this->assertCount(3, $subjects);
        $this->assertEquals('supplier', $subjects[0]['type']);
        $this->assertNotContains('Old Director', array_column($subjects, 'name'));
    }

    public function test_a_match_becomes_an_open_hit_carrying_why_it_matched(): void
    {
        $supplier = $this->supplier();
        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Vladimir', 'surname' => 'Petrov',
            'role' => 'director', 'nationality' => 'RU',
        ]);

        $this->stubProvider([
            ['name' => 'Vladimir Petrov', 'nationality' => 'RU', 'list_name' => 'FIC TFS', 'external_ref' => 'TFS-991'],
        ]);

        $result = $this->screening->screen($supplier);

        $this->assertFalse($result['unavailable']);
        $this->assertEquals(1, $result['new_hits']);

        $hit = ScreeningHit::where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertEquals(ScreeningHit::STATUS_OPEN, $hit->status);
        $this->assertEquals('FIC TFS', $hit->list_name);
        $this->assertEquals('person', $hit->subject_type);
        $this->assertGreaterThanOrEqual(70, $hit->match_score);
        $this->assertNotEmpty($hit->match_basis);
    }

    public function test_screening_with_no_provider_records_the_gap_instead_of_reporting_clear(): void
    {
        $supplier = $this->supplier();

        $result = $this->screening->screen($supplier);

        $this->assertTrue($result['unavailable']);
        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'check_type' => CheckType::SCREENING_SANCTIONS,
            'status' => VerificationStatus::UNAVAILABLE,
        ]);
        $this->assertEquals(0, ScreeningHit::where('supplier_id', $supplier->id)->count());
    }

    // ── Memory: the property the whole feature rests on ─────────────────────

    public function test_a_cleared_hit_does_not_come_back_next_month(): void
    {
        $supplier = $this->supplier();
        SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'John', 'surname' => 'Smith', 'role' => 'director']);

        $this->stubProvider([['name' => 'John Smith', 'list_name' => 'Some watchlist']]);

        $this->screening->screen($supplier);
        $hit = ScreeningHit::where('supplier_id', $supplier->id)->firstOrFail();

        $this->screening->adjudicate($hit, ScreeningHit::STATUS_FALSE_POSITIVE, 'Different John Smith — date of birth and ID both differ.');

        // Next month's run sees the same name again.
        $second = $this->screening->screen($supplier);

        $this->assertEquals(0, $second['new_hits'], 'A cleared hit was raised again.');
        $this->assertEquals(1, $second['seen_again']);
        $this->assertEquals(1, ScreeningHit::where('supplier_id', $supplier->id)->count());

        $hit->refresh();
        $this->assertEquals(ScreeningHit::STATUS_FALSE_POSITIVE, $hit->status, 'An adjudication was overwritten by a re-screen.');
        $this->assertEquals(2, $hit->times_seen);
        $this->assertNotNull($hit->adjudicated_at);
    }

    public function test_a_genuinely_new_name_still_arrives_as_open(): void
    {
        $supplier = $this->supplier();
        SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'John', 'surname' => 'Smith', 'role' => 'director']);

        $this->stubProvider([['name' => 'John Smith']]);
        $this->screening->screen($supplier);
        ScreeningHit::where('supplier_id', $supplier->id)->firstOrFail()
            ->adjudicate(ScreeningHit::STATUS_FALSE_POSITIVE, $this->user->id, 'Not our director.');

        // A second designation for the same person appears on the list later — lists carry aliases
        // as separate entries, so this is a new row to adjudicate, not the one already cleared.
        app(ProviderRegistry::class)->forget('screening_complyadvantage');
        TenantVerificationProvider::query()->where('provider_code', 'screening_complyadvantage')->delete();
        $this->stubProvider([['name' => 'John Smith'], ['name' => 'SMITH, John']]);

        $result = $this->screening->screen($supplier);

        $this->assertEquals(1, $result['new_hits'], 'A new designation must not be swallowed by the dedupe.');
        $this->assertEquals(1, ScreeningHit::where('supplier_id', $supplier->id)->unadjudicated()->count());
    }

    public function test_clearing_a_hit_requires_a_reason(): void
    {
        $supplier = $this->supplier();
        $hit = $this->screening->recordHit($supplier, ScreeningHit::TYPE_SANCTIONS, 'ABC Holdings', listName: 'FIC TFS');

        $this->expectException(\InvalidArgumentException::class);

        // An unexplained dismissal cannot be told apart from a mis-click a year later.
        $this->screening->adjudicate($hit, ScreeningHit::STATUS_FALSE_POSITIVE, '  ');
    }

    public function test_confirming_a_hit_needs_no_note_and_is_attributed(): void
    {
        $supplier = $this->supplier();
        $hit = $this->screening->recordHit($supplier, ScreeningHit::TYPE_SANCTIONS, 'ABC Holdings', listName: 'FIC TFS');

        $this->screening->adjudicate($hit, ScreeningHit::STATUS_CONFIRMED);

        $hit->refresh();
        $this->assertEquals(ScreeningHit::STATUS_CONFIRMED, $hit->status);
        $this->assertEquals($this->user->id, $hit->adjudicated_by);
        $this->assertNotNull($hit->adjudicated_at);
    }

    public function test_a_hit_can_be_recorded_by_hand_from_a_published_list(): void
    {
        $supplier = $this->supplier();
        $person = SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'Named', 'surname' => 'Person', 'role' => 'director']);

        // The FIC list is published; a licensed feed is a procurement exercise. The manual path is
        // what makes screening possible today rather than after a contract is signed.
        $hit = $this->screening->recordHit(
            supplier: $supplier,
            listType: ScreeningHit::TYPE_SANCTIONS,
            matchedName: 'NAMED PERSON',
            subjectType: 'person',
            subjectId: $person->id,
            listName: 'FIC Targeted Financial Sanctions',
            details: ['designated' => '2024-03-01'],
        );

        $this->assertEquals('manual', $hit->provider_code);
        $this->assertEquals(ScreeningHit::STATUS_OPEN, $hit->status);
        $this->assertEquals('Named Person', $hit->subject_name);
        $this->assertEquals(['designated' => '2024-03-01'], $hit->details);
    }

    public function test_hits_are_tenant_scoped(): void
    {
        $supplier = $this->supplier();
        $this->screening->recordHit($supplier, ScreeningHit::TYPE_SANCTIONS, 'ABC Holdings');

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->makeCompany('Other Tenant'));

        $this->assertEquals(0, ScreeningHit::count());
    }
}
