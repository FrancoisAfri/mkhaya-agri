<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskTiering;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierSetting;

/**
 * Phase 3: the number, and what it is allowed to authorise.
 *
 * Two properties matter more than any individual weighting.
 *
 * The first is that an unchecked supplier must not score well. A supplier nobody has verified has no
 * failed checks, so a model that scores from what it finds gives them zero and calls them excellent —
 * which is the arithmetic behind every waved-through fraudulent supplier there has ever been.
 *
 * The second is that the score is not the decision. A good total with an unadjudicated sanctions
 * match, a lapsed tax check or a mismatched bank account is not an approval, and no weighting can be
 * allowed to make it one.
 */
class RiskEngineTest extends SupplierTestCase
{
    private RiskEngine $risk;

    private VerificationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->risk = app(RiskEngine::class);
        $this->engine = app(VerificationEngine::class);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'company_name' => 'ABC Holdings (Pty) Ltd',
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'tax_reference_number' => '9012345678',
            'account_holder_name' => 'ABC Holdings',
            'bank_account_number' => '62012345678',
            'is_active' => 1,
        ]);
    }

    /** Record a passing check of the given kind. */
    private function pass(Supplier $supplier, string $checkType): void
    {
        $this->engine->recordManual(
            supplier: $supplier,
            checkType: $checkType,
            status: VerificationStatus::VERIFIED,
            observed: $this->engine->claimsFor($supplier, $checkType),
            claimed: $this->engine->claimsFor($supplier, $checkType),
        );
    }

    /** Everything a supplier needs to be auto-approvable, so a test can take exactly one away. */
    private function fullyVerified(): Supplier
    {
        $supplier = $this->supplier();

        foreach ([CheckType::COMPANY_REGISTRY, CheckType::COMPANY_DIRECTORS, CheckType::BANK_ACCOUNT,
            CheckType::TAX_COMPLIANCE, CheckType::TAX_VAT_VENDOR] as $check) {
            $this->pass($supplier, $check);
        }

        $this->engine->recordManual($supplier, CheckType::SCREENING_SANCTIONS, VerificationStatus::VERIFIED);
        $this->engine->recordManual($supplier, CheckType::OWNERSHIP_BENEFICIAL, VerificationStatus::VERIFIED);

        SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'credit_bureau',
            'score' => 88, 'score_max' => 100, 'payment_behaviour' => 'on_time',
            'report_date' => now()->subDays(10)->toDateString(),
        ]);

        // Ownership resolved to a person, so that component is covered too.
        $root = \App\Modules\SupplierManagement\Models\SupplierEntity::rootFor($supplier);
        $person = \App\Modules\SupplierManagement\Models\SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Sole', 'surname' => 'Owner', 'role' => 'shareholder',
        ]);
        \App\Modules\SupplierManagement\Models\SupplierOwnership::create([
            'supplier_id' => $supplier->id, 'holder_kind' => 'person', 'holder_id' => $person->id,
            'subject_entity_id' => $root->id, 'percentage' => 100, 'as_at' => now()->toDateString(),
        ]);

        return $supplier->fresh();
    }

    // ── Absence is not innocence ────────────────────────────────────────────

    public function test_a_supplier_nobody_has_checked_does_not_score_well(): void
    {
        $score = $this->risk->assess($this->supplier());

        $this->assertGreaterThan(40, (float) $score->total,
            'An unverified supplier scored as though it were safe — this is how a fraudulent supplier gets approved.');
        $this->assertContains($score->band, ['moderate', 'high', 'critical']);
        $this->assertFalse($score->auto_approvable);

        // And the reasons say why, rather than leaving a number to be argued with.
        $identity = $score->components[RiskModel::COMPANY_IDENTITY];
        $this->assertFalse($identity['covered']);
        $this->assertNotEmpty($identity['reasons']);
    }

    public function test_components_that_are_genuinely_uninformative_stay_neutral(): void
    {
        $score = $this->risk->assess($this->supplier());

        // A brand-new supplier has no delivery record and no ESG assessment. Holding that against
        // them would be noise, not diligence.
        $this->assertEquals(0.0, $score->components[RiskModel::ESG]['score']);
        $this->assertEquals(0.0, $score->components[RiskModel::OPERATIONAL]['score']);
        // But identity, tax and screening are not uninformative when missing.
        $this->assertGreaterThan(0, $score->components[RiskModel::TAX]['score']);
        $this->assertGreaterThan(0, $score->components[RiskModel::SANCTIONS_PEP]['score']);
    }

    public function test_verifying_a_supplier_moves_the_score_down_and_the_movement_is_recorded(): void
    {
        $supplier = $this->supplier();
        $before = $this->risk->assess($supplier);

        $this->pass($supplier, CheckType::COMPANY_REGISTRY);
        $this->pass($supplier, CheckType::TAX_COMPLIANCE);
        $after = $this->risk->assess($supplier->fresh());

        $this->assertLessThan((float) $before->total, (float) $after->total);
        $this->assertEquals((float) $before->total, (float) $after->previous_total);
        $this->assertLessThan(0, $after->movement(), 'Movement should be negative when risk falls.');
    }

    public function test_a_stale_verification_is_not_as_good_as_a_fresh_one(): void
    {
        $supplier = $this->supplier();
        $this->pass($supplier, CheckType::TAX_COMPLIANCE);
        $fresh = $this->risk->assess($supplier)->components[RiskModel::TAX]['score'];

        // A tax compliance status is a statement about a moment; a 60-day-old one is a 60-day-old
        // statement, not a current fact.
        \App\Modules\SupplierManagement\Models\SupplierVerification::query()
            ->where('supplier_id', $supplier->id)
            ->update(['valid_until' => now()->subDays(5)]);

        $stale = $this->risk->assess($supplier)->components[RiskModel::TAX]['score'];

        $this->assertGreaterThan($fresh, $stale);
    }

    // ── The score is not the decision ───────────────────────────────────────

    public function test_a_fully_verified_supplier_is_auto_approvable(): void
    {
        $score = $this->risk->assess($this->fullyVerified());

        $this->assertTrue($score->auto_approvable, 'Blockers: '.implode(' | ', $score->blockers ?? []));
        $this->assertLessThan(30, (float) $score->total);
        $this->assertEmpty($score->edd_triggers);
    }

    /**
     * Each gate, removed one at a time. This is the test that matters most in the file: the gates are
     * a conjunction, so every one of them has to be able to withhold approval on its own.
     */
    public function test_removing_any_single_gate_withholds_auto_approval(): void
    {
        $cases = [
            'company registry' => function (Supplier $s) {
                \App\Modules\SupplierManagement\Models\SupplierVerification::query()
                    ->where('supplier_id', $s->id)->check(CheckType::COMPANY_REGISTRY)->delete();
            },
            'tax compliance' => function (Supplier $s) {
                \App\Modules\SupplierManagement\Models\SupplierVerification::query()
                    ->where('supplier_id', $s->id)->check(CheckType::TAX_COMPLIANCE)->delete();
            },
            'bank account' => function (Supplier $s) {
                \App\Modules\SupplierManagement\Models\SupplierVerification::query()
                    ->where('supplier_id', $s->id)->check(CheckType::BANK_ACCOUNT)->delete();
            },
            'unadjudicated screening hit' => function (Supplier $s) {
                ScreeningHit::create([
                    'supplier_id' => $s->id, 'subject_type' => 'supplier', 'subject_name' => $s->company_name,
                    'list_type' => ScreeningHit::TYPE_SANCTIONS, 'matched_name' => 'ABC Holdings',
                    'match_score' => 82, 'provider_code' => 'manual', 'status' => ScreeningHit::STATUS_OPEN,
                    'first_seen_at' => now(), 'last_seen_at' => now(),
                ]);
            },
        ];

        foreach ($cases as $label => $break) {
            $supplier = $this->fullyVerified();
            $this->assertTrue($this->risk->assess($supplier)->auto_approvable, "Baseline failed for: {$label}");

            $break($supplier);

            $score = $this->risk->assess($supplier->fresh());
            $this->assertFalse($score->auto_approvable, "Auto-approval survived removing: {$label}");
            $this->assertNotEmpty($score->blockers);
        }
    }

    public function test_an_unavailable_check_blocks_approval_rather_than_being_skipped(): void
    {
        $supplier = $this->fullyVerified();

        // The source goes away and the check is re-run: it records unavailable, which must not be
        // read as "still verified from last time".
        $this->engine->verify($supplier, CheckType::TAX_COMPLIANCE);

        $score = $this->risk->assess($supplier->fresh());

        $this->assertFalse($score->auto_approvable);
        $this->assertNotEmpty(array_filter($score->blockers, fn ($b) => str_contains($b, 'Tax compliance')));
    }

    public function test_a_confirmed_sanctions_match_tops_the_scale_and_nothing_pulls_it_down(): void
    {
        $supplier = $this->fullyVerified();

        ScreeningHit::create([
            'supplier_id' => $supplier->id, 'subject_type' => 'supplier', 'subject_name' => $supplier->company_name,
            'list_type' => ScreeningHit::TYPE_SANCTIONS, 'list_name' => 'FIC TFS',
            'matched_name' => 'ABC Holdings (Pty) Ltd', 'match_score' => 100,
            'provider_code' => 'manual', 'status' => ScreeningHit::STATUS_CONFIRMED,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $score = $this->risk->assess($supplier->fresh());

        $this->assertEquals(100.0, $score->components[RiskModel::SANCTIONS_PEP]['score']);
        $this->assertFalse($score->auto_approvable);
        $this->assertContains('There is a live sanctions match.', $score->edd_triggers);
    }

    public function test_a_pep_raises_diligence_without_being_treated_as_wrongdoing(): void
    {
        $supplier = $this->fullyVerified();

        ScreeningHit::create([
            'supplier_id' => $supplier->id, 'subject_type' => 'person', 'subject_id' => 1,
            'subject_name' => 'A Director', 'list_type' => ScreeningHit::TYPE_PEP,
            'matched_name' => 'A Director', 'match_score' => 95,
            'provider_code' => 'manual', 'status' => ScreeningHit::STATUS_CONFIRMED,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $score = $this->risk->assess($supplier->fresh());

        // Enhanced diligence, not rejection — and well short of the sanctions score.
        $this->assertContains('A politically exposed person is involved.', $score->edd_triggers);
        $this->assertLessThan(60, $score->components[RiskModel::SANCTIONS_PEP]['score']);
    }

    public function test_a_bank_mismatch_forces_enhanced_due_diligence(): void
    {
        $supplier = $this->fullyVerified();

        $this->engine->recordManual(
            supplier: $supplier,
            checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::MISMATCH,
            observed: ['account_holder_name' => 'Someone Else'],
        );

        $score = $this->risk->assess($supplier->fresh());

        $this->assertContains('The bank account holder does not match the supplier.', $score->edd_triggers);
        $this->assertFalse($score->auto_approvable);
    }

    // ── Credit ──────────────────────────────────────────────────────────────

    public function test_insolvency_dominates_the_financial_component(): void
    {
        $supplier = $this->fullyVerified();

        SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'credit_bureau',
            'score' => 80, 'score_max' => 100,       // a good score, and irrelevant next to this
            'insolvency' => true, 'report_date' => now()->toDateString(),
        ]);

        $score = $this->risk->assess($supplier->fresh());

        $this->assertGreaterThanOrEqual(95, $score->components[RiskModel::FINANCIAL]['score']);
        $this->assertContains('The credit report shows insolvency, business rescue or judgments.', $score->edd_triggers);
    }

    public function test_credit_history_is_kept_so_deterioration_is_visible(): void
    {
        $supplier = $this->supplier();

        SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'credit_bureau',
            'score' => 85, 'score_max' => 100, 'report_date' => now()->subMonths(3)->toDateString(),
        ]);
        SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'credit_bureau',
            'score' => 40, 'score_max' => 100, 'judgments_count' => 2,
            'report_date' => now()->toDateString(),
        ]);

        $this->assertEquals(2, SupplierCreditReport::where('supplier_id', $supplier->id)->count(),
            'A single overwritten row cannot show deterioration.');

        $latest = SupplierCreditReport::latestFor($supplier->id);
        $this->assertEquals(40, $latest->score);
        $this->assertEquals(60.0, $latest->riskScore(), 'A bureau score is creditworthiness; the model wants risk.');
    }

    // ── The model is configurable, and reproducible ─────────────────────────

    public function test_a_tenant_can_retune_the_weights_and_old_scores_stay_explicable(): void
    {
        $supplier = $this->supplier();
        $original = $this->risk->assess($supplier);

        SupplierSetting::query()->firstOrCreate([])->update([
            'risk_weights' => [RiskModel::REPUTATION => 40.0, RiskModel::TAX => 1.0],
        ]);

        $retuned = $this->risk->assess($supplier->fresh());

        $this->assertNotEquals((float) $original->total, (float) $retuned->total);
        // The old snapshot still carries the weights it was computed with, so it can be recomputed
        // and defended rather than merely asserted.
        $this->assertEquals(10.0, $original->weights[RiskModel::TAX]);
        $this->assertEquals(1.0, $retuned->weights[RiskModel::TAX]);
    }

    public function test_the_auto_approval_threshold_is_configurable(): void
    {
        $supplier = $this->fullyVerified();
        $this->assertTrue($this->risk->assess($supplier)->auto_approvable);

        SupplierSetting::query()->firstOrCreate([])->update(['auto_approve_below' => 1.0]);

        $strict = $this->risk->assess($supplier->fresh());
        $this->assertFalse($strict->auto_approvable);
        $this->assertNotEmpty(array_filter($strict->blockers, fn ($b) => str_contains($b, 'threshold')));
    }

    public function test_the_supplier_carries_a_cache_of_the_newest_snapshot(): void
    {
        $supplier = $this->supplier();
        $score = $this->risk->assess($supplier);

        $supplier->refresh();
        $this->assertEquals((float) $score->total, (float) $supplier->risk_score);
        $this->assertEquals($score->band, $supplier->risk_band);
        $this->assertNotNull($supplier->risk_calculated_at);

        // And the snapshot remains the authority.
        $this->assertEquals($score->id, SupplierRiskScore::latestFor($supplier->id)->id);
    }

    public function test_bands_land_on_the_documented_boundaries(): void
    {
        $this->assertEquals('excellent', RiskModel::bandFor(20));
        $this->assertEquals('low', RiskModel::bandFor(20.01));
        $this->assertEquals('low', RiskModel::bandFor(40));
        $this->assertEquals('moderate', RiskModel::bandFor(41));
        $this->assertEquals('high', RiskModel::bandFor(61));
        $this->assertEquals('critical', RiskModel::bandFor(81));
    }

    // ── Tiering ─────────────────────────────────────────────────────────────

    public function test_the_engagement_decides_the_tier_not_the_supplier(): void
    {
        $tiering = app(RiskTiering::class);

        $stationery = $tiering->assess(['annual_spend' => 12_000]);
        $this->assertEquals(2, $stationery['tier']);

        $payroll = $tiering->assess(['annual_spend' => 12_000, 'personal_data' => true, 'system_access' => true]);
        $this->assertEquals(4, $payroll['tier'], 'Access to personal data is a tier 4 engagement whatever the spend.');
        $this->assertContains('Processes personal data', $payroll['reasons']);
    }

    public function test_a_higher_tier_requires_more_checks(): void
    {
        $tiering = app(RiskTiering::class);

        $basic = $tiering->requiredChecks(1);
        $critical = $tiering->requiredChecks(4);

        $this->assertContains(CheckType::COMPANY_REGISTRY, $basic);
        $this->assertNotContains(CheckType::OWNERSHIP_BENEFICIAL, $basic);
        $this->assertContains(CheckType::OWNERSHIP_BENEFICIAL, $critical);
        $this->assertContains(CheckType::MEDIA_ADVERSE, $critical);
        // Cumulative: everything basic requires, critical requires too.
        $this->assertEmpty(array_diff($basic, $critical));
    }

    public function test_outstanding_checks_are_reported_against_the_tier(): void
    {
        $supplier = $this->supplier();
        app(RiskTiering::class)->apply($supplier, ['annual_spend' => 5_000_000, 'personal_data' => true]);

        $supplier->refresh();
        $this->assertEquals(4, $supplier->risk_tier);

        $outstanding = app(RiskTiering::class)->outstandingChecks($supplier);
        $this->assertContains(CheckType::COMPANY_REGISTRY, $outstanding);

        $this->pass($supplier, CheckType::COMPANY_REGISTRY);
        $this->assertNotContains(CheckType::COMPANY_REGISTRY, app(RiskTiering::class)->outstandingChecks($supplier->fresh()));
    }

    public function test_a_tier_four_engagement_forces_enhanced_due_diligence(): void
    {
        $supplier = $this->fullyVerified();
        app(RiskTiering::class)->apply($supplier, ['government_contract' => true]);

        $score = $this->risk->assess($supplier->fresh());

        $this->assertContains('The engagement is tier 4, which requires enhanced due diligence.', $score->edd_triggers);
    }

    public function test_scores_are_tenant_scoped(): void
    {
        $this->risk->assess($this->supplier());

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->makeCompany('Other Tenant'));

        $this->assertEquals(0, SupplierRiskScore::count());
    }

    // ── The routes ──────────────────────────────────────────────────────────

    public function test_assessing_from_the_route_stores_a_snapshot(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/risk/assess")->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull(SupplierRiskScore::latestFor($supplier->id));
        $this->assertNotNull($supplier->fresh()->risk_score);
    }

    public function test_tiering_from_the_route_records_the_answers_and_rescores(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/risk/tier", [
            'annual_spend' => 3_000_000,
            'personal_data' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $supplier->refresh();
        $this->assertEquals(4, $supplier->risk_tier);
        // The answers are kept, not just the conclusion — somebody will ask why it is tier 4.
        $this->assertTrue($supplier->tier_factors['personal_data']);
        $this->assertContains('Processes personal data', $supplier->tier_factors['reasons']);
        $this->assertEquals(4, SupplierRiskScore::latestFor($supplier->id)->tier);
    }

    public function test_adjudicating_a_match_requires_a_reason_when_clearing_it(): void
    {
        $supplier = $this->supplier();
        $hit = ScreeningHit::create([
            'supplier_id' => $supplier->id, 'subject_type' => 'supplier', 'subject_name' => $supplier->company_name,
            'list_type' => ScreeningHit::TYPE_SANCTIONS, 'matched_name' => 'ABC Holdings',
            'match_score' => 90, 'provider_code' => 'manual', 'status' => ScreeningHit::STATUS_OPEN,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->patch("/suppliers/{$supplier->uuid}/risk/hits/{$hit->id}", [
            'status' => ScreeningHit::STATUS_FALSE_POSITIVE,
        ])->assertSessionHasErrors('note');

        $this->assertEquals(ScreeningHit::STATUS_OPEN, $hit->fresh()->status);

        $this->patch("/suppliers/{$supplier->uuid}/risk/hits/{$hit->id}", [
            'status' => ScreeningHit::STATUS_FALSE_POSITIVE,
            'note' => 'Registration number and country both differ from the designated entity.',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(ScreeningHit::STATUS_FALSE_POSITIVE, $hit->fresh()->status);
    }

    public function test_a_hit_belonging_to_another_supplier_cannot_be_adjudicated_through_this_one(): void
    {
        $mine = $this->supplier();
        $theirs = $this->supplier();
        $hit = ScreeningHit::create([
            'supplier_id' => $theirs->id, 'subject_type' => 'supplier', 'subject_name' => $theirs->company_name,
            'list_type' => ScreeningHit::TYPE_SANCTIONS, 'matched_name' => 'Someone',
            'match_score' => 90, 'provider_code' => 'manual', 'status' => ScreeningHit::STATUS_OPEN,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->patch("/suppliers/{$mine->uuid}/risk/hits/{$hit->id}", [
            'status' => ScreeningHit::STATUS_CONFIRMED,
        ])->assertNotFound();
    }

    public function test_a_credit_report_can_be_captured_by_hand_and_rescores_the_supplier(): void
    {
        $supplier = $this->fullyVerified();
        $before = $this->risk->assess($supplier);

        $this->post("/suppliers/{$supplier->uuid}/risk/credit", [
            'provider_code' => 'manual',
            'score' => 25, 'score_max' => 100,
            'judgments_count' => 3, 'payment_behaviour' => 'delinquent',
            'report_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $after = SupplierRiskScore::latestFor($supplier->id);

        $this->assertGreaterThan((float) $before->total, (float) $after->total);
        $this->assertContains('The credit report shows insolvency, business rescue or judgments.', $after->edd_triggers);
    }

    public function test_a_credit_report_cannot_be_dated_in_the_future(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/risk/credit", [
            'report_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasErrors('report_date');
    }

    public function test_the_supplier_page_carries_the_assessment_and_its_gates(): void
    {
        $supplier = $this->fullyVerified();
        $this->risk->assess($supplier);
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                $risk = $page->toArray()['props']['risk'];

                $this->assertNotNull($risk['score']);
                $this->assertTrue($risk['score']['auto_approvable']);
                // The breakdown travels with the score, so the number can be argued with rather than
                // just believed.
                $this->assertArrayHasKey(RiskModel::COMPANY_IDENTITY, $risk['score']['components']);
                $this->assertArrayHasKey('history', $risk);
                $this->assertArrayHasKey('outstanding', $risk['tier']);
            });
    }

    public function test_the_page_shows_an_unassessed_supplier_as_unassessed(): void
    {
        $supplier = $this->supplier();
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}")
            ->assertOk()
            ->assertInertia(function ($page) {
                // Null rather than a zero score: never assessed must not render as "0 — excellent".
                $this->assertNull($page->toArray()['props']['risk']['score']);
            });
    }
}
