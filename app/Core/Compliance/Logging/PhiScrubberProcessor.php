<?php

namespace App\Core\Compliance\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Strips PHI patterns from log messages before they reach any handler.
 *
 * Patterns covered:
 *   - SA ID numbers (13-digit sequences matching the Luhn-adjacent SARS format)
 *   - Passport numbers (SA: A-Z followed by 8 digits)
 *   - Email addresses
 *   - SA phone numbers (+27 / 0xx patterns)
 *   - Encrypted field ciphertext blobs (base64 strings > 60 chars — Laravel encrypt() output)
 */
class PhiScrubberProcessor implements ProcessorInterface
{
    private const PATTERNS = [
        // SA ID number: 13 digits (YYMMDD + 4 + 1 + 1 + Z)
        '/\b(\d{13})\b/'                                   => '[SA-ID-REDACTED]',
        // SA passport: one uppercase letter + 8 digits
        '/\b([A-Z]\d{8})\b/'                               => '[PASSPORT-REDACTED]',
        // Email addresses
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/' => '[EMAIL-REDACTED]',
        // SA mobile: +27 or 0 + 9 digits
        '/(\+27|0)[6-8][0-9]{8}\b/'                        => '[PHONE-REDACTED]',
        // Laravel encrypt() output — eyJ... base64 blob longer than 60 chars
        '/eyJ[A-Za-z0-9+\/=]{60,}/'                        => '[ENCRYPTED-REDACTED]',
        // Income tax / TRN: 10 digits
        '/\b([0-9]{10})\b/'                                => '[TRN-REDACTED]',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->scrub($record->message);
        $context = $this->scrubArray($record->context);
        $extra   = $this->scrubArray($record->extra);

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    private function scrub(string $value): string
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value);
        }
        return $value;
    }

    private function scrubArray(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = $this->scrub($value);
            }
        });
        return $data;
    }
}
