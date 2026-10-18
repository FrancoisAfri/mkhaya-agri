<?php
namespace App\Modules\Policies\Services\RuleEngine;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Models\RuleCondition;
class ConditionEvaluator {
    /** @param RuleCondition[] $conditions */
    public function evaluate(array $conditions, PolicyEventDTO $event): bool {
        if (empty($conditions)) return true;
        $result = null;
        foreach ($conditions as $i => $condition) {
            $check = $this->evaluateSingle($condition, $event->payload);
            $result = $i === 0 ? $check
                : ($condition->logical_operator === 'OR' ? $result || $check : $result && $check);
        }
        return (bool) $result;
    }
    private function evaluateSingle(RuleCondition $condition, array $payload): bool {
        $actual   = data_get($payload, $condition->field);
        $expected = $condition->value;
        return match($condition->operator) {
            'eq'      => $actual == $expected,
            'neq'     => $actual != $expected,
            'gt'      => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte'     => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt'      => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte'     => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'in'      => in_array($actual, (array) $expected),
            'not_in'  => !in_array($actual, (array) $expected),
            'between' => is_array($expected)
                && count($expected) >= 2
                && is_numeric($actual)
                && is_numeric($expected[0])
                && is_numeric($expected[1])
                && (float) $actual >= (float) $expected[0]
                && (float) $actual <= (float) $expected[1],
            'contains'=> str_contains((string) $actual, (string) $expected),
            default   => throw new \InvalidArgumentException("Unknown rule operator [{$condition->operator}]"),
        };
    }
}
