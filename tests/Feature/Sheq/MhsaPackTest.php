<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Regulatory\Classifier;
use App\Modules\Operations\Sheq\Regulatory\MetricConventions;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use PHPUnit\Framework\TestCase;

/**
 * The Mine Health and Safety Act pack.
 *
 * A mine is not an OHS Act workplace — section 1 of the OHS Act excludes it. These tests assert the
 * ways the mining regime genuinely differs, because a mine running the wrong pack would notify the
 * wrong authority, on the wrong form, against the wrong benchmark, and would look an order of
 * magnitude safer than it is.
 *
 * The clause content is DRAFT pending practitioner sign-off; what is asserted here is that the pack
 * is internally coherent and that the differences from za-ohsa are real rather than cosmetic.
 */
class MhsaPackTest extends TestCase
{
    private function pack(string $id = 'za-mhsa'): PackManifest
    {
        return (new PackLoader)->load(
            dirname(__DIR__, 3)."/resources/regulatory-packs/{$id}"
        );
    }

    private function classify(array $facts, string $packId = 'za-mhsa'): array
    {
        return (new Classifier)->classify($this->pack($packId), $facts)->toArray();
    }

    // ── The pack loads and is internally coherent ───────────────────────────────────────────────

    public function test_the_pack_loads_and_declares_its_jurisdiction(): void
    {
        $pack = $this->pack();

        $this->assertSame('za-mhsa', $pack->packId);
        $this->assertSame('ZA', $pack->jurisdiction());
        $this->assertNotEmpty($pack->classificationRules());
        $this->assertNotEmpty($pack->legalRegister());
    }

    public function test_every_obligation_a_rule_references_is_defined(): void
    {
        /**
         * A rule naming an obligation the pack does not define would schedule nothing — the
         * incident would look handled while no statutory clock was ever started.
         */
        $pack = $this->pack();
        $defined = array_keys($pack->obligations());
        $missing = [];

        foreach ($pack->classificationRules() as $rule) {
            foreach ($rule['then']['obligations'] ?? [] as $obligation) {
                if (! in_array($obligation, $defined, true)) {
                    $missing[] = "{$rule['id']} → {$obligation}";
                }
            }
        }

        $this->assertSame([], $missing, implode(', ', $missing));
    }

    public function test_every_form_a_rule_references_is_defined(): void
    {
        $pack = $this->pack();
        $forms = array_keys($pack->manifest['forms'] ?? []);
        $missing = [];

        foreach ($pack->classificationRules() as $rule) {
            foreach ($rule['then']['forms'] ?? [] as $form) {
                if (! in_array($form, $forms, true)) {
                    $missing[] = "{$rule['id']} → {$form}";
                }
            }
        }

        $this->assertSame([], $missing, implode(', ', $missing));
    }

    // ── Classification under s23 ────────────────────────────────────────────────────────────────

    public function test_a_fatality_is_section_23_and_notifies_the_principal_inspector(): void
    {
        $result = $this->classify(['outcome' => 'FATALITY', 'incident_type' => 'INJURY']);

        $this->assertSame('MHSA_S23_FATAL', $result['statutory_category']);
        $this->assertTrue($result['reportable']);
        $this->assertContains('immediate_pi_notification', $result['obligations']);
        // s12: the scene may not be disturbed without permission — a duty the OHS Act has no
        // equivalent of, and one that has to start immediately.
        $this->assertContains('scene_preservation', $result['obligations']);
    }

    public function test_the_principal_inspector_must_be_notified_far_faster_than_the_ohs_act_requires(): void
    {
        $mhsa = $this->pack()->obligation('immediate_pi_notification');
        $ohsa = $this->pack('za-ohsa')->obligation('immediate_pd_notification');

        $this->assertSame('PT4H', $mhsa['due_in']);
        $this->assertSame('PT24H', $ohsa['due_in']);
        $this->assertStringContainsString('Principal Inspector', $mhsa['recipient']);
    }

    public function test_a_fall_of_ground_with_nobody_hurt_is_still_reportable(): void
    {
        /**
         * The clearest divergence from the OHS Act. Mining treats dangerous occurrences as
         * reportable in their own right — a fall of ground that injured nobody still goes to the
         * Principal Inspector, where the equivalent OHS Act event usually would not.
         */
        $result = $this->classify([
            'incident_type' => 'FALL_OF_GROUND',
            'outcome' => 'NO_INJURY',
            'treatment_level' => null,
        ]);

        $this->assertSame('MHSA_S23_DANGEROUS_OCCURRENCE', $result['statutory_category']);
        $this->assertTrue($result['reportable']);
    }

