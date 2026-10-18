<?php

namespace App\Modules\Operations\WarehouseManagement\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Reads GS1 element strings (WMS-012): GS1-128, GS1 DataMatrix and GS1 QR content, in either the
 * scanner's raw form (FNC1 sent as the GS character, ASCII 29, optionally behind a ]C1 / ]d2 / ]Q3
 * symbology prefix) or the human-readable form printed under the bars, "(01)…(10)…".
 *
 * Only the application identifiers a warehouse acts on are interpreted; any other AI is kept
 * verbatim in `other` rather than dropped, so nothing on the label is silently lost. A malformed
 * string is refused with the reason: guessing at a batch number is how the wrong lot gets picked.
 */
final class Gs1Parser
{
    private const GS = "\x1D";

    /** AI => [fixed length or null for variable, max length, field]. */
    private const AIS = [
        '00' => [18, 18, 'sscc'],
        '01' => [14, 14, 'gtin'],
        '02' => [14, 14, 'content_gtin'],
        '10' => [null, 20, 'batch'],
        '11' => [6, 6, 'production_date'],
        '13' => [6, 6, 'packaging_date'],
        '15' => [6, 6, 'best_before'],
        '17' => [6, 6, 'expiry'],
        '21' => [null, 20, 'serial'],
        '30' => [null, 8, 'count'],
        '37' => [null, 8, 'count'],
        '240' => [null, 30, 'additional_id'],
        '241' => [null, 30, 'customer_part'],
        '400' => [null, 30, 'order_number'],
    ];

    /** AIs whose length is fixed by GS1 even though we do not interpret them. */
    private const FIXED_UNINTERPRETED = ['12' => 6, '16' => 6, '20' => 2, '410' => 13, '411' => 13, '412' => 13, '413' => 13, '414' => 13, '415' => 13];

    /** Is this plausibly a GS1 element string rather than a bare product code? */
    public static function looksLikeGs1(string $raw): bool
    {
        $raw = self::stripPrefix($raw);

        return str_contains($raw, self::GS) || preg_match('/^\(\d{2,4}\)/', $raw) === 1
            || (preg_match('/^(01|02)\d{14}(10|11|13|15|17|21|30|37)/', $raw) === 1);
    }

    /**
     * @return array{gtin:?string, content_gtin:?string, sscc:?string, batch:?string, serial:?string,
     *   production_date:?string, packaging_date:?string, best_before:?string, expiry:?string,
     *   count:?int, net_weight_kg:?float, other:array<string,string>}
     */
    public function parse(string $raw): array
    {
        $raw = trim(self::stripPrefix($raw));
        if ($raw === '') {
            throw new InvalidArgumentException('The scan is empty.');
        }

        $out = ['gtin' => null, 'content_gtin' => null, 'sscc' => null, 'batch' => null, 'serial' => null,
            'production_date' => null, 'packaging_date' => null, 'best_before' => null, 'expiry' => null,
            'count' => null, 'net_weight_kg' => null, 'additional_id' => null, 'customer_part' => null,
            'order_number' => null, 'other' => []];

        foreach ($this->elements($raw) as [$ai, $value]) {
            if (preg_match('/^310(\d)$/', $ai, $m)) {
                // Net weight in kg with an implied decimal point (AI 3100-3105).
                $out['net_weight_kg'] = (int) $value / (10 ** (int) $m[1]);

                continue;
            }

            $field = self::AIS[$ai][2] ?? null;
            if ($field === null) {
                $out['other'][$ai] = $value;

                continue;
            }

            $out[$field] = match ($field) {
                'gtin', 'content_gtin' => $this->checkedGtin($value),
                'sscc' => $this->checkedDigits($value, 'SSCC'),
                'production_date', 'packaging_date', 'best_before', 'expiry' => $this->date($value, $ai),
                'count' => (int) $value,
                default => $value,
            };
        }

        if ($out['gtin'] === null && $out['sscc'] === null && $out['content_gtin'] === null) {
            throw new InvalidArgumentException('The GS1 code carries no product identifier (01), content (02) or SSCC (00).');
        }

        return $out;
    }

