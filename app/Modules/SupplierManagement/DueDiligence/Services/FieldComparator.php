<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use Illuminate\Support\Str;

/**
 * Decides whether what a supplier told us matches what a source says.
 *
 * This is the part of due diligence that is easy to get wrong in both directions. Compare raw
 * strings and "ABC Holdings (Pty) Ltd" mismatches "ABC HOLDINGS PTY LTD", so every real supplier
 * looks fraudulent and staff learn to click past the warning. Compare too loosely and "ABC Trading"
 * passes as "ABC Holdings", which is precisely the substitution a mandate fraud relies on.
 *
 * So each field type gets its own rule, and anything that is close-but-not-equal is reported as a
 * partial match for a person to look at rather than being decided by a threshold.
 */
class FieldComparator
{
    /** Company-form suffixes that carry no identifying information. */
    private const ENTITY_SUFFIXES = [
        'proprietary limited', 'pty ltd', 'pty limited', 'pty', 'ltd', 'limited', 'inc',
        'incorporated', 'cc', 'close corporation', 'npc', 'soc', 'trust', 'partnership',
        'llp', 'plc', 'sa', 'co',
    ];

    /** Honorifics and generational suffixes, which differ between a registry and a form all the time. */
    private const NAME_NOISE = ['mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'adv', 'rev', 'jr', 'snr', 'sr', 'ii', 'iii'];

    /**
     * Compare a claim against an observation, field by field.
     *
     * Only fields present in BOTH are compared: a source that does not return a VAT number is not
     * evidence that the supplier's VAT number is wrong. Fields the source returned and we never
     * claimed are reported as `informational` — new directors arrive this way.
     *
     * @param  array<string,mixed>  $claimed
     * @param  array<string,mixed>  $observed
     * @return array<string, array{match:bool|null, kind:string, claimed:mixed, observed:mixed, note:string|null}>
     */
    public function compare(array $claimed, array $observed): array
    {
        $result = [];

        foreach ($observed as $field => $observedValue) {
            if (! array_key_exists($field, $claimed) || $this->blank($claimed[$field])) {
                $result[$field] = [
                    'match' => null,
                    'kind' => 'informational',
                    'claimed' => null,
                    'observed' => $observedValue,
                    'note' => 'Reported by the source; not claimed by the supplier.',
                ];

                continue;
            }

            $result[$field] = $this->compareField($field, $claimed[$field], $observedValue);
        }

        return $result;
    }

    /** Did every compared field agree? Informational fields do not count against a match. */
    public function allMatched(array $comparison): bool
    {
        foreach ($comparison as $field) {
            if ($field['match'] === false) {
                return false;
            }
        }

        return true;
    }

    /** Fields that agreed loosely enough to need a human, but not loosely enough to fail outright. */
    public function partials(array $comparison): array
    {
        return array_keys(array_filter($comparison, fn ($f) => ($f['kind'] ?? null) === 'partial'));
    }

    private function compareField(string $field, mixed $claimed, mixed $observed): array
    {
        [$kind, $matched, $note] = match (true) {
            $this->isRegistrationField($field) => $this->compareRegistration($claimed, $observed),
            $this->isNameField($field) => $this->compareName($field, $claimed, $observed),
            $this->isDateField($field) => $this->compareDate($claimed, $observed),
            default => $this->compareScalar($claimed, $observed),
        };

        return [
            'match' => $matched,
            'kind' => $kind,
            'claimed' => $claimed,
            'observed' => $observed,
            'note' => $note,
        ];
    }

    /**
     * Registration, tax and VAT numbers: digits only, because 2019/123456/07 and 201912345607 are
     * the same company and the difference is a formatting convention nobody agrees on.
     */
    private function compareRegistration(mixed $claimed, mixed $observed): array
    {
        $a = preg_replace('/\D+/', '', (string) $claimed);
        $b = preg_replace('/\D+/', '', (string) $observed);

        if ($a === '' || $b === '') {
            return ['unknown', null, 'One side is empty.'];
        }

        return $a === $b
            ? ['exact', true, null]
            : ['mismatch', false, 'The numbers differ once formatting is removed.'];
    }

    /**
     * Names: normalised, then compared exactly, then as a containment, then by similarity.
     *
     * A bank-account holder name is the strictest case in the system — a partial match there is the
     * signature of payment redirection — so the caller is told it is partial and the banking gate
     * treats partial as a stop.
     */
    private function compareName(string $field, mixed $claimed, mixed $observed): array
    {
        if (trim((string) $claimed) === '' || trim((string) $observed) === '') {
            return ['unknown', null, 'One side is empty.'];
        }

        // An account holder is a company for most suppliers and a person for a sole proprietor, and
        // nothing in the field name says which. So both readings are tried and the kinder result wins:
        // normalising "ABC HOLDINGS PROPRIETARY LIMITED" as a person's name leaves the entity form in
        // place and reports a real supplier as a mismatch, which teaches staff to click past the one
        // warning that stops payment redirection.
        $readings = $this->isHolderField($field)
            ? ['entity', 'person']
            : ($this->isPersonField($field) ? ['person'] : ['entity']);

        $best = null;

        foreach ($readings as $reading) {
            $result = $this->compareNormalised(
                $reading === 'person' ? $this->normalisePersonName((string) $claimed) : $this->normaliseEntityName((string) $claimed),
                $reading === 'person' ? $this->normalisePersonName((string) $observed) : $this->normaliseEntityName((string) $observed),
            );

            if ($result[0] === 'exact') {
                return $result;
            }

            $best = $this->better($best, $result);
        }

        return $best ?? ['mismatch', false, 'The names do not correspond.'];
    }

    /** @return array{0:string,1:bool|null,2:string|null} */
    private function compareNormalised(string $a, string $b): array
    {
        if ($a === '' || $b === '') {
            return ['unknown', null, 'One side is empty.'];
        }

        if ($a === $b) {
            return ['exact', true, null];
        }

        // One name wholly inside the other: "ABC Holdings" vs "ABC Holdings Group". Same family,
        // possibly not the same entity — a person decides.
        if (Str::contains($a, $b) || Str::contains($b, $a)) {
            return ['partial', null, 'One name contains the other — confirm they are the same entity.'];
        }

        similar_text($a, $b, $percent);

        if ($percent >= 88.0) {
            return ['partial', null, sprintf('Names are %d%% similar — confirm this is the same entity.', (int) $percent)];
        }

        return ['mismatch', false, 'The names do not correspond.'];
    }

    /** Of two readings of the same pair of names, the one that claims more agreement. */
    private function better(?array $current, array $candidate): array
    {
        $rank = ['mismatch' => 0, 'unknown' => 1, 'partial' => 2, 'exact' => 3];

        if ($current === null) {
            return $candidate;
        }

        return ($rank[$candidate[0]] ?? 0) > ($rank[$current[0]] ?? 0) ? $candidate : $current;
    }

    private function isHolderField(string $field): bool
    {
        return (bool) preg_match('/holder/i', $field);
    }

    private function isPersonField(string $field): bool
    {
        return (bool) preg_match('/(director|person|shareholder|owner|trustee)/i', $field);
    }

    private function compareDate(mixed $claimed, mixed $observed): array
    {
        try {
            $a = \Illuminate\Support\Carbon::parse((string) $claimed)->toDateString();
            $b = \Illuminate\Support\Carbon::parse((string) $observed)->toDateString();
        } catch (\Throwable) {
            return $this->compareScalar($claimed, $observed);
        }

        return $a === $b ? ['exact', true, null] : ['mismatch', false, "Source says {$b}."];
    }

    private function compareScalar(mixed $claimed, mixed $observed): array
    {
        $a = Str::lower(trim((string) $claimed));
        $b = Str::lower(trim((string) $observed));

        if ($a === '' || $b === '') {
            return ['unknown', null, 'One side is empty.'];
        }

        return $a === $b ? ['exact', true, null] : ['mismatch', false, "Source says \"{$observed}\"."];
    }

    /** Lower-cased, punctuation stripped, entity form removed, whitespace collapsed. */
    public function normaliseEntityName(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = str_replace('&', ' and ', $value);
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        // Suffixes are stripped repeatedly: "ABC Holdings Pty Ltd" carries two of them.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::ENTITY_SUFFIXES as $suffix) {
                if (Str::endsWith($value, ' '.$suffix)) {
                    $value = trim(Str::beforeLast($value, ' '.$suffix));
                    $changed = true;
                }
            }
        }

        return $value;
    }

    /**
     * Person names, normalised so word order does not decide the answer: registries return
     * "SMITH, John Andrew" where a form says "John Smith".
     */
    public function normalisePersonName(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^a-z\s]/', ' ', $value);
        $parts = array_values(array_filter(
            preg_split('/\s+/', (string) $value) ?: [],
            fn ($p) => $p !== '' && ! in_array($p, self::NAME_NOISE, true)
        ));

        sort($parts);

        return implode(' ', $parts);
    }

    private function isRegistrationField(string $field): bool
    {
        return (bool) preg_match('/(registration|reg_no|tax_reference|vat|account_number|supplier_number|id_number|passport)/i', $field);
    }

    private function isNameField(string $field): bool
    {
        return (bool) preg_match('/(name|director|person)/i', $field);
    }

    private function isDateField(string $field): bool
    {
        return (bool) preg_match('/(date|_at|established|appointed|expiry)/i', $field);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }
}
