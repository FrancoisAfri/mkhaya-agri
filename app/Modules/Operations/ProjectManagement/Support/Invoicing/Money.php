<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * Decimal arithmetic for the fee and disbursement accounts.
 *
 * ── Why not floats ─────────────────────────────────────────────────────────
 *
 * The workbook this reproduces is built on IEEE-754 doubles, and it shows: its own cached results
 * include 2116420.3680000002, 9931.581839999999 and 13750.000000000002. Those trailing digits are
 * noise, not money, and they compound — a professional fee account chains a percentage into an
 * apportionment into a progress figure into a VAT calculation, five multiplications deep, before
 * anybody reads a number off it.
 *
 * So every figure here is a decimal string handled by bcmath, per PM-ADR-006. This is a deliberate
 * *correction* rather than a faithful reproduction, and it is the one place the module knowingly
 * differs from the spreadsheet: a cell that reads 13750.000000000002 in Excel reads 13750.00 here.
 * Anything else would mean reproducing the defect and then rounding it away at the end, which is
 * the same answer with more steps and less confidence.
 *
 * ── Where rounding happens ─────────────────────────────────────────────────
 *
 * Intermediates are kept at SCALE, not rounded to the cent. The workbook does not round between
 * steps either, and rounding a stage fee before multiplying it by a progress percentage would
 * shift the claim by a few cents on a multi-million-rand account — small, but the kind of
 * difference a client's quantity surveyor queries. Rounding is applied when a figure is stored or
 * printed, and nowhere else.
 */
final class Money
{
    /** Working precision. Well beyond the cent, so five chained operations cannot drift into it. */
    public const SCALE = 8;

    /** What a stored or printed money value carries. */
    public const MONEY = 2;

    private function __construct()
    {
    }

    /** Normalise anything the caller has to hand into a decimal string. */
    public static function of(string|float|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            // The one permitted crossing point, for values arriving from a float source such as an
            // imported spreadsheet. Formatted rather than cast so 1.0E-5 does not reach bcmath as
            // the literal string "1.0E-5", which it would read as 1.
            return rtrim(rtrim(number_format($value, self::SCALE, '.', ''), '0'), '.') ?: '0';
        }

        return (string) $value;
    }

    public static function add(string|float|int|null $a, string|float|int|null $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    public static function sub(string|float|int|null $a, string|float|int|null $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function mul(string|float|int|null $a, string|float|int|null $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    public static function div(string|float|int|null $a, string|float|int|null $b): string
    {
        $divisor = self::of($b);

        if (bccomp($divisor, '0', self::SCALE) === 0) {
            // The workbook has a live #DIV/0! in it (Vat Invoice K34). A spreadsheet can print that
            // and carry on; an invoice cannot, so division by zero is zero rather than an error the
            // caller has to handle at every site.
            return '0';
        }

        return bcdiv(self::of($a), $divisor, self::SCALE);
    }

    /** @param  array<int, string|float|int|null>  $values */
    public static function sum(array $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = self::add($total, $value);
        }

        return $total;
    }

    /**
     * Half-up rounding, which bcmath does not provide — bcadd truncates.
     *
     * Half-up rather than banker's rounding because that is what the spreadsheet does and what an
     * invoice is expected to do; a half-cent on a certificate rounds up, in the claimant's favour.
     */
    public static function round(string|float|int|null $value, int $decimals = self::MONEY): string
    {
        $amount = self::of($value);
        $offset = bcdiv('5', bcpow('10', (string) ($decimals + 1), self::SCALE), self::SCALE);

        $adjusted = bccomp($amount, '0', self::SCALE) < 0
            ? bcsub($amount, $offset, self::SCALE)
            : bcadd($amount, $offset, self::SCALE);

        return bcadd($adjusted, '0', $decimals);
    }

    /** A rounded money string, the form that reaches a column or a database. */
    public static function money(string|float|int|null $value): string
    {
        return self::round($value, self::MONEY);
    }

    public static function isZero(string|float|int|null $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) === 0;
    }

    public static function compare(string|float|int|null $a, string|float|int|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    /** `$amount` × `$rate`, where the rate is a proportion (0.15), not a percentage (15). */
    public static function applyRate(string|float|int|null $amount, string|float|int|null $rate): string
    {
        return self::mul($amount, $rate);
    }

    /** `$amount` plus a surcharge — the annexures' 10%, written in the workbook as `× 110%`. */
    public static function withSurcharge(string|float|int|null $amount, string|float|int|null $rate): string
    {
        return self::mul($amount, self::add('1', $rate));
    }
}