    /** Validates a GTIN-8/12/13/14 check digit and returns it padded to 14 digits. */
    public static function normaliseGtin(string $code): ?string
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return null;
        }

        return self::checkDigitValid($code) ? str_pad($code, 14, '0', STR_PAD_LEFT) : null;
    }

    public static function checkDigitValid(string $digits): bool
    {
        $body = substr($digits, 0, -1);
        $sum = 0;
        $weight = 3;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - $sum % 10) % 10 === (int) substr($digits, -1);
    }

    /** @return list<array{0:string,1:string}> */
    private function elements(string $raw): array
    {
        // Human-readable form: (01)09501101530003(17)250101(10)ABC
        if (str_starts_with($raw, '(')) {
            if (! preg_match_all('/\((\d{2,4})\)([^(]*)/', $raw, $m, PREG_SET_ORDER)) {
                throw new InvalidArgumentException('The GS1 code could not be read.');
            }

            return array_map(fn ($x) => [$x[1], trim($x[2])], $m);
        }

        $elements = [];
        $i = 0;
        $len = strlen($raw);
        while ($i < $len) {
            if ($raw[$i] === self::GS) {
                $i++;

                continue;
            }
            [$ai, $fixed, $max] = $this->identify(substr($raw, $i));
            $i += strlen($ai);

            if ($fixed !== null) {
                $value = substr($raw, $i, $fixed);
                if (strlen($value) !== $fixed) {
                    throw new InvalidArgumentException("AI ({$ai}) should carry {$fixed} characters.");
                }
                $i += $fixed;
            } else {
                $end = strpos($raw, self::GS, $i);
                $value = $end === false ? substr($raw, $i) : substr($raw, $i, $end - $i);
                if (strlen($value) > $max) {
                    throw new InvalidArgumentException("AI ({$ai}) is longer than {$max} characters; a separator is probably missing.");
                }
                $i += strlen($value);
            }
            $elements[] = [$ai, $value];
        }

        return $elements;
    }

    /** @return array{0:string,1:?int,2:int} */
    private function identify(string $rest): array
    {
        foreach ([4, 3, 2] as $n) {
            $ai = substr($rest, 0, $n);
            if ($n === 4 && preg_match('/^310\d$/', $ai)) {
                return [$ai, 6, 6];
            }
            if (isset(self::AIS[$ai])) {
                return [$ai, self::AIS[$ai][0], self::AIS[$ai][1]];
            }
            if (isset(self::FIXED_UNINTERPRETED[$ai])) {
                return [$ai, self::FIXED_UNINTERPRETED[$ai], self::FIXED_UNINTERPRETED[$ai]];
            }
        }

        throw new InvalidArgumentException('Unrecognised application identifier at "'.substr($rest, 0, 4).'".');
    }

    private function checkedGtin(string $value): string
    {
        $gtin = self::normaliseGtin($value);
        if ($gtin === null) {
            throw new InvalidArgumentException("GTIN {$value} has a wrong check digit.");
        }

        return $gtin;
    }

    private function checkedDigits(string $value, string $what): string
    {
        if (! ctype_digit($value) || ! self::checkDigitValid($value)) {
            throw new InvalidArgumentException("{$what} {$value} has a wrong check digit.");
        }

        return $value;
    }

    /** YYMMDD; DD = 00 means the last day of the month (GS1 General Specifications 7.12). */
    private function date(string $value, string $ai): string
    {
        if (! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new InvalidArgumentException("AI ({$ai}) date {$value} is not a valid YYMMDD.");
        }

        // GS1 century rule simplified to the window that matters for live stock: 51-99 => 19xx.
        $year = ((int) $m[1] >= 51 ? 1900 : 2000) + (int) $m[1];
        $date = Carbon::create($year, (int) $m[2], 1);
        if ((int) $m[3] === 0) {
            return $date->endOfMonth()->toDateString();
        }
        if ((int) $m[3] > $date->daysInMonth) {
            throw new InvalidArgumentException("AI ({$ai}) date {$value} is not a real date.");
        }

        return $date->day((int) $m[3])->toDateString();
    }

    private static function stripPrefix(string $raw): string
    {
        return preg_replace('/^\](C1|d2|Q3|e0)/', '', $raw) ?? $raw;
    }
}
