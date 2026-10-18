<?php

namespace Tests\Unit\Sheq;

use App\Modules\Operations\Sheq\Regulatory\Classifier;
use App\Modules\Operations\Sheq\Regulatory\MetricConventions;
use App\Modules\Operations\Sheq\Regulatory\ObligationDraft;
use App\Modules\Operations\Sheq\Regulatory\ObligationScheduler;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The Regulatory Pack Engine against the za-ohsa pack, driven by the acceptance criteria in the
 * SHEQ specification (§5.1 "statutory clock", §3.2).
 *
 * These tests are the reason the engine exists: if a fatality stops classifying as OHSA_S24(a), or
 * the seven-day W.Cl.2 clock drifts, a customer misses a statutory deadline. They deliberately load
 * the real shipped pack rather than a fixture — a test that passes against a hand-written pack while
 * the real one is malformed is worse than no test.
 */
class RegulatoryPackEngineTest extends TestCase
{
    private static ?PackManifest $pack = null;

    private function pack(): PackManifest
    {
        return self::$pack ??= (new PackLoader)->load(
            dirname(__DIR__, 3).'/resources/regulatory-packs/za-ohsa'
        );
    }

    // ── Classification (SHEQ-REG-3) ──────────────────────────────────────────────────────────────

    public function test_a_fatality_is_classified_under_section_24a_and_is_reportable(): void
    {
        $result = (new Classifier)->classify($this->pack(), ['outcome' => 'FATALITY']);

        $this->assertSame('OHSA_S24(a)', $result->statutoryCategory);
        $this->assertTrue($result->reportable);
        $this->assertSame('s24a', $result->ruleId);
        $this->assertContains('immediate_pd_notification', $result->obligations);
        $this->assertContains('wcl_submission_7d', $result->obligations);
        $this->assertContains('annexure1_record', $result->obligations);
        $this->assertContains('WCL1_OR_WCL2', $result->forms);
    }

    public function test_an_injury_causing_fourteen_days_incapacity_reaches_section_24a(): void
    {
        $result = (new Classifier)->classify($this->pack(), [
            'outcome' => 'LOST_TIME',
            'likely_incapacity_days' => 14,
        ]);

        $this->assertSame('OHSA_S24(a)', $result->statutoryCategory);
        $this->assertTrue($result->reportable);
    }

    public function test_thirteen_days_incapacity_does_not_reach_section_24a(): void
    {
        // The 14-day boundary is the statute's, and off-by-one here is a missed notification.
        $result = (new Classifier)->classify($this->pack(), [
            'outcome' => 'LOST_TIME',
            'likely_incapacity_days' => 13,
            'treatment_level' => 'LOST_TIME',
        ]);

        $this->assertSame('RECORDABLE', $result->statutoryCategory);
        $this->assertFalse($result->reportable);
        $this->assertSame(['annexure1_record'], $result->obligations);
    }

    public function test_unknown_incapacity_days_does_not_satisfy_the_fourteen_day_test(): void
    {
        // A 60-second report (SHEQ-INC-1) knows almost nothing. Absent facts must not be read as
        // zero, nor as satisfying a threshold.
        $result = (new Classifier)->classify($this->pack(), [
            'outcome' => 'LOST_TIME',
            'treatment_level' => 'LOST_TIME',
        ]);

        $this->assertSame('RECORDABLE', $result->statutoryCategory);
    }

    public function test_a_dangerous_substance_release_is_section_24c(): void
    {
        $result = (new Classifier)->classify($this->pack(), ['dangerous_substance_release' => true]);

        $this->assertSame('OHSA_S24(c)', $result->statutoryCategory);
        $this->assertTrue($result->reportable);
    }

    public function test_first_aid_only_is_recorded_but_not_reportable(): void
    {
        $result = (new Classifier)->classify($this->pack(), ['treatment_level' => 'FIRST_AID']);

        $this->assertSame('FIRST_AID', $result->statutoryCategory);
        $this->assertFalse($result->reportable);
        $this->assertSame([], $result->obligations);
    }

    public function test_a_near_miss_falls_through_to_non_recordable(): void
    {
        $result = (new Classifier)->classify($this->pack(), [
            'incident_type' => 'NEAR_MISS',
            'outcome' => 'NO_INJURY',
        ]);

        $this->assertSame('NON_RECORDABLE', $result->statutoryCategory);
        $this->assertFalse($result->reportable);
    }

