<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\DueDiligence\Services\EntityResolver;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\ReputationMonitor;
use App\Modules\SupplierManagement\DueDiligence\Services\ReputationScore;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierFinding;
use App\Modules\SupplierManagement\Models\SupplierHandle;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;

/**
 * Reputation and social monitoring.
 *
 * The failure this whole subsystem is built against is not "we missed a story" — it is the opposite.
 * Search a supplier's name, show everything that comes back, and by month two a procurement officer
 * has read forty articles about a differently-owned company with a similar name and stopped opening
 * the alerts. The one real story then lands in a queue nobody reads.
 *
 * So the tests that matter here are about restraint: a mention that cannot be tied to this supplier
 * must not reach the score, a positive story must not be filed as a risk, a cleared finding must not
 * come back next month, and fifteen complaints must not outweigh one regulator.
 */
class ReputationMonitoringTest extends SupplierTestCase
{
    private ReputationMonitor $monitor;

    private ReputationScore $reputation;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->monitor = app(ReputationMonitor::class);
        $this->reputation = app(ReputationScore::class);
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_name' => 'Zolani Civils (Pty) Ltd',
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'website_url' => 'https://zolanicivils.co.za',
            'physical_address' => '14 Long Street, Gqeberha, South Africa',
            'is_active' => 1,
        ], $attributes));
    }

    /** A news/social provider that returns a fixed list of mentions. */
    private function stubSource(array $mentions, string $checkType = CheckType::MEDIA_ADVERSE, string $code = 'media_newsapi'): void
    {
        TenantVerificationProvider::query()->firstOrCreate(
            ['provider_code' => $code],
            ['is_enabled' => true, 'credentials' => ['api_key' => 'k'], 'enabled_checks' => [$checkType]]
        );

        app(ProviderRegistry::class)->fake($code, new class($code, $checkType, $mentions) implements VerificationProvider
        {
            public function __construct(private string $code, private string $checkType, private array $mentions) {}

            public function code(): string
            {
                return $this->code;
            }

            public function capabilities(): array
            {
                return [$this->checkType];
            }

            public function supports(string $checkType): bool
            {
                return $checkType === $this->checkType;
            }

            public function verify(VerificationRequest $request): VerificationResult
            {
                return new VerificationResult(
                    status: VerificationStatus::VERIFIED,
                    observed: ['mentions' => $this->mentions],
                    summary: 'Searched.',
                );
            }

            public function health(): ProviderHealth
            {
                return ProviderHealth::ok();
            }
        });
    }

    // ── Entity resolution: the defence that makes this usable ───────────────

    public function test_a_registration_number_in_the_text_settles_attribution(): void
    {
        $supplier = $this->supplier(['company_registration_number' => '2019/445566/07']);

        $result = app(EntityResolver::class)->resolve($supplier, [
            'title' => 'Contractor 2019/445566/07 investigated over tender',
            'excerpt' => 'The company faces questions about a municipal award.',
        ]);

        $this->assertTrue($result['strong']);
        $this->assertContains('registration_number', array_column($result['basis'], 'factor'));
    }

    public function test_a_different_registration_number_contradicts_a_name_match(): void
    {
        $supplier = $this->supplier(['company_registration_number' => '2019/445566/07']);

        $result = app(EntityResolver::class)->resolve($supplier, [
            'title' => 'Zolani Civils accused of overcharging',
            'excerpt' => 'Zolani Civils (2005/111222/23), a different entity, is under review.',
        ]);

        $this->assertFalse($result['attributable'],
            'A same-name company with a different registration number was attributed to this supplier.');
        $this->assertContains('contradicts', array_column($result['basis'], 'effect'));
    }

    public function test_a_generic_company_name_alone_is_not_enough_to_attribute(): void
    {
        $supplier = $this->supplier(['company_name' => 'National Supply Services', 'website_url' => null]);

        $result = app(EntityResolver::class)->resolve($supplier, [
            'title' => 'National Supply Services under fire',
            'excerpt' => 'Complaints mount against the firm.',
        ]);

        // A name that would match half the register is barely evidence.
        $this->assertFalse($result['attributable']);
    }

    public function test_a_distinctive_name_with_a_director_named_is_attributed(): void
    {
        $supplier = $this->supplier();
        SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Nomsa', 'surname' => 'Dlamini', 'role' => 'director',
        ]);

        $result = app(EntityResolver::class)->resolve($supplier, [
            'title' => 'Zolani Civils director Nomsa Dlamini responds to claims',
            'excerpt' => 'The company says it will cooperate.',
        ]);

        $this->assertTrue($result['attributable']);
        $this->assertContains('director', array_column($result['basis'], 'factor'));
    }

    public function test_the_supplier_domain_is_near_proof(): void
    {
        $supplier = $this->supplier();

        $result = app(EntityResolver::class)->resolve($supplier, [
            'title' => 'Statement issued',
            'excerpt' => 'Published on zolanicivils.co.za earlier today.',
            'url' => 'https://news.example/story',
        ]);

        $this->assertTrue($result['attributable']);
    }

    // ── The pipeline ────────────────────────────────────────────────────────

    public function test_a_run_records_what_it_searched_even_when_nothing_is_found(): void
    {
        $supplier = $this->supplier();
        $this->stubSource([]);

        $run = $this->monitor->run($supplier, 'news', 'onboarding');

        // The empty runs are the point: "we were monitoring" is an assertion, a run record is evidence.
        $this->assertEquals(0, $run->findings_new);
        $this->assertEquals(1, $run->sources_queried);
        $this->assertNotNull($run->completed_at);
        $this->assertEquals('onboarding', $run->trigger);
        $this->assertContains($supplier->company_name, $run->subjects);
    }

    public function test_monitoring_runs_during_onboarding_not_only_after_approval(): void
    {
        // is_active = 0 is a supplier still being onboarded. The reputation of a company you are
        // about to engage is exactly as relevant as one you already have.
        $supplier = $this->supplier(['is_active' => 0]);
        $this->stubSource([[
            'title' => 'Zolani Civils (2019/445566/07) faces tender probe',
            'url' => 'https://news24.com/story-1',
            'source_name' => 'News24',
            'published_at' => now()->subDays(3)->toIso8601String(),
        ]]);

        $run = $this->monitor->run($supplier, 'news', 'onboarding');

        $this->assertGreaterThan(0, $run->mentions_seen);
    }

    public function test_an_unattributable_mention_goes_to_review_and_not_to_the_score(): void
    {
        $supplier = $this->supplier(['company_name' => 'National Supply Services', 'website_url' => null]);
        $this->stubSource([[
            'title' => 'National Supply Services in fraud probe',
            'url' => 'https://news.example/other-company',
            'source_name' => 'Some Blog',
        ]]);

        $run = $this->monitor->run($supplier, 'news');

        $this->assertEquals(1, $run->below_confidence_floor);
        $this->assertEquals(0, $run->findings_new);

        $finding = SupplierFinding::where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertEquals(SupplierFinding::STATUS_NEEDS_REVIEW, $finding->status);
        // Not classified either — no point spending a model call on somebody else's bad news.
        $this->assertNull($finding->category);
        $this->assertLessThan(EntityResolver::CONFIDENCE_FLOOR, $finding->entity_confidence);
    }

    public function test_the_same_article_seen_twice_is_one_finding_and_keeps_its_verdict(): void
    {
        $supplier = $this->supplier();
        $mention = [
            'title' => 'Zolani Civils director Nomsa Dlamini responds',
            'url' => 'https://news24.com/story-2',
            'source_name' => 'News24',
        ];
        SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'Nomsa', 'surname' => 'Dlamini', 'role' => 'director']);

        $this->stubSource([$mention]);
        $this->monitor->run($supplier, 'news');

        $finding = SupplierFinding::where('supplier_id', $supplier->id)->firstOrFail();
        $finding->adjudicate(SupplierFinding::STATUS_FALSE_POSITIVE, $this->user->id, 'Different Zolani Civils in Gauteng.');

        // Next month, the same story — with a tracking parameter, as it always is.
        $this->stubSource([array_merge($mention, ['url' => 'https://news24.com/story-2?utm_source=newsletter'])]);
        $second = $this->monitor->run($supplier, 'news');

        $this->assertEquals(0, $second->findings_new);
        $this->assertEquals(1, $second->findings_updated);
        $this->assertEquals(1, SupplierFinding::where('supplier_id', $supplier->id)->count());
        $this->assertEquals(SupplierFinding::STATUS_FALSE_POSITIVE, $finding->fresh()->status,
            'A cleared finding was re-raised by the next run.');
        // Seeing it again does raise corroboration, which is a real signal.
        $this->assertEquals(1, $finding->fresh()->corroborations);
    }

    public function test_a_source_that_is_not_licensed_is_recorded_as_unavailable(): void
    {
        $supplier = $this->supplier();

        // No social provider configured — a supplier nobody could search must not look like one
        // nothing was found about.
        $run = $this->monitor->run($supplier, 'social');

        $this->assertEquals(1, $run->sources_unavailable);
        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'check_type' => CheckType::SOCIAL_REPUTATION,
            'status' => VerificationStatus::UNAVAILABLE,
        ]);
    }

    public function test_brands_handles_and_people_are_all_searched(): void
    {
        $supplier = $this->supplier(['trading_name' => 'Zolani Roads']);
        SupplierHandle::create(['supplier_id' => $supplier->id, 'kind' => 'brand', 'value' => 'ZC Plant Hire']);
        SupplierHandle::create(['supplier_id' => $supplier->id, 'kind' => 'handle', 'platform' => 'x', 'value' => '@zolanicivils']);
        SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'Nomsa', 'surname' => 'Dlamini', 'role' => 'director']);

        $subjects = $this->monitor->subjects($supplier)->pluck('value');

        // The story that matters usually names a brand or a person, not the entity on the invoice.
        $this->assertContains('Zolani Civils (Pty) Ltd', $subjects);
        $this->assertContains('Zolani Roads', $subjects);
        $this->assertContains('ZC Plant Hire', $subjects);
        $this->assertContains('@zolanicivils', $subjects);
        $this->assertContains('Nomsa Dlamini', $subjects);
    }

    // ── Scoring ─────────────────────────────────────────────────────────────

    private function finding(Supplier $supplier, array $attributes = []): SupplierFinding
    {
        return SupplierFinding::create(array_merge([
            'supplier_id' => $supplier->id,
            'channel' => 'news',
            'source_name' => 'News24',
            'url' => 'https://news24.com/'.uniqid(),
            'title' => 'A story',
            'category' => 'litigation',
            'severity' => 'medium',
            'sentiment' => 'negative',
            'entity_confidence' => 90,
            'analysis_confidence' => 80,
            'status' => SupplierFinding::STATUS_OPEN,
            'published_at' => now()->subDays(10),
        ], $attributes));
    }

    public function test_one_regulator_outweighs_many_complaints(): void
    {
        $complaints = $this->supplier();
        for ($i = 0; $i < 15; $i++) {
            $this->finding($complaints, ['category' => 'customer_complaints', 'severity' => 'low', 'channel' => 'social']);
        }

        $regulator = $this->supplier();
        $this->finding($regulator, ['category' => 'regulatory', 'severity' => 'high', 'channel' => 'regulatory', 'source_name' => 'gov.za']);

        $this->assertGreaterThan(
            $this->reputation->score($complaints),
            $this->reputation->score($regulator),
            'Volume of grumbling outscored a regulator acting — this is the failure the weighting exists to prevent.'
        );
    }

    public function test_a_positive_story_is_kept_and_does_not_raise_risk(): void
    {
        $supplier = $this->supplier();
        $this->finding($supplier, [
            'category' => 'positive', 'severity' => 'none', 'sentiment' => 'positive',
            'is_positive' => true, 'title' => 'Zolani Civils wins R100m contract',
        ]);

        $result = $this->reputation->for($supplier);

        $this->assertEquals(1, $result['findings']);
        $this->assertEquals(0, $result['negative']);
        $this->assertEquals(0.0, $result['score'], 'A contract win was scored as a risk.');
    }

    public function test_an_old_story_counts_less_than_a_recent_one_but_never_nothing(): void
    {
        $recent = $this->supplier();
        $this->finding($recent, ['category' => 'fraud', 'severity' => 'high', 'published_at' => now()->subMonth()]);

        $old = $this->supplier();
        $this->finding($old, ['category' => 'fraud', 'severity' => 'high', 'published_at' => now()->subYears(6)]);

        $recentScore = $this->reputation->score($recent);
        $oldScore = $this->reputation->score($old);

        $this->assertGreaterThan($oldScore, $recentScore);
        $this->assertGreaterThan(0, $oldScore, 'A supplier must not outlive its own record entirely.');
    }

    public function test_a_finding_we_are_barely_sure_about_counts_proportionally_less(): void
    {
        $certain = $this->supplier();
        $this->finding($certain, ['category' => 'fraud', 'severity' => 'high', 'entity_confidence' => 100]);

        $unsure = $this->supplier();
        $this->finding($unsure, ['category' => 'fraud', 'severity' => 'high', 'entity_confidence' => 62]);

        $this->assertGreaterThan($this->reputation->score($unsure), $this->reputation->score($certain));
    }

    public function test_a_credible_source_outweighs_an_anonymous_one(): void
    {
        $credible = $this->supplier();
        $this->finding($credible, ['category' => 'corruption', 'severity' => 'high', 'source_name' => 'Reuters', 'channel' => 'news']);

        $anonymous = $this->supplier();
        $this->finding($anonymous, ['category' => 'corruption', 'severity' => 'high', 'source_name' => 'anon forum', 'channel' => 'forum']);

        $this->assertGreaterThan($this->reputation->score($anonymous), $this->reputation->score($credible));
    }

    public function test_no_coverage_is_reported_as_no_coverage_not_as_a_clean_record(): void
    {
        $result = $this->reputation->for($this->supplier());

        $this->assertFalse($result['coverage']);
        $this->assertEquals(0.0, $result['score']);

        // And the risk engine must not read that as good news.
        $component = app(RiskEngine::class)->components($this->supplier())[RiskModel::REPUTATION];
        $this->assertFalse($component['covered']);
    }

    public function test_the_score_names_what_is_driving_it(): void
    {
        $supplier = $this->supplier();
        $this->finding($supplier, ['category' => 'bribery', 'severity' => 'critical', 'title' => 'Bribery allegation reported']);
        $this->finding($supplier, ['category' => 'customer_complaints', 'severity' => 'low']);

        $result = $this->reputation->for($supplier);

        $this->assertNotEmpty($result['drivers']);
        $this->assertEquals('Bribery', $result['drivers'][0]['category'], 'The worst finding should lead.');
        $this->assertStringContainsString('Bribery allegation', $result['drivers'][0]['title']);
    }

    public function test_many_findings_do_not_pin_every_supplier_at_one_hundred(): void
    {
        $supplier = $this->supplier();
        for ($i = 0; $i < 12; $i++) {
            $this->finding($supplier, ['category' => 'litigation', 'severity' => 'medium']);
        }

        // Diminishing returns: ten medium findings are worse than one, and not ten times worse.
        // A straight sum makes the number useless for ranking anything.
        $this->assertLessThan(100.0, $this->reputation->score($supplier));
    }

    // ── Alerting ────────────────────────────────────────────────────────────

    public function test_serious_unreviewed_findings_raise_one_flag_not_forty(): void
    {
        $supplier = $this->supplier();
        SupplierPerson::create(['supplier_id' => $supplier->id, 'first_name' => 'Nomsa', 'surname' => 'Dlamini', 'role' => 'director']);

        $this->finding($supplier, ['category' => 'fraud', 'severity' => 'critical']);
        $this->finding($supplier, ['category' => 'corruption', 'severity' => 'high']);
        $this->stubSource([]);

        $this->monitor->run($supplier, 'news');

        $flags = SupplierRiskFlag::where('supplier_id', $supplier->id)->where('type', 'reputation_findings')->get();

        $this->assertCount(1, $flags, 'A queue of forty flags is a queue nobody works.');
        $this->assertEquals('critical', $flags->first()->severity);
        $this->assertStringContainsString('2 reputation finding', $flags->first()->message);
    }

    public function test_working_through_the_findings_clears_the_flag(): void
    {
        $supplier = $this->supplier();
        $finding = $this->finding($supplier, ['category' => 'fraud', 'severity' => 'critical']);
        $this->stubSource([]);

        $this->monitor->run($supplier, 'news');
        $this->assertEquals(1, SupplierRiskFlag::where('supplier_id', $supplier->id)->open()->count());

        $finding->adjudicate(SupplierFinding::STATUS_FALSE_POSITIVE, $this->user->id, 'Different company.');
        $this->monitor->run($supplier, 'news');

        // A supplier that has been worked through must stop looking like one that has not.
        $this->assertEquals(0, SupplierRiskFlag::where('supplier_id', $supplier->id)
            ->where('type', 'reputation_findings')->open()->count());
    }

    public function test_findings_are_tenant_scoped(): void
    {
        $supplier = $this->supplier();
        $this->finding($supplier);

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->makeCompany('Other Tenant'));

        $this->assertEquals(0, SupplierFinding::count());
    }

    public function test_the_supplier_page_carries_the_timeline_and_the_passes(): void
    {
        $supplier = $this->supplier();
        $this->finding($supplier, ['category' => 'fraud', 'severity' => 'high', 'title' => 'Fraud probe reported']);
        SupplierHandle::create(['supplier_id' => $supplier->id, 'kind' => 'brand', 'value' => 'Zolani Roads']);
        $this->stubSource([]);
        $this->monitor->run($supplier, 'news');

        $this->withoutVite()
            ->get("/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $reputation = $page->toArray()['props']['reputation'];

                $this->assertTrue($reputation['score']['coverage']);
                $this->assertCount(1, $reputation['findings']);
                $this->assertEquals('Zolani Roads', $reputation['handles'][0]['value']);
                // The passes are shown including the ones that found nothing — evidence, not assertion.
                $this->assertNotEmpty($reputation['runs']);

                // Provenance travels with every finding: was this a machine's reading or a person's,
                // and how sure are we it is even the right company.
                $finding = $reputation['findings'][0];
                $this->assertArrayHasKey('ai_generated', $finding);
                $this->assertArrayHasKey('entity_confidence', $finding);
                $this->assertNotEmpty($finding['category_label']);
            });
    }

    public function test_clearing_a_finding_through_the_route_requires_a_reason(): void
    {
        $supplier = $this->supplier();
        $finding = $this->finding($supplier, ['category' => 'fraud', 'severity' => 'high']);

        $this->patch("/suppliers/{$supplier->uuid}/reputation/findings/{$finding->id}", [
            'status' => SupplierFinding::STATUS_FALSE_POSITIVE,
        ])->assertSessionHasErrors('note');

        $this->assertEquals(SupplierFinding::STATUS_OPEN, $finding->fresh()->status);

        $this->patch("/suppliers/{$supplier->uuid}/reputation/findings/{$finding->id}", [
            'status' => SupplierFinding::STATUS_FALSE_POSITIVE,
            'note' => 'Different company — the registration number in the article is not ours.',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(SupplierFinding::STATUS_FALSE_POSITIVE, $finding->fresh()->status);
    }

    public function test_a_person_correcting_the_reading_takes_it_off_the_machine(): void
    {
        $supplier = $this->supplier();
        $finding = $this->finding($supplier, ['category' => 'fraud', 'severity' => 'critical', 'ai_generated' => true]);

        $this->patch("/suppliers/{$supplier->uuid}/reputation/findings/{$finding->id}", [
            'status' => SupplierFinding::STATUS_CONFIRMED,
            'category' => 'litigation',
            'severity' => 'medium',
        ])->assertSessionHasNoErrors();

        $finding->refresh();
        $this->assertEquals('litigation', $finding->category);
        $this->assertEquals('medium', $finding->severity);
        // The provenance of every field has to stay honest: this is now a person's reading.
        $this->assertFalse($finding->ai_generated);
    }

    public function test_a_finding_belonging_to_another_supplier_cannot_be_adjudicated_here(): void
    {
        $mine = $this->supplier();
        $theirs = $this->supplier();
        $finding = $this->finding($theirs);

        $this->patch("/suppliers/{$mine->uuid}/reputation/findings/{$finding->id}", [
            'status' => SupplierFinding::STATUS_CONFIRMED,
        ])->assertNotFound();
    }
}
