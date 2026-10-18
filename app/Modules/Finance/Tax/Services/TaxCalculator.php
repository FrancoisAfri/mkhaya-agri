<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Models\TaxRuleVersion;

/**
 * The one place tax is calculated (§5.3). Screens, the register and reports call this; none of them
 * carries a formula of its own.
 *
 * Decimal arithmetic only: every amount is a numeric string and every operation is bcmath at a
 * working scale well beyond currency precision, rounded once at the end by the rule's own method.
 * Binary floating point never touches a tax amount.
 *
 * The result always says which state it is in. `status = calculated` with `tax = "0.00"` is a zero
 * tax; `status = invalid` or `no_rule` carries `tax = null`, and the two are never confused.
 */
class TaxCalculator
{
    private const SCALE = 10;

    /**
     * @param  string  $amount     the amount as entered, as a decimal string
     * @param  bool    $inclusive  whether $amount already includes the tax
     * @param  array{source?:string, override_ref?:string, date?:string}  $context
     * @return array{status:string, tax_code:?string, tax_type:?string, treatment:?string, taxable_base:?string, rate:?string,
     *               method:?string, tax:?string, unrounded_tax:?string, rounding:?string, precision:?int, gross:?string,
     *               rule_version_id:?int, rule_version:?int, effective_date:?string, warnings:list<string>,
     *               calculated_at:string, source:?string, override_ref:?string}
     */
    public function calculate(?TaxRuleVersion $rule, string $amount, bool $inclusive = false, array $context = []): array
    {
        $base = [
            'status' => 'calculated', 'tax_code' => $rule?->tax_code, 'tax_type' => $rule?->taxType?->code,
            'treatment' => $rule?->treatment, 'taxable_base' => null, 'rate' => $rule?->rate, 'method' => $rule?->method,
            'tax' => null, 'unrounded_tax' => null, 'rounding' => $rule?->rounding, 'precision' => $rule?->precision,
            'gross' => null, 'rule_version_id' => $rule?->id, 'rule_version' => $rule?->version,
            'effective_date' => $context['date'] ?? null, 'warnings' => [],
            'calculated_at' => now()->toIso8601String(),
            'source' => $context['source'] ?? null, 'override_ref' => $context['override_ref'] ?? null,
        ];

        if (! is_numeric($amount)) {
            return ['status' => 'invalid', 'warnings' => ['The amount is not a number.']] + $base;
        }
        if (! $rule) {
            return ['status' => 'no_rule', 'warnings' => ['No published rule is in force for this code on this date.']] + $base;
        }
        if ($rule->status !== 'published') {
            return ['status' => 'invalid', 'warnings' => ["Rule version {$rule->version} is {$rule->status}, not published."]] + $base;
        }
        if (isset($context['date']) && ! $rule->appliesOn($context['date'])) {
            return ['status' => 'invalid', 'warnings' => ['The rule is not in force on the transaction date.']] + $base;
        }

        $amount = $this->normalise($amount);
        $precision = (int) ($rule->precision ?? 2);

        // Treatments that carry no tax are calculated, and the answer is zero — not "missing".
        if ($rule->method === 'none' || in_array($rule->treatment, ['zero_rated', 'exempt', 'out_of_scope'], true) || bccomp((string) $rule->rate, '0', self::SCALE) === 0 && $rule->method === 'percentage') {
            $zero = $this->round('0', $precision, $rule->rounding);

            return array_merge($base, [
                'taxable_base' => $this->round($amount, $precision, 'half_up'),
                'tax' => $zero, 'unrounded_tax' => '0', 'gross' => $this->round($amount, $precision, 'half_up'),
            ]);
        }

        if ($rule->method === 'fixed') {
            $tax = $this->round((string) $rule->rate, $precision, $rule->rounding);
            $net = $inclusive ? bcsub($amount, $tax, self::SCALE) : $amount;

            return array_merge($base, [
                'taxable_base' => $this->round($net, $precision, 'half_up'), 'tax' => $tax, 'unrounded_tax' => (string) $rule->rate,
                'gross' => $this->round(bcadd($net, $tax, self::SCALE), $precision, 'half_up'),
            ]);
        }

        // Percentage. Inclusive amounts are split as tax = gross × r / (100 + r).
        $rate = $this->normalise((string) $rule->rate);
        if ($inclusive) {
            $unrounded = bcdiv(bcmul($amount, $rate, self::SCALE), bcadd('100', $rate, self::SCALE), self::SCALE);
            $tax = $this->round($unrounded, $precision, $rule->rounding);
            $net = bcsub($amount, $tax, self::SCALE);
            $gross = $amount;
        } else {
            $unrounded = bcdiv(bcmul($amount, $rate, self::SCALE), '100', self::SCALE);
            $tax = $this->round($unrounded, $precision, $rule->rounding);
            $net = $amount;
            $gross = bcadd($amount, $tax, self::SCALE);
        }

        return array_merge($base, [
            'taxable_base' => $this->round($net, $precision, 'half_up'),
            'tax' => $tax, 'unrounded_tax' => $this->trim($unrounded),
            'gross' => $this->round($gross, $precision, 'half_up'),
        ]);
    }

    /** Round a decimal string to $precision places by method, without floating point. */
    public function round(string $value, int $precision, ?string $method = 'half_up'): string
    {
        $value = $this->normalise($value);
        $negative = bccomp($value, '0', self::SCALE) < 0;
        $abs = $negative ? bcmul($value, '-1', self::SCALE) : $value;
        $factor = bcpow('10', (string) $precision, 0);
        $shifted = bcmul($abs, $factor, self::SCALE);
        $floor = bcadd($shifted, '0', 0);                       // truncate toward zero
        $fraction = bcsub($shifted, $floor, self::SCALE);

        $up = match ($method ?? 'half_up') {
            'down' => false,
            'up' => bccomp($fraction, '0', self::SCALE) > 0,
            'half_even' => bccomp($fraction, '0.5', self::SCALE) > 0
                || (bccomp($fraction, '0.5', self::SCALE) === 0 && ((int) substr($floor, -1)) % 2 === 1),
            default => bccomp($fraction, '0.5', self::SCALE) >= 0,  // half_up: away from zero
        };

        $result = $up ? bcadd($floor, '1', 0) : $floor;
        $result = bcdiv($result, $factor, $precision);

        return $negative && bccomp($result, '0', $precision) !== 0 ? '-' . $result : $result;
    }

    private function normalise(string $value): string
    {
        $value = trim($value);

        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }

    private function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