    public function test_the_result_records_which_pack_version_judged_it(): void
    {
        // SHEQ-REG-2: the record must stay explainable by the rules effective at occurred_at.
        $result = (new Classifier)->classify($this->pack(), ['outcome' => 'FATALITY']);

        $this->assertSame('za-ohsa', $result->packId);
        $this->assertSame($this->pack()->version, $result->packVersion);
    }

    // ── Investigation depth (SHEQ-INC-7) ─────────────────────────────────────────────────────────

    public function test_a_near_miss_with_fatal_potential_demands_the_same_rigour_as_a_serious_injury(): void
    {
        $classifier = new Classifier;

        $nearMiss = $classifier->investigationRequirement($this->pack(), [
            'actual_severity' => 1,   // nobody hurt
            'potential_severity' => 5,   // could have been a fatality
        ]);

        $serious = $classifier->investigationRequirement($this->pack(), [
            'actual_severity' => 5,
            'potential_severity' => 5,
        ]);

        $this->assertSame($serious['method_minimum'], $nearMiss['method_minimum']);
        $this->assertSame('ICAM', $nearMiss['method_minimum']);
        $this->assertTrue($nearMiss['driven_by_potential']);
    }

    // ── The statutory clock (SHEQ-REG-4/5, §5.1 acceptance) ─────────────────────────────────────

    public function test_the_wcl_submission_is_due_seven_days_after_the_incident(): void
    {
        // Given an incident occurring at 2026-08-06T14:00:00+02:00 …
        $occurredAt = Carbon::parse('2026-08-06T14:00:00+02:00');

        $result = (new Classifier)->classify($this->pack(), ['outcome' => 'FATALITY']);
        $drafts = (new ObligationScheduler)->schedule(
            $this->pack(), $result->obligations, $occurredAt, 'Africa/Johannesburg'
        );

        $wcl = $this->obligation($drafts, 'wcl_submission_7d');

        // … then due_at is 2026-08-13T14:00:00+02:00.
        $this->assertSame('2026-08-13T14:00:00+02:00', $wcl->dueAt->toIso8601String());
        $this->assertTrue($wcl->evidenceRequired);
        $this->assertSame('SHEQ_PRACTITIONER', $wcl->responsibleRole);
    }

    public function test_immediate_notification_runs_a_twenty_four_hour_clock(): void
    {
        $occurredAt = Carbon::parse('2026-08-06T14:00:00+02:00');
        $result = (new Classifier)->classify($this->pack(), ['outcome' => 'FATALITY']);
        $drafts = (new ObligationScheduler)->schedule($this->pack(), $result->obligations, $occurredAt);

        $notification = $this->obligation($drafts, 'immediate_pd_notification');

        $this->assertSame('2026-08-07T14:00:00+02:00', $notification->dueAt->toIso8601String());
    }

    public function test_reminders_are_remaining_time_offsets_and_run_in_order(): void
    {
        // P5D on a P7D deadline means "when 5 days remain" — day 2, not day 5. Reading these as
        // elapsed time would fire the final reminder first.
        $occurredAt = Carbon::parse('2026-08-06T14:00:00+02:00');
        $drafts = (new ObligationScheduler)->schedule($this->pack(), ['wcl_submission_7d'], $occurredAt);
        $wcl = $this->obligation($drafts, 'wcl_submission_7d');

        $this->assertCount(3, $wcl->reminderAt);
        $this->assertSame('2026-08-08T14:00:00+02:00', $wcl->reminderAt[0]->toIso8601String()); // 5 days left
        $this->assertSame('2026-08-11T14:00:00+02:00', $wcl->reminderAt[1]->toIso8601String()); // 2 days left
        $this->assertSame('2026-08-13T02:00:00+02:00', $wcl->reminderAt[2]->toIso8601String()); // 12 hours left

        foreach ($wcl->reminderAt as $reminder) {
            $this->assertTrue($reminder->lessThan($wcl->dueAt), 'A reminder must precede the deadline.');
            $this->assertTrue($reminder->greaterThanOrEqualTo($wcl->anchorAt), 'A reminder must not precede the incident.');
        }
    }

    public function test_escalation_ends_in_a_breach_at_the_deadline(): void
    {
        $occurredAt = Carbon::parse('2026-08-06T14:00:00+02:00');
        $drafts = (new ObligationScheduler)->schedule($this->pack(), ['wcl_submission_7d'], $occurredAt);
        $escalations = $this->obligation($drafts, 'wcl_submission_7d')->escalations;

        $this->assertSame('SITE_MANAGER', $escalations[0]['notify_role']);
        $this->assertSame('EXECUTIVE', $escalations[1]['notify_role']);
        $this->assertSame('BREACH', $escalations[1]['severity']);
        $this->assertSame('2026-08-13T14:00:00+02:00', $escalations[1]['after']->toIso8601String());
    }

