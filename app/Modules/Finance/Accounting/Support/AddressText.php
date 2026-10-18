<?php

namespace App\Modules\Finance\Accounting\Support;

/**
 * A VAT number typed into an address field.
 *
 * Until an invoice had somewhere to print a VAT number, the only way anybody
 * could get one onto the document was to append it to the address — so
 * addresses across the book read "P.O. Box 6377, Secunda, 2302, VAT NO:
 * 4370228530". Now that the number has a field of its own, printing the
 * address verbatim would show it twice.
 *
 * This lifts the number out. It is deliberately conservative: it only matches a
 * recognised label followed by a plausible number, so an address that merely
 * contains a digit run — a box number, a street number, a postcode — is left
 * exactly as it was.
 */
final class AddressText
{
    /**
     * Label, optional punctuation, then 8-15 digits (SA VAT numbers are 10),
     * allowing the spacing people actually type.
     */
    private const PATTERN = '/(?:^|[,;|\-\/]|\s)\s*(?:vat|tax)[\s.]*(?:no|nr|number|reg(?:istration)?)?[\s.:#-]*([0-9][0-9\s-]{6,18}[0-9])/i';

    /** The VAT number buried in a piece of text, or null when there is none. */
    public static function taxNumberIn(?string $text): ?string
    {
        if (! filled($text) || ! preg_match(self::PATTERN, $text, $m)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $m[1]);

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    /** The same text with the VAT number, its label and any orphaned punctuation removed. */
    public static function withoutTaxNumber(?string $text): ?string
    {
        if (! filled($text) || self::taxNumberIn($text) === null) {
            return $text;
        }

        $clean = preg_replace(self::PATTERN, '', $text);

        // Tidy what the removal left behind: doubled separators, and a trailing comma.
        $clean = preg_replace('/\s*,\s*,+/', ',', (string) $clean);
        $clean = preg_replace('/[ \t]+/', ' ', (string) $clean);
        $clean = preg_replace('/\s*\n\s*\n+/', "\n", (string) $clean);
        $clean = trim((string) $clean, " \t\n\r,;|-");

        return $clean === '' ? null : $clean;
    }
}
