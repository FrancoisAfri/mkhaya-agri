<?php

namespace Tests\Feature\SupplierManagement;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\DueDiligence\Services\AlertRouter;
use App\Modules\SupplierManagement\DueDiligence\Services\DueDiligenceSummary;
use App\Modules\SupplierManagement\DueDiligence\Services\HealthScore;
use App\Modules\SupplierManagement\DueDiligence\Services\QuestionnaireService;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Services\RoleMap;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\QuestionnaireTemplates;
use App\Modules\SupplierManagement\DueDiligence\Support\RoleSlot;
use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\DueDiligenceQuestionnaire;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierScar;
use App\Modules\SupplierManagement\Notifications\SupplierAlertNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The tail: questionnaires, alert routing, health, the written summary, cross-border sources.
 *
 * The through-line is the same one as everywhere else in this module — a thing that has not been
 * done must not read as a thing that came back clean. An unanswered questionnaire, an alert routed
 * to nobody, and a summary that omits its gaps are all versions of the same failure.
 */
class DueDiligenceTailTest extends SupplierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_name' => 'Zolani Civils (Pty) Ltd',
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'is_active' => 1,
        ], $attributes));
    }

    // ── Questionnaires ──────────────────────────────────────────────────────

    public function test_the_packs_a_supplier_gets_depend_on_the_engagement(): void
    {
        // Asking a stationery supplier nine ESG questions produces nine unread answers and one
        // supplier who stops replying to procurement.
        $this->assertEquals([QuestionnaireTemplates::GOVERNANCE], QuestionnaireTemplates::forTier(2));

        $tier4 = QuestionnaireTemplates::forTier(4);
        $this->assertContains(QuestionnaireTemplates::CYBER, $tier4);
        $this->assertContains(QuestionnaireTemplates::ESG, $tier4);
    }

    public function test_a_pack_is_sent_with_its_questions_copied_onto_it(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);

        $questionnaire = app(QuestionnaireService::class)->send($supplier, QuestionnaireTemplates::CYBER);

        $this->assertEquals(DueDiligenceQuestionnaire::STATUS_SENT, $questionnaire->status);
        $this->assertGreaterThan(5, $questionnaire->answers()->count());
        // Copied, not referenced: a pack answered in March still reads as the pack that was asked
        // after somebody edits the template in June.
        $this->assertNotNull($questionnaire->answers()->first()->question);
        $this->assertNotNull($questionnaire->due_on);
    }

    public function test_sending_the_same_pack_twice_does_not_duplicate_it(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);
        $service = app(QuestionnaireService::class);

        $first = $service->send($supplier, QuestionnaireTemplates::ESG);
        $second = $service->send($supplier, QuestionnaireTemplates::ESG);

        $this->assertEquals($first->id, $second->id);
    }

    public function test_a_flagged_answer_is_decided_by_the_template_not_the_reader(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);
        $service = app(QuestionnaireService::class);
        $questionnaire = $service->send($supplier, QuestionnaireTemplates::GOVERNANCE);

        $questionnaire = $service->record($questionnaire, [
            'anti_bribery_policy' => 'no',       // flagged: high
            'prior_debarment' => 'yes',          // flagged: critical
            'whistleblower' => 'yes',            // not flagged
            'subcontracting' => 'Two electrical subcontractors.',
        ]);

        $flags = collect($questionnaire->flags);

        $this->assertTrue($flags->pluck('code')->contains('anti_bribery_policy'));
        $this->assertTrue($flags->pluck('code')->contains('prior_debarment'));
        $this->assertFalse($flags->pluck('code')->contains('whistleblower'));
        // The worst flag sets the floor, so one critical answer outranks several minor ones.
        $this->assertGreaterThanOrEqual(100.0, (float) $questionnaire->risk_score);
    }

    public function test_leaving_the_hard_questions_blank_is_not_a_clean_questionnaire(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);
        $service = app(QuestionnaireService::class);

        $answered = $service->send($supplier, QuestionnaireTemplates::GOVERNANCE);
        $service->record($answered, [
            'anti_bribery_policy' => 'yes', 'whistleblower' => 'yes', 'conflict_policy' => 'yes',
            'related_to_buyer' => 'no', 'political_office' => 'no', 'prior_debarment' => 'no',
            'ownership_declaration' => 'yes', 'subcontracting' => 'None.',
        ], submit: true);

        $other = $this->supplier(['risk_tier' => 4, 'company_name' => 'Half Answered Co']);
        $blank = $service->send($other, QuestionnaireTemplates::GOVERNANCE);
        $service->record($blank, ['whistleblower' => 'yes'], submit: true);

        // Otherwise the route to a clean questionnaire is to leave the hard ones blank.
        $this->assertGreaterThan(
            (float) $answered->fresh()->risk_score,
            (float) $blank->fresh()->risk_score,
        );
        $this->assertEquals(100, $answered->fresh()->completeness);
        $this->assertLessThan(30, $blank->fresh()->completeness);
    }

    public function test_an_outstanding_pack_scores_as_outstanding_not_as_clean(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);
        app(QuestionnaireService::class)->send($supplier, QuestionnaireTemplates::ESG);

        $position = app(QuestionnaireService::class)->position($supplier->fresh());

        $this->assertFalse($position['covered']);
        $this->assertGreaterThan(0, $position['score'], 'Silence was scored as a good answer.');
        $this->assertStringContainsString('outstanding', implode(' ', $position['reasons']));
    }

    public function test_the_esg_component_of_risk_now_has_something_behind_it(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);
        $service = app(QuestionnaireService::class);

        $q = $service->send($supplier, QuestionnaireTemplates::ESG);
        $service->record($q, ['child_labour' => 'no'], submit: true);   // critical flag

        $components = app(RiskEngine::class)->components($supplier->fresh());

        $this->assertTrue($components[RiskModel::ESG]['covered']);
        $this->assertGreaterThan(50, $components[RiskModel::ESG]['score']);
    }

    public function test_questionnaires_can_be_sent_and_answered_through_the_routes(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);

        $this->post("/suppliers/{$supplier->uuid}/questionnaires")->assertRedirect()->assertSessionHasNoErrors();

        $questionnaire = DueDiligenceQuestionnaire::where('supplier_id', $supplier->id)->firstOrFail();

        $this->patch("/suppliers/{$supplier->uuid}/questionnaires/{$questionnaire->id}", [
            'answers' => ['anti_bribery_policy' => 'yes'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, $questionnaire->fresh()->completeness);
    }

    public function test_a_questionnaire_belonging_to_another_supplier_cannot_be_answered_here(): void
    {
        $mine = $this->supplier(['risk_tier' => 4]);
        $theirs = $this->supplier(['risk_tier' => 4, 'company_name' => 'Other Co']);
        $questionnaire = app(QuestionnaireService::class)->send($theirs, QuestionnaireTemplates::GOVERNANCE);

        $this->patch("/suppliers/{$mine->uuid}/questionnaires/{$questionnaire->id}", [
            'answers' => ['anti_bribery_policy' => 'no'],
        ])->assertNotFound();
    }

    // ── Alert routing ───────────────────────────────────────────────────────

    public function test_severity_decides_who_is_told(): void
    {
        $officer = $this->makeUser($this->companyId);
        $manager = $this->makeUser($this->companyId);
        $compliance = $this->makeUser($this->companyId);
        $executive = $this->makeUser($this->companyId);

        app(RoleMap::class)->save([
            RoleSlot::PROCUREMENT_OFFICER => ['users' => [$officer->id], 'roles' => []],
            RoleSlot::PROCUREMENT_MANAGER => ['users' => [$manager->id], 'roles' => []],
            RoleSlot::COMPLIANCE_OFFICER => ['users' => [$compliance->id], 'roles' => []],
            RoleSlot::EXECUTIVE => ['users' => [$executive->id], 'roles' => []],
        ]);

        $router = app(AlertRouter::class);

        // Low goes to the person who owns the supplier; critical goes up.
        $this->assertEquals([$officer->id], $router->recipients('low')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$compliance->id, $executive->id],
            $router->recipients('critical')->pluck('id')->all()
        );
        // An executive is not told about every changed postal code.
        $this->assertNotContains($executive->id, $router->recipients('low')->pluck('id')->all());
    }

    public function test_an_alert_reaches_the_people_it_routes_to(): void
    {
        $compliance = $this->makeUser($this->companyId);
        app(RoleMap::class)->save([
            RoleSlot::COMPLIANCE_OFFICER => ['users' => [$compliance->id], 'roles' => []],
            RoleSlot::EXECUTIVE => ['users' => [$compliance->id], 'roles' => []],
        ]);

        Notification::fake();
        $supplier = $this->supplier();

        $told = app(AlertRouter::class)->dispatch($supplier, 'change', 'critical', 'Banking details changed.');

        // Deduplicated: one person in two slots is told once, not twice.
        $this->assertEquals(1, $told);
        Notification::assertSentTo($compliance, SupplierAlertNotification::class, function ($n) {
            $this->assertEquals('critical', $n->severity);
            // Mail only for what cannot wait — otherwise a mailbox rule gets written and the
            // critical one lands in the same folder.
            $this->assertContains('mail', $n->via($n->supplier));

            return true;
        });
    }

    public function test_a_low_severity_alert_does_not_send_mail(): void
    {
        $officer = $this->makeUser($this->companyId);
        app(RoleMap::class)->save([RoleSlot::PROCUREMENT_OFFICER => ['users' => [$officer->id], 'roles' => []]]);

        Notification::fake();

        app(AlertRouter::class)->dispatch($this->supplier(), 'change', 'low', 'Postal code changed.');

        Notification::assertSentTo($officer, SupplierAlertNotification::class, function ($n) {
            $this->assertEquals(['database'], $n->via($n->supplier));

            return true;
        });
    }

    public function test_routing_can_be_retuned_and_a_typo_slot_is_dropped(): void
    {
        $router = app(AlertRouter::class);

        $router->save([
            'critical' => [RoleSlot::EXECUTIVE, 'not_a_slot'],
            'high' => [RoleSlot::COMPLIANCE_OFFICER],
            'medium' => [],
            'low' => [],
        ]);

        $this->assertEquals([RoleSlot::EXECUTIVE], $router->slotsFor('critical'));
        // An empty list is a real choice — that severity is switched off.
        $this->assertEquals([], $router->slotsFor('medium'));
        $this->assertEquals(0, $router->recipients('medium')->count());
    }

    // ── Health ──────────────────────────────────────────────────────────────

    public function test_health_and_risk_are_different_questions(): void
    {
        $supplier = $this->supplier();
        $engine = app(VerificationEngine::class);

        foreach ([CheckType::COMPANY_REGISTRY, CheckType::TAX_COMPLIANCE, CheckType::BANK_ACCOUNT] as $check) {
            $engine->recordManual($supplier, $check, VerificationStatus::VERIFIED,
                observed: $engine->claimsFor($supplier, $check), claimed: $engine->claimsFor($supplier, $check));
        }
        app(RiskEngine::class)->assess($supplier->fresh());

        // Low risk, and a delivery record full of corrective actions.
        for ($i = 0; $i < 3; $i++) {
            SupplierScar::create([
                'supplier_id' => $supplier->id, 'status' => 'open',
                'title' => 'Late delivery', 'description' => 'Delivered nine days after the agreed date.',
                'severity' => 'medium',
            ]);
        }

        $health = app(HealthScore::class)->for($supplier->fresh());

        $this->assertTrue($health['covered']);
        // A supplier can be low risk and a nightmare to work with — collapsing both into one number
        // would hide whichever half the reader was not asking about.
        $this->assertLessThan(60, $health['components']['delivery']['score']);
        $this->assertGreaterThan(50, $health['components']['risk']['score']);
    }

    public function test_a_brand_new_supplier_is_not_scored_as_unhealthy_for_being_new(): void
    {
        $health = app(HealthScore::class)->for($this->supplier());

        // Scoring an absent delivery record as poor health tells you about their age, not their
        // performance.
        $this->assertFalse($health['covered']);
        $this->assertEquals('unknown', $health['band']);
    }

    public function test_health_reads_the_opposite_way_round_from_risk(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);

        $health = app(HealthScore::class)->for($supplier->fresh());
        $risk = (float) $supplier->fresh()->risk_score;

        // 82% good is legible in a way that 18/100 bad is not.
        $this->assertEqualsWithDelta(100 - $risk, $health['components']['risk']['score'], 0.5);
    }

    // ── The written summary ─────────────────────────────────────────────────

    public function test_the_summary_is_built_from_facts_and_keeps_every_gap(): void
    {
        $supplier = $this->supplier();
        $engine = app(VerificationEngine::class);

        $engine->recordManual($supplier, CheckType::COMPANY_REGISTRY, VerificationStatus::VERIFIED,
            observed: $engine->claimsFor($supplier, CheckType::COMPANY_REGISTRY),
            claimed: $engine->claimsFor($supplier, CheckType::COMPANY_REGISTRY));
        app(RiskEngine::class)->assess($supplier->fresh());

        $summary = app(DueDiligenceSummary::class)->for($supplier->fresh());

        $this->assertNotEmpty($summary['summary']);
        // The facts travel with the prose so a reader can check one against the other.
        $this->assertArrayHasKey('facts', $summary);
        $this->assertNotEmpty($summary['facts']['gaps']);
        // Every gap survives into the concerns, whatever the model did or did not say.
        foreach ($summary['facts']['gaps'] as $gap) {
            $this->assertContains($gap, $summary['concerns'],
                'A gap was dropped from the summary — a fluent omission is worse than no summary.');
        }
    }

    public function test_the_summary_never_recommends_approval_while_a_gate_is_failing(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);

        $summary = app(DueDiligenceSummary::class)->for($supplier->fresh());

        $this->assertFalse($summary['facts']['auto_approvable']);
        $this->assertNotEmpty($summary['facts']['approval_gates_failing']);
        $this->assertStringNotContainsStringIgnoringCase('may be approved', $summary['recommendation']);
    }

    public function test_the_summary_says_whether_a_machine_wrote_it(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);

        $summary = app(DueDiligenceSummary::class)->for($supplier->fresh());

        // Labelled wherever it appears — a reader has to know what they are reading.
        $this->assertArrayHasKey('ai_generated', $summary);
        $this->assertIsBool($summary['ai_generated']);
    }

    public function test_the_summary_route_returns_it(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);

        $this->getJson("/suppliers/{$supplier->uuid}/summary")
            ->assertOk()
            ->assertJsonStructure(['summary', 'strengths', 'concerns', 'recommendation', 'ai_generated', 'facts']);
    }

    // ── Cross-border ────────────────────────────────────────────────────────

    public function test_eswatini_and_botswana_have_their_own_sources(): void
    {
        $byCountry = collect(ProviderCatalogue::definitions())->groupBy('country');

        // A South African registry cannot answer a question about a Botswana company, so a supplier
        // in Gaborone would otherwise sit permanently at "never checked".
        $this->assertGreaterThanOrEqual(2, $byCountry->get('SZ')?->count() ?? 0);
        $this->assertGreaterThanOrEqual(2, $byCountry->get('BW')?->count() ?? 0);

        $cipa = collect(ProviderCatalogue::definitions())->firstWhere('code', 'bw_cipa');
        $this->assertContains(CheckType::COMPANY_REGISTRY, $cipa['capabilities']);
        $this->assertNotEmpty($cipa['access_note']);
    }

    public function test_the_passport_carries_health_and_questionnaires(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);
        app(QuestionnaireService::class)->send($supplier, QuestionnaireTemplates::GOVERNANCE);
        app(RiskEngine::class)->assess($supplier->fresh());
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}/passport")
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $this->assertArrayHasKey('health', $props);
                $this->assertCount(1, $props['questionnaires']);
                // The packs this tier calls for that nobody has sent are stated, not silently absent.
                $this->assertNotEmpty($props['questionnairesExpected']);
            });
    }

    public function test_questionnaires_are_tenant_scoped(): void
    {
        $supplier = $this->supplier(['risk_tier' => 4]);
        app(QuestionnaireService::class)->send($supplier, QuestionnaireTemplates::GOVERNANCE);

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->makeCompany('Other Tenant'));

        $this->assertEquals(0, DueDiligenceQuestionnaire::count());
    }
}
