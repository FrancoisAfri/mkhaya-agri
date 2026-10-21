<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use InvalidArgumentException;

/**
 * The metric formulas a production type is judged by — "feed_kg / live_weight_kg",
 * "current_count / opening_count * 100" — evaluated without eval().
 *
 * A formula is configuration a tenant can edit, so it is parsed, not executed: numbers, variable
 * names, + - * /, unary minus and brackets, nothing else. A division by zero or a variable with
 * no value gives null — "not measurable yet" — rather than a number that looks like an answer.
 */
final class Formula
{
    private array $tokens = [];

    private int $pos = 0;

    private array $vars = [];

    /** @param  array<string, float|int|null>  $vars */
    public static function evaluate(string $formula, array $vars): ?float
    {
        $f = new self;
        $f->tokens = self::tokenize($formula);
        $f->vars = $vars;
        $f->pos = 0;
        try {
            $value = $f->expression();
        } catch (\DivisionByZeroError) {
            return null;
        }
        if ($f->pos !== count($f->tokens)) {
            throw new InvalidArgumentException("Unexpected '{$f->tokens[$f->pos]}' in the formula.");
        }

        return $value === null || ! is_finite($value) ? null : round($value, 4);
    }

    /** The variable names a formula uses, to check a new formula only asks for what is measured. */
    public static function variables(string $formula): array
    {
        return array_values(array_unique(array_filter(self::tokenize($formula), fn ($t) => preg_match('/^[a-z_][a-z0-9_]*$/i', $t))));
    }

    public static function validate(string $formula, array $known): void
    {
        self::evaluate($formula, array_fill_keys($known, 1));
        $unknown = array_diff(self::variables($formula), $known);
        if ($unknown) {
            throw new InvalidArgumentException('The formula uses '.implode(', ', $unknown).', which is not measured. Use: '.implode(', ', $known).'.');
        }
    }

    private static function tokenize(string $s): array
    {
        if (! preg_match_all('/\s*(\d+(?:\.\d+)?|[a-z_][a-z0-9_]*|[-+*\/()])\s*/Ai', $s, $m) || implode('', $m[0]) !== $s) {
            throw new InvalidArgumentException('A formula may use numbers, measured names, + - * / and brackets only.');
        }

        return $m[1];
    }

    private function expression(): ?float
    {
        $v = $this->term();
        while (in_array($this->tokens[$this->pos] ?? null, ['+', '-'], true)) {
            $op = $this->tokens[$this->pos++];
            $r = $this->term();
            $v = $v === null || $r === null ? null : ($op === '+' ? $v + $r : $v - $r);
        }

        return $v;
    }

    private function term(): ?float
    {
        $v = $this->factor();
        while (in_array($this->tokens[$this->pos] ?? null, ['*', '/'], true)) {
            $op = $this->tokens[$this->pos++];
            $r = $this->factor();
            if ($op === '/' && $r !== null && abs($r) < 1e-12) {
                throw new \DivisionByZeroError;
            }
            $v = $v === null || $r === null ? null : ($op === '*' ? $v * $r : $v / $r);
        }

        return $v;
    }

    private function factor(): ?float
    {
        $t = $this->tokens[$this->pos++] ?? throw new InvalidArgumentException('The formula ends too early.');
        if ($t === '-') {
            $v = $this->factor();

            return $v === null ? null : -$v;
        }
        if ($t === '(') {
            $v = $this->expression();
            if (($this->tokens[$this->pos++] ?? null) !== ')') {
                throw new InvalidArgumentException('A bracket in the formula is not closed.');
            }

            return $v;
        }
        if (is_numeric($t)) {
            return (float) $t;
        }
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $t)) {
            $v = $this->vars[$t] ?? null;

            return $v === null ? null : (float) $v;
        }
        throw new InvalidArgumentException("Unexpected '{$t}' in the formula.");
    }
}