    public function test_the_annexure_1_deadline_counts_business_days(): void
    {
        // Thursday 6 August + 7 working days = Monday 17 August, not Thursday 13th.
        $drafts = (new ObligationScheduler)->schedule(
            $this->pack(), ['annexure1_record'], Carbon::parse('2026-08-06T09:00:00+02:00')
        );

        $due = $this->obligation($drafts, 'annexure1_record')->dueAt;

        $this->assertSame('2026-08-17', $due->toDateString());
        $this->assertFalse($due->isWeekend());
    }

    public function test_an_obligation_the_pack_does_not_define_fails_loudly(): void
    {
        $this->expectExceptionMessageMatches('/unknown obligation/i');

        (new ObligationScheduler)->schedule($this->pack(), ['no_such_obligation'], Carbon::now());
    }

    // ── Metric conventions (SHEQ-REG-7) ──────────────────────────────────────────────────────────

    public function test_ltifr_uses_the_packs_million_hour_multiplier(): void
    {
        $metrics = new MetricConventions($this->pack());

        $this->assertSame(
            6.0,
            $metrics->compute('LTIFR', ['lost_time_injuries' => 3, 'hours_worked' => 500000])
        );
    }

    public function test_difr_uses_the_south_african_two_hundred_thousand_multiplier(): void
    {
        // DIFR and LTIFR differ by a factor of five. Hard-coding one multiplier misreports the other.
        $metrics = new MetricConventions($this->pack());

        $this->assertSame(
            1.2,
            $metrics->compute('DIFR', ['disabling_injuries' => 3, 'hours_worked' => 500000])
        );
    }

    public function test_a_period_with_no_exposure_hours_has_no_rate_rather_than_zero(): void
    {
        // 0.0 would render on a dashboard as flawless safety; null renders as "no data".
        $metrics = new MetricConventions($this->pack());

        $this->assertNull($metrics->compute('LTIFR', ['lost_time_injuries' => 2, 'hours_worked' => 0]));
    }

    public function test_recordability_follows_the_pack_not_the_code(): void
    {
        $metrics = new MetricConventions($this->pack());

        $this->assertFalse($metrics->isRecordable('FIRST_AID'));
        $this->assertTrue($metrics->isRecordable('MEDICAL_TREATMENT'));
        $this->assertTrue($metrics->isRecordable('RESTRICTED_WORK'));
        $this->assertTrue($metrics->isRecordable('FATALITY'));
        $this->assertFalse($metrics->isRecordable('LOST_TIME', isCommute: true));
    }

    public function test_retention_floors_come_from_the_pack_and_medical_outlives_the_incident(): void
    {
        $metrics = new MetricConventions($this->pack());

        $this->assertSame(3, $metrics->retentionYears('incident'));
        $this->assertSame(40, $metrics->retentionYears('medical_surveillance'));
        $this->assertSame(40, $metrics->retentionYears('asbestos_exposure'));
        $this->assertSame(3, $metrics->retentionYears('something_the_pack_never_heard_of'));
    }

    // ── Pack integrity (SHEQ-REG-8) ──────────────────────────────────────────────────────────────

    public function test_the_checksum_is_stable_across_key_order(): void
    {
        $a = PackManifest::fromArray(['pack_id' => 'x', 'version' => '1', 'a' => 1, 'b' => ['q' => 1, 'p' => 2]]);
        $b = PackManifest::fromArray(['version' => '1', 'b' => ['p' => 2, 'q' => 1], 'pack_id' => 'x', 'a' => 1]);

        $this->assertSame($a->checksum(), $b->checksum());
    }

    public function test_the_shipped_pack_defines_every_obligation_its_rules_reference(): void
    {
        // A classification pointing at a missing obligation means an incident with no statutory
        // deadline. Cheaper to catch here than in production.
        $pack = $this->pack();

        foreach ($pack->classificationRules() as $rule) {
            foreach ((array) ($rule['then']['obligations'] ?? []) as $obligationId) {
                $this->assertNotNull(
                    $pack->obligation($obligationId),
                    "Rule `{$rule['id']}` references undefined obligation `{$obligationId}`."
                );
            }
        }
    }

    /** @param list<ObligationDraft> $drafts */
    private function obligation(array $drafts, string $id): ObligationDraft
    {
        foreach ($drafts as $draft) {
            if ($draft->definitionId === $id) {
                return $draft;
            }
        }

        $this->fail("Obligation `{$id}` was not scheduled.");
    }
}
