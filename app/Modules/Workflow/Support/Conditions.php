<?php

namespace App\Modules\Workflow\Support;

use Carbon\CarbonImmutable;

/**
 * Evaluates routing conditions (PRS §7) without executing anything a designer wrote.
 *
 * A condition is a tree, the shape Procurement Governance's CustomExpressionHandler already uses:
 *   leaf        {field, operator, value}
 *   combinators {all: [...]}, {any: [...]}, {not: {...}}
 * Fields are dotted paths into the instance's data (form values, the record's exposed fields under
 * `record.`, and `requester.*`). Strings are compared, never evaluated; `matches` takes a named
 * pattern from an allow-list instead of a user-supplied regular expression.
 */
final class Conditions
{
    public const OPERATORS = [
        'eq' => 'equals', 'neq' => 'does not equal',
        'gt' => 'is greater than', 'gte' => 'is at least', 'lt' => 'is less than', 'lte' => 'is at most',
        'contains' => 'contains', 'not_contains' => 'does not contain',
        'starts_with' => 'starts with', 'ends_with' => 'ends with',
        'empty' => 'is empty', 'not_empty' => 'is not empty',
        'in' => 'is one of', 'not_in' => 'is not one of',
        'before' => 'is before (date)', 'after' => 'is after (date)',
        'between' => 'is between', 'true' => 'is yes', 'false' => 'is no',
        'matches' => 'matches pattern',
    ];

    /** Named patterns only (§18: validate expressions; no user-supplied regex). */
    public const PATTERNS = [
        'email' => '/^[^@\s]+@[^@\s]+\.[^@\s]+$/',
        'sa_id_number' => '/^\d{13}$/',
        'sa_vat_number' => '/^4\d{9}$/',
        'phone' => '/^\+?\d[\d\s-]{6,}$/',
        'digits' => '/^\d+$/',
        'reference' => '/^[A-Z]{2,5}-?\d{2,}/',
    ];

