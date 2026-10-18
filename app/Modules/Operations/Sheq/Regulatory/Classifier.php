<?php

namespace App\Modules\Operations\Sheq\Regulatory;

/**
 * Applies a pack's classification rules to the facts of an incident (SHEQ-REG-3).
 *
 * Core code decides *nothing* about what is reportable. It walks the pack's rules in order, stops at
 * the first match, and returns whatever that rule declared. "Section 24", "RIDDOR" and "OSHA 300"
 * appear in pack YAML and never here — principle P2.
 *
 * First-match-wins is the semantic, and it is why rule order in the pack is meaningful: a fatality
 * matches `s24a` before it reaches the catch-all `recordable_non_s24` beneath. Packs therefore list
 * rules most-severe first, exactly as the specification's example does.
 */
class Classifier
{
    public function __construct(private readonly RuleEvaluator $evaluator = new RuleEvaluator) {}

    /** @param array<string,mixed> $facts */
    public function classify(PackManifest $pack, array $facts): ClassificationResult
    {
        foreach ($pack->classificationRules() as $rule) {
            $when = $rule['when'] ?? null;

            // A rule with no `when` is an unconditional catch-all; packs use it as a final fallback.
            if ($when !== null && ! $this->evaluator->matches((array) $when, $facts)) {
                continue;
            }

            $then = (array) ($rule['then'] ?? []);

            return new ClassificationResult(
                packId: $pack->packId,
                packVersion: $pack->version,
                ruleId: isset($rule['id']) ? (string) $rule['id'] : null,
                statutoryCategory: isset($then['statutory_category']) ? (string) $then['statutory_category'] : null,
                reportable: (bool) ($then['reportable'] ?? false),
                obligations: array_values((array) ($then['obligations'] ?? [])),
                forms: array_values((array) ($then['forms'] ?? [])),
                attributes: array_diff_key($then, array_flip(['statutory_category', 'reportable', 'obligations', 'forms'])),
            );
        }

        // Nothing matched. The incident is still recorded and still investigable — it simply carries
        // no statutory category. Silently inventing one would be worse than admitting the gap.
        return ClassificationResult::unclassified($pack->packId, $pack->version);
    }

    /**
     * Investigation rigour for an incident, driven by potential severity (SHEQ-INC-7).
     *
     * Returns the pack's requirement — minimum method and whether an investigation is mandatory —
     * so that a near-miss with fatal potential is held to the same standard as a lost-time injury.
     *
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function investigationRequirement(PackManifest $pack, array $facts): array
    {
        $rules = $pack->investigationRules();
        $potential = (int) ($facts['potential_severity'] ?? 0);
        $actual = (int) ($facts['actual_severity'] ?? 0);

        // The driver is deliberately the higher of the two: a pack may omit potential severity for
        // an incident whose actual outcome already demands a full investigation.
        $driver = max($potential, $actual);

        $selected = [];
        foreach ($rules['thresholds'] ?? [] as $threshold) {
            if ($driver >= (int) ($threshold['severity_at_least'] ?? 0)) {
                $selected = $threshold;
            }
        }

        return $selected + [
            'required' => $driver > 0,
            'driver_severity' => $driver,
            'driven_by_potential' => $potential >= $actual,
        ];
    }
}
