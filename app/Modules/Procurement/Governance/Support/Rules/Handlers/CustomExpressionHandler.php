<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Str;

/**
 * CUSTOM — a tenant-defined condition over the subject's own fields, for the checks nobody
 * anticipated (§5.3 leaves the list open on purpose).
 *
 * parameters:
 *   expression  a structured condition, not a string to be executed:
 *     {"field":"risk_level","operator":"in","value":["LOW","MEDIUM"]}
 *     {"all":[{...},{...}]}          every branch must hold
 *     {"any":[{...},{...}]}          at least one branch must hold
 *     {"not":{...}}
 *
 * Operators: = != > >= < <= in not_in contains starts_with is_empty is_not_empty between
 *
 * There is no string evaluation here, and there will not be. A configurable rule engine whose
 * escape hatch is `eval()` hands remote code execution to anybody who can reach the settings screen
 * — and on a multi-tenant platform that is a tenant administrator, not a developer. A structured
 * tree covers what these rules actually need and cannot execute anything.
 *
 * Depth is bounded so a hand-edited or imported expression cannot recurse the evaluator to death.
 */
class CustomExpressionHandler implements RuleHandler
{
    private const MAX_DEPTH = 10;

    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        $expression = $parameters['expression'] ?? null;

        if (! is_array($expression) || $expression === []) {
            return RuleOutcome::skip('The rule has no expression to evaluate.');
        }

        try {
            $holds = $this->evaluateNode($expression, $subject, 0);
        } catch (\Throwable $e) {
            return RuleOutcome::skip('The expression could not be evaluated: '.$e->getMessage());
        }

        return $holds
            ? RuleOutcome::pass()
            : RuleOutcome::fail('The configured condition was not met.', ['expression' => $expression]);
    }

    private function evaluateNode(array $node, RuleSubject $subject, int $depth): bool
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('expression nested too deeply');
        }

        if (isset($node['all'])) {
            foreach ((array) $node['all'] as $branch) {
                if (! $this->evaluateNode((array) $branch, $subject, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($node['any'])) {
            foreach ((array) $node['any'] as $branch) {
                if ($this->evaluateNode((array) $branch, $subject, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($node['not'])) {
            return ! $this->evaluateNode((array) $node['not'], $subject, $depth + 1);
        }

        return $this->evaluateComparison($node, $subject);
    }

    private function evaluateComparison(array $node, RuleSubject $subject): bool
    {
        $field = (string) ($node['field'] ?? '');

        if ($field === '') {
            throw new \RuntimeException('a comparison needs a field');
        }

        $actual = $this->resolve($subject, $field);
        $expected = $node['value'] ?? null;

        return match (mb_strtolower((string) ($node['operator'] ?? '='))) {
            '=', '==', 'eq' => $this->looseEquals($actual, $expected),
            '!=', '<>', 'neq' => ! $this->looseEquals($actual, $expected),
            '>', 'gt' => $this->numeric($actual) > $this->numeric($expected),
            '>=', 'gte' => $this->numeric($actual) >= $this->numeric($expected),
            '<', 'lt' => $this->numeric($actual) < $this->numeric($expected),
            '<=', 'lte' => $this->numeric($actual) <= $this->numeric($expected),
            'in' => $this->inList($actual, (array) $expected),
            'not_in' => ! $this->inList($actual, (array) $expected),
            'contains' => $actual !== null
                && Str::contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'starts_with' => $actual !== null
                && Str::startsWith(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'is_empty' => $this->isEmpty($actual),
            'is_not_empty' => ! $this->isEmpty($actual),
            'between' => is_array($expected) && count($expected) === 2
                && $this->numeric($actual) >= $this->numeric($expected[0])
                && $this->numeric($actual) <= $this->numeric($expected[1]),
            default => throw new \RuntimeException('unknown operator'),
        };
    }

    /** The hoisted subject properties are addressable by name too, not only the `fields` bag. */
    private function resolve(RuleSubject $subject, string $field): mixed
    {
        return match ($field) {
            'amount' => $subject->amount,
            'category' => $subject->category,
            'company_unit_id' => $subject->companyUnitId,
            'method' => $subject->method,
            'subtype' => $subject->subtype,
            'risk_level' => $subject->riskLevel,
            'status' => $subject->status,
            'supplier_id' => $subject->supplierId,
            'budget_id' => $subject->budgetId,
            default => $subject->field($field),
        };
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return strcasecmp($a, $b) === 0;
        }

        return (string) $a === (string) $b;
    }

    private function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $candidate) {
            if ($this->looseEquals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function numeric(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