    private const MAX_DEPTH = 10;

    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>  $data
     */
    public static function evaluate(?array $condition, array $data, int $depth = 0): bool
    {
        if ($condition === null || $condition === []) {
            return true;
        }
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Condition nested too deeply.');
        }
        if (isset($condition['all'])) {
            foreach ((array) $condition['all'] as $c) {
                if (! self::evaluate($c, $data, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($condition['any'])) {
            foreach ((array) $condition['any'] as $c) {
                if (self::evaluate($c, $data, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }
        if (array_key_exists('not', $condition)) {
            return ! self::evaluate($condition['not'], $data, $depth + 1);
        }

        return self::leaf((string) ($condition['field'] ?? ''), (string) ($condition['operator'] ?? 'eq'), $condition['value'] ?? null, $data);
    }

    /**
     * Problems with a condition, for the designer's validation (§4.3). Empty when it is sound.
     *
     * @return list<string>
     */
    public static function problems(?array $condition, int $depth = 0): array
    {
        if ($condition === null || $condition === []) {
            return [];
        }
        if ($depth > self::MAX_DEPTH) {
            return ['The condition is nested more than ' . self::MAX_DEPTH . ' levels deep.'];
        }
        foreach (['all', 'any'] as $k) {
            if (isset($condition[$k])) {
                if (! is_array($condition[$k]) || $condition[$k] === []) {
                    return ["An \"{$k}\" group needs at least one condition."];
                }

                return array_merge(...array_map(fn ($c) => self::problems(is_array($c) ? $c : null, $depth + 1), $condition[$k]));
            }
        }
        if (array_key_exists('not', $condition)) {
            return self::problems(is_array($condition['not']) ? $condition['not'] : null, $depth + 1);
        }
        $out = [];
        if (blank($condition['field'] ?? null)) {
            $out[] = 'A condition has no field.';
        } elseif (! preg_match('/^[A-Za-z_][A-Za-z0-9_.]{0,79}$/', (string) $condition['field'])) {
            $out[] = "\"{$condition['field']}\" is not a valid field name.";
        }
        $op = $condition['operator'] ?? null;
        if (! isset(self::OPERATORS[$op])) {
            $out[] = 'Unknown operator "' . $op . '".';
        } elseif ($op === 'matches' && ! isset(self::PATTERNS[$condition['value'] ?? ''])) {
            $out[] = 'Pattern must be one of: ' . implode(', ', array_keys(self::PATTERNS)) . '.';
        } elseif ($op === 'between' && (! is_array($condition['value'] ?? null) || count($condition['value']) !== 2)) {
            $out[] = '"Between" needs a lower and an upper value.';
        }

        return $out;
    }

    /** Plain-language description of a condition, for explanations and the audit trail. */
    public static function describe(?array $condition): string
    {
        if ($condition === null || $condition === []) {
            return 'always';
        }
        if (isset($condition['all'])) {
            return '(' . implode(' and ', array_map(fn ($c) => self::describe($c), $condition['all'])) . ')';
        }
        if (isset($condition['any'])) {
            return '(' . implode(' or ', array_map(fn ($c) => self::describe($c), $condition['any'])) . ')';
        }
        if (array_key_exists('not', $condition)) {
            return 'not ' . self::describe($condition['not']);
        }
        $v = $condition['value'] ?? null;
        $v = is_array($v) ? implode(' – ', $v) : (string) $v;

        return trim(($condition['field'] ?? '?') . ' ' . (self::OPERATORS[$condition['operator'] ?? ''] ?? '?') . ' ' . $v);
    }

    private static function leaf(string $field, string $op, mixed $expected, array $data): bool
    {
        $actual = data_get($data, $field);

        return match ($op) {
            'eq' => self::same($actual, $expected),
            'neq' => ! self::same($actual, $expected),
            'gt' => self::cmp($actual, $expected) === 1,
            'gte' => in_array(self::cmp($actual, $expected), [0, 1], true),
            'lt' => self::cmp($actual, $expected) === -1,
            'lte' => in_array(self::cmp($actual, $expected), [0, -1], true),
            'contains' => is_array($actual) ? in_array($expected, $actual) : str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => ! (is_array($actual) ? in_array($expected, $actual) : str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected))),
            'starts_with' => str_starts_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'ends_with' => str_ends_with(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'empty' => blank($actual),
            'not_empty' => ! blank($actual),
            'in' => in_array((string) $actual, array_map('strval', (array) $expected), true),
            'not_in' => ! in_array((string) $actual, array_map('strval', (array) $expected), true),
            'before' => self::date($actual) !== null && self::date($expected) !== null && self::date($actual) < self::date($expected),
            'after' => self::date($actual) !== null && self::date($expected) !== null && self::date($actual) > self::date($expected),
            'between' => is_array($expected) && count($expected) === 2 && self::between($actual, $expected[0], $expected[1]),
            'true' => filter_var($actual, FILTER_VALIDATE_BOOLEAN) === true,
            'false' => filter_var($actual, FILTER_VALIDATE_BOOLEAN) === false,
            'matches' => isset(self::PATTERNS[$expected]) && preg_match(self::PATTERNS[$expected], (string) $actual) === 1,
            default => false,
        };
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return bccomp((string) $a, (string) $b, 6) === 0;
        }
        if (is_bool($a) || is_bool($b)) {
            return filter_var($a, FILTER_VALIDATE_BOOLEAN) === filter_var($b, FILTER_VALIDATE_BOOLEAN);
        }

        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /** -1, 0, 1, or null when the values cannot be ordered (a missing amount never passes a threshold). */
    private static function cmp(mixed $a, mixed $b): ?int
    {
        if (blank($a) || blank($b)) {
            return null;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return bccomp((string) $a, (string) $b, 6);
        }
        $da = self::date($a);
        $db = self::date($b);
        if ($da && $db) {
            return $da <=> $db;
        }

        return null;
    }

    private static function between(mixed $v, mixed $lo, mixed $hi): bool
    {
        $a = self::cmp($v, $lo);
        $b = self::cmp($v, $hi);

        return $a !== null && $b !== null && $a >= 0 && $b <= 0;
    }

    private static function date(mixed $v): ?CarbonImmutable
    {
        if ($v === null || $v === '' || is_numeric($v) || is_array($v)) {
            return null;
        }
        if ($v === 'today') {
            return CarbonImmutable::today();
        }
        try {
            return CarbonImmutable::parse((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }
}
