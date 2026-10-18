<?php
namespace App\Modules\Policies\Services\RuleEngine;
use App\Modules\Policies\DTOs\{PolicyEventDTO,EvaluationResultDTO};
use App\Modules\Policies\Models\{EventRuleMap,PolicyRule};
class RuleEvaluationEngine {
    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator,
    ) {}
    /** @return EvaluationResultDTO[] */
    public function evaluate(PolicyEventDTO $event): array {
        return collect($this->resolveApplicableRules($event))
            ->map(fn(PolicyRule $rule) => $this->evaluateRule($rule, $event))
            ->all();
    }
    private function resolveApplicableRules(PolicyEventDTO $event): array {
        return EventRuleMap::with(['rule.conditions','rule.actions'])
            ->where('module', $event->module)->where('active', true)->get()
            ->filter(fn(EventRuleMap $map) =>
                fnmatch($map->action_pattern, $event->action) &&
                $this->passesPayloadFilter($map->payload_filter, $event->payload)
            )->map(fn(EventRuleMap $map) => $map->rule)
            ->filter(fn(?PolicyRule $rule) => $rule?->active)->values()->all();
    }
    private function evaluateRule(PolicyRule $rule, PolicyEventDTO $event): EvaluationResultDTO {
        $passed = $this->conditionEvaluator->evaluate($rule->conditions->all(), $event);
        return new EvaluationResultDTO(
            rule: $rule, event: $event, passed: $passed,
            severity: $rule->severity, outcome: $passed ? 'pass' : $rule->action_on_breach,
        );
    }
    private function passesPayloadFilter(?array $filter, array $payload): bool {
        if (empty($filter)) return true;
        foreach ($filter as $key => $expected) {
            if (data_get($payload, $key) !== $expected) return false;
        }
        return true;
    }
}
