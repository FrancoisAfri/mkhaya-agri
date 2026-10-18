<?php

namespace Tests\Feature\Tax;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Exceptions\TaxRuleException;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxRegistration;
use App\Modules\Finance\Tax\Models\TaxRuleVersion;
use App\Modules\Finance\Tax\Services\TaxCalculator;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Services\TaxRegistrationService;
use App\Modules\Finance\Tax\Services\TaxRuleService;

/**
 * SRS §25 acceptance matrix — registrations, rules and the calculator:
 * TAX-001, TAX-002, TAX-003, TAX-004, TAX-005, TAX-008, TAX-021, TAX-024.
 */
class TaxFoundationTest extends TaxTestCase
{
    // ── TAX-001 ──────────────────────────────────────────────────────────────

    public function test_tax_001_a_registration_is_saved_against_its_entity_and_generates_nothing_until_confirmed(): void
    {
        $this->actingAs($this->reviewer);
        $reg = app(TaxRegistrationService::class)->create([
            'entity_id' => $this->entity->id, 'tax_type_id' => $this->vat()->id, 'reference' => '4123456789',
            'effective_from' => '2026-01-01', 'filing_frequency' => 'bi_monthly_even',
            'preparer_id' => $this->preparer->id, 'reviewer_id' => $this->reviewer->id,
        ]);

        $this->assertSame($this->entity->id, $reg->entity_id);
        $this->assertSame('pending', $reg->status);
        $this->assertSame(0, app(TaxPeriodService::class)->generate($reg), 'TAX-REG-003: nothing until applicability is confirmed.');

        app(TaxRegistrationService::class)->confirmApplicability($reg, 'Registration letter dated 2026-01-05');
        $created = app(TaxPeriodService::class)->generate($reg->fresh(), now()->parse('2026-12-31'));

        $this->assertSame(6, $created, 'Six two-monthly periods in a calendar year.');
        $labels = TaxPeriod::orderBy('start_date')->pluck('label')->all();
        $this->assertSame('Jan–Feb 2026', $labels[0], 'Category B periods end in even months.');
        $this->assertSame(2, $reg->versions()->count(), 'Creation and confirmation are both versioned (TAX-REG-005).');
    }

    public function test_a_registration_with_details_missing_cannot_be_confirmed(): void
    {
        $this->actingAs($this->reviewer);
        $reg = app(TaxRegistrationService::class)->create([
            'entity_id' => $this->entity->id, 'tax_type_id' => $this->vat()->id, 'reference' => '12345',
            'effective_from' => '2026-01-01', 'filing_frequency' => 'monthly', 'preparer_id' => $this->preparer->id,
        ]);

        $this->expectException(TaxControlException::class);
        $this->expectExceptionMessageMatches('/valid VAT number.*reviewer/');
        app(TaxRegistrationService::class)->confirmApplicability($reg, 'Trying anyway');
    }

    public function test_two_registrations_for_one_tax_over_the_same_dates_are_refused(): void
    {
        $this->vatRegistration();

        $this->expectException(TaxControlException::class);
        app(TaxRegistrationService::class)->create([
            'entity_id' => $this->entity->id, 'tax_type_id' => $this->vat()->id, 'reference' => '4111111111',
            'effective_from' => '2026-06-01', 'filing_frequency' => 'monthly',
        ]);
    }

    public function test_a_registration_change_needs_a_reason_and_is_versioned(): void
    {
        $reg = $this->vatRegistration();
        app(TaxRegistrationService::class)->update($reg, ['filing_frequency' => 'monthly'], 'Moved to Category C by SARS', '2026-07-01');

        $latest = $reg->versions()->first();
        $this->assertSame('Moved to Category C by SARS', $latest->reason);
        $this->assertSame(['bi_monthly_even', 'monthly'], $latest->changes['filing_frequency']);
        $this->assertSame('2026-07-01', $latest->effective_date->toDateString());
    }

    // ── Due dates ────────────────────────────────────────────────────────────