    public function test_a_methane_ignition_is_a_dangerous_occurrence(): void
    {
        $result = $this->classify(['incident_type' => 'METHANE_IGNITION', 'outcome' => 'NO_INJURY']);

        $this->assertTrue($result['reportable']);
        // Rule outputs beyond the engine's own keys land in `attributes`.
        $this->assertSame('ICAM', $result['attributes']['investigation_minimum'] ?? null);
    }

    public function test_an_occupational_disease_routes_to_odmwa_not_coida(): void
    {
        // Occupational disease in a mine falls under ODMWA and the Medical Bureau, which is a
        // different authority and a different form from the COIDA route.
        $result = $this->classify(['incident_type' => 'OCCUPATIONAL_DISEASE', 'outcome' => 'ILLNESS']);

        $this->assertSame('ODMWA_REPORTABLE', $result['statutory_category']);
        $this->assertContains('odmwa_notification', $result['obligations']);
        $this->assertContains('ODMWA_NOTIFICATION', $result['forms']);
    }

    public function test_a_first_aid_case_is_still_recorded_but_not_reported(): void
    {
        $result = $this->classify(['treatment_level' => 'FIRST_AID', 'outcome' => 'INJURY_OR_ILLNESS']);

        $this->assertFalse($result['reportable']);
        $this->assertContains('section21_record', $result['obligations']);
    }

    public function test_a_near_miss_is_neither_reportable_nor_forgotten(): void
    {
        $result = $this->classify(['incident_type' => 'NEAR_MISS', 'outcome' => 'NO_INJURY']);

        $this->assertSame('NON_RECORDABLE', $result['statutory_category']);
        $this->assertFalse($result['reportable']);
    }

    // ── Metrics: the benchmark that must not be mixed up ────────────────────────────────────────

    public function test_mining_rates_use_the_200000_hour_base_not_the_million_hour_one(): void
    {
        /**
         * A mine benchmarked on the OHS Act's million-hour multiplier reports a fifth of its real
         * rate. This is the single most consequential difference between the two packs.
         */
        $mhsa = new MetricConventions($this->pack());
        $ohsa = new MetricConventions($this->pack('za-ohsa'));

        $counters = ['disabling_injuries' => 3, 'hours_worked' => 600000, 'lost_time_injuries' => 3];

        $this->assertSame(1.0, $mhsa->compute('DIFR', $counters));

        // The same three injuries over the same hours, on the OHS Act's LTIFR convention.
        $this->assertSame(5.0, $ohsa->compute('LTIFR', $counters));
    }

    public function test_the_pack_defines_the_rates_the_dmre_reports_on(): void
    {
        $ids = array_column($this->pack()->metrics()['definitions'], 'id');

        foreach (['DIFR', 'FIFR', 'LTIFR'] as $expected) {
            $this->assertContains($expected, $ids);
        }
    }

    // ── Retention ───────────────────────────────────────────────────────────────────────────────

    public function test_mining_records_are_kept_for_forty_years(): void
    {
        // Occupational lung disease presents decades after exposure; the record is what links a
        // claim to the workplace that caused it.
        $retention = $this->pack()->retention();

        $this->assertSame('P40Y', $retention['incident_records']);
        $this->assertSame('P40Y', $retention['exposure_monitoring']);
    }

    // ── Legal register ──────────────────────────────────────────────────────────────────────────

    public function test_the_legal_register_covers_the_mhsa_duties_and_cites_them(): void
    {
        $register = $this->pack()->legalRegister();
        $clauses = array_column($register, 'clause');

        foreach (['s11', 's21', 's23', 's25'] as $clause) {
            $this->assertContains($clause, $clauses, "The register omits MHSA {$clause}.");
        }

        foreach ($register as $line) {
            $this->assertNotEmpty($line['instrument']);
            $this->assertNotEmpty($line['requirement']);
            $this->assertNotEmpty($line['evidence_type']);
        }
    }

    public function test_the_two_packs_do_not_claim_the_same_site_types(): void
    {
        /**
         * A site that matched both packs would be classified by whichever activated last. Mining
         * site types and OHS Act site types are kept distinct so that cannot happen by accident.
         */
        $mining = $this->pack()->manifest['applies_to_site_types'];
        $general = $this->pack('za-ohsa')->manifest['applies_to_site_types'];

        $this->assertContains('MINE', $mining);
        $this->assertNotContains('MINE', $general);
        $this->assertContains('OFFICE', $general);
        $this->assertNotContains('OFFICE', $mining);
    }
}
