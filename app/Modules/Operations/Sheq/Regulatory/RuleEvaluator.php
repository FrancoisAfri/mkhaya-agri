<?php

namespace App\Modules\Operations\Sheq\Regulatory;

use InvalidArgumentException;

/**
 * Evaluates the declarative `when:` predicates used throughout a Regulatory Pack (SHEQ-REG-3).
 *
 * This is deliberately a tiny, closed language rather than an expression evaluator: packs are
 * authored by domain consultants (SHEQ-REG-10) and delivered without a code deploy (SHEQ-REG-8),
 * so pack content is *input*, and input that can execute arbitrary expressions is a vulnerability
 * with a compliance rulebook attached. Everything here is data comparison — no callables, no eval,
 * no property traversal into objects.
 *
 * Grammar:
 *
 *   when:
 *     any_of: [ <predicate>, ... ]        # OR
 *     all_of: [ <predicate>, ... ]        # AND  (alias: and)
 *     not: <predicate>
 *     <field>: <scalar>                   # equality
 *     <field>: { gte: n }                 # gte | gt | lte | lt | in | not_in | eq | ne | exists
 *
 * A bare map with several keys is an implicit AND, which is what makes the spec's own example read
 * naturally:
 *
 *   - and:
 *       - outcome: INJURY_OR_ILLNESS
 *       - likely_incapacity_days: { gte: 14 }
 *
 * Facts are a flat or dotted array. A missing fact is never an error — it simply fails to match,
 * because an incident reported in 60 seconds (SHEQ-INC-1) is mostly unknown at classification time
 * and must still classify to something.
 */
class RuleEvaluator
{
    private const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'exists', 'contains'];

    /**
     * @param  array<string,mixed>  $predicate
     * @param  array<string,mixed>  $facts
     */
    public function matches(array $predicate, array $facts): bool
    {
        foreach ($predicate as $key => $value) {
            if (! $this->clauseMatches((string) $key, $value, $facts)) {
                return false; // implicit AND across the keys of one map
            }
        }

        return true;
    }

    private function clauseMatches(string $key, mixed $value, array $facts): bool
    {
        switch ($key) {
            case 'any_of':
                foreach ($this->asPredicateList($value, 'any_of') as $branch) {
                    if ($this->matches($branch, $facts)) {
                        return true;
                    }
                }

                return false;

            case 'all_of':
            case 'and':
                foreach ($this->asPredicateList($value, $key) as $branch) {
                    if (! $this->matches($branch, $facts)) {
                        return false;
                    }
                }

                return true;

            case 'not':
                if (! is_array($value)) {
                    throw new InvalidArgumentException('`not` expects a predicate map.');
                }

                return ! $this->matches($value, $facts);

            default:
                return $this->fieldMatches($key, $value, $facts);
        }
    }

    private function fieldMatches(string $field, mixed $expected, array $facts): bool
    {
        $actual = $this->fact($facts, $field);

        // { field: { gte: 14 } } — an operator map. A plain array is a value, not an operator map.
        if (is_array($expected) && $this->isOperatorMap($expected)) {
            foreach ($expected as $operator => $operand) {
                if (! $this->compare((string) $operator, $actual, $operand)) {
                    return false;
                }
            }

            return true;
        }

        return $this->compare('eq', $actual, $expected);
    }

    private function compare(string $operator, mixed $actual, mixed $operand): bool
    {
        return match ($operator) {
            'eq' => $this->looseEquals($actual, $operand),
            'ne' => ! $this->looseEquals($actual, $operand),
            'exists' => ((bool) $operand) === ($actual !== null),
            // Comparisons against an unknown fact are false, never a PHP notice or a null-is-zero
            // surprise: "we do not know the incapacity days" must not satisfy "gte 14".
            'gt' => is_numeric($actual) && is_numeric($operand) && $actual > $operand,
            'gte' => is_numeric($actual) && is_numeric($operand) && $actual >= $operand,
            'lt' => is_numeric($actual) && is_numeric($operand) && $actual < $operand,
            'lte' => is_numeric($actual) && is_numeric($operand) && $actual <= $operand,
            'in' => is_array($operand) && $this->containsLoosely($operand, $actual),
            'not_in' => is_array($operand) && ! $this->containsLoosely($operand, $actual),
            'contains' => is_array($actual) && $this->containsLoosely($actual, $operand),
            default => throw new InvalidArgumentException("Unknown operator `{$operator}` in regulatory pack rule."),
        };
    }

    /**
     * Enum-ish values are compared case-insensitively so a pack authored with `FATALITY` still
     * matches a payload carrying `fatality`. Booleans and numbers keep strict-ish semantics.
     */
    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_string($actual) && is_string($expected)) {
            return strcasecmp($actual, $expected) === 0;
        }

        if (is_bool($expected) || is_bool($actual)) {
            return (bool) $actual === (bool) $expected;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return $actual === $expected;
    }

    private function containsLoosely(array $haystack, mixed $needle): bool
    {
        foreach ($haystack as $candidate) {
            if ($this->looseEquals($needle, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function isOperatorMap(array $value): bool
    {
        if ($value === [] || array_is_list($value)) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::OPERATORS, true)) {
                return false;
            }
        }

        return true;
    }

    /** Supports dotted paths so packs can read nested facts (`person.age`, `site.type`). */
    private function fact(array $facts, string $path): mixed
    {
        if (array_key_exists($path, $facts)) {
            return $facts[$path];
        }

        $value = $facts;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private function asPredicateList(mixed $value, string $key): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("`{$key}` expects a list of predicates.");
        }

        return $value;
    }
}