    public function test_due_dates_come_from_the_tax_types_rule(): void
    {
        $this->vatRegistration();
        $p = TaxPeriod::orderBy('start_date')->first(); // Jan–Feb 2026

        // VAT via eFiling: last business day of the following month. 31 March 2026 is a Tuesday.
        $this->assertSame('2026-03-31', $p->filing_due_date->toDateString());
        $this->assertSame('2026-03-31', $p->payment_due_date->toDateString());
        $this->assertDatabaseHas('tax_compliance_deadlines', ['period_id' => $p->id, 'kind' => 'filing', 'owner_id' => $this->preparer->id]);
        $this->assertDatabaseHas('tax_compliance_deadlines', ['period_id' => $p->id, 'kind' => 'payment']);
    }

    // ── TAX-004 / TAX-005 ────────────────────────────────────────────────────

    public function test_tax_004_standard_rated_vat_matches_reference_calculations(): void
    {
        $rule = $this->publishedRule('STD', '15', 'standard', 'output');
        $calc = app(TaxCalculator::class);

        $exclusive = $calc->calculate($rule, '1000.00');
        $this->assertSame('calculated', $exclusive['status']);
        $this->assertSame('150.00', $exclusive['tax']);
        $this->assertSame('1150.00', $exclusive['gross']);

        $inclusive = $calc->calculate($rule, '1150.00', true);
        $this->assertSame('150.00', $inclusive['tax']);
        $this->assertSame('1000.00', $inclusive['taxable_base']);

        // 0.33 × 15% = 0.0495 → 0.05 (half up); the unrounded value is kept for reproduction.
        $small = $calc->calculate($rule, '0.33');
        $this->assertSame('0.05', $small['tax']);
        $this->assertSame('0.0495', $small['unrounded_tax']);
        $this->assertSame($rule->id, $small['rule_version_id']);
    }

    public function test_tax_005_zero_rated_and_exempt_are_zero_not_missing(): void
    {
        $calc = app(TaxCalculator::class);
        $zr = $calc->calculate($this->publishedRule('ZR', '0', 'zero_rated', 'output'), '500.00');
        $ex = $calc->calculate($this->publishedRule('EX', '0', 'exempt', 'output'), '500.00');
        $none = $calc->calculate(null, '500.00');

        $this->assertSame(['calculated', '0.00'], [$zr['status'], $zr['tax']]);
        $this->assertSame(['calculated', '0.00'], [$ex['status'], $ex['tax']]);
        $this->assertSame('no_rule', $none['status']);
        $this->assertNull($none['tax'], 'A missing calculation is null, never zero.');
    }

    // ── TAX-002 / TAX-003 / TAX-008 / TAX-024 ────────────────────────────────

    public function test_tax_002_a_rule_publishes_only_after_simulation_and_approval_by_someone_else(): void
    {
        $rules = app(TaxRuleService::class);
        $this->actingAs($this->preparer);
        $draft = $rules->draft($this->ruleData('STD', '15', '2018-04-01'));

        try {
            $rules->submit($draft, 'Standard rate');
            $this->fail('Submission without a simulation must be refused.');
        } catch (TaxRuleException) {
        }

        $sim = $rules->simulate($draft, [['amount' => '1000', 'expected_tax' => '150.00'], ['amount' => '1150', 'inclusive' => true, 'expected_tax' => '150.00']]);
        $this->assertTrue($sim['passed']);
        $rules->submit($draft->fresh(), 'Standard rate per VAT Act s7(1)(a)');

        try {
            $rules->approveAndPublish($draft->fresh());
            $this->fail('The author must not approve their own rule.');
        } catch (TaxRuleException $e) {
            $this->assertStringContainsString('someone else', $e->getMessage());
        }
        $this->assertDatabaseHas('tax_audit_events', ['action' => 'rule.approve', 'outcome' => 'denied']);

        $this->actingAs($this->reviewer);
        $published = $rules->approveAndPublish($draft->fresh(), 'Confirmed against the Act');
        $this->assertSame('published', $published->status);
        $this->assertSame($this->reviewer->id, $published->approved_by);
    }

    public function test_a_rule_edited_after_simulation_must_be_simulated_again(): void
    {
        $rules = app(TaxRuleService::class);
        $this->actingAs($this->preparer);
        $draft = $rules->draft($this->ruleData('STD', '15', '2018-04-01'));
        $rules->simulate($draft, [['amount' => '100']]);
        $rules->updateDraft($draft->fresh(), ['rate' => '16'] + $this->ruleData('STD', '16', '2018-04-01'));

        $this->expectException(TaxRuleException::class);
        $rules->submit($draft->fresh(), 'Changed');
    }

    public function test_tax_003_overlapping_effective_dates_cannot_be_published(): void
    {
        $this->publishedRule('STD', '15', 'standard', 'output', '2018-04-01');
        $rules = app(TaxRuleService::class);

        $this->actingAs($this->preparer);
        // Starts on the same day as the version in force: there is nothing to end, only a conflict.
        $clash = $rules->draft($this->ruleData('STD', '16', '2018-04-01'));
        $rules->simulate($clash, [['amount' => '100']]);

        $this->expectException(TaxRuleException::class);
        $rules->submit($clash->fresh(), 'Clashing rate');
    }

    public function test_tax_008_and_024_a_future_rate_leaves_history_and_the_old_version_intact(): void
    {
        $old = $this->publishedRule('STD', '15', 'standard', 'output', '2018-04-01');
        $before = app(TaxCalculator::class)->calculate($old, '1000');

        $rules = app(TaxRuleService::class);
        $this->actingAs($this->preparer);
        $draft = $rules->draft($this->ruleData('STD', '16', '2027-04-01'));
        $rules->simulate($draft, [['amount' => '1000', 'expected_tax' => '160.00']]);
        $rules->submit($draft->fresh(), 'Rate change announced in the Budget');
        $this->actingAs($this->reviewer);
        $new = $rules->approveAndPublish($draft->fresh(), 'Per Budget speech', closePrevious: true);

        $old->refresh();
        $this->assertSame('2027-03-31', $old->effective_to->toDateString(), 'The old version ends the day before.');
        $this->assertSame($old->id, $rules->resolve('STD', $this->entity->id, '2026-10-01')->id);
        $this->assertSame($new->id, $rules->resolve('STD', $this->entity->id, '2027-04-01')->id);
        $this->assertSame('150.00', app(TaxCalculator::class)->calculate($old, '1000')['tax'], 'TAX-008: history unchanged.');
        $this->assertSame($before['tax'], '150.00');

        // TAX-024: the published version cannot be edited in place.
        $this->expectException(TaxRuleException::class);
        $old->rate = '14';
        $old->save();
    }

    // ── TAX-021 ──────────────────────────────────────────────────────────────

    public function test_tax_021_only_an_authorised_reopen_with_a_reason_unlocks_a_period(): void
    {
        $this->vatRegistration();
        $period = TaxPeriod::orderBy('start_date')->first();
        $period->forceFill(['status' => 'closed'])->save();
        $periods = app(TaxPeriodService::class);

        $this->actingAs($this->reviewer);
        $periods->transition($period, 'locked');
        $this->assertTrue($period->fresh()->isLocked());

        try {
            $periods->reopen($period->fresh(), 'too short');
            $this->fail('A reopen needs a real reason.');
        } catch (TaxControlException) {
        }

        $periods->reopen($period->fresh(), 'SARS query on input VAT for supplier X — corrections needed');
        $period->refresh();
        $this->assertSame('in_preparation', $period->status);
        $this->assertSame(1, $period->reopen_count);
        $this->assertDatabaseHas('tax_audit_events', ['action' => 'period.reopened', 'subject_id' => $period->id]);
        $this->assertSame(['in_preparation', 'locked'], array_slice($period->events()->pluck('to_status')->all(), 0, 2), 'The lock stays in the history.');
    }

    public function test_a_period_is_not_marked_submitted_or_accepted_without_evidence(): void
    {
        $this->vatRegistration();
        $period = TaxPeriod::orderBy('start_date')->first();
        $period->forceFill(['status' => 'approved'])->save();

        $this->actingAs($this->reviewer);
        $this->expectException(TaxControlException::class);
        app(TaxPeriodService::class)->transition($period, 'submitted');
    }

    private function ruleData(string $code, string $rate, string $from): array
    {
        return ['tax_type_id' => $this->vat()->id, 'tax_code' => $code, 'name' => "{$code} {$rate}%", 'treatment' => 'standard',
            'direction' => 'output', 'method' => 'percentage', 'rate' => $rate, 'rounding' => 'half_up', 'precision' => 2,
            'return_field' => '1', 'effective_from' => $from];
    }
}
