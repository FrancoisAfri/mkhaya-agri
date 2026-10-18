<?php

namespace App\Modules\Operations\Sheq\Ai;

/**
 * Strips personal information out of text before it leaves the tenant boundary (SHEQ-AI-5).
 *
 * Incident descriptions are the worst case in the whole product for this. They are free text,
 * written in a hurry, often by the injured person's supervisor, and they routinely contain a full
 * name, an ID number and a phone number in the first sentence — "Thabo Mokoena (8501015800083) was
 * taken to hospital, next of kin 082 555 1234". Sending that to a third-party inference provider is
 * a cross-border transfer of special-personal-information under POPIA, and health information about
 * an identifiable person is exactly the category POPIA guards hardest.
 *
 * The approach is placeholder substitution rather than deletion. `[PERSON_1]` keeps the text
 * coherent — the model can still reason that the same person appears twice in a sentence, which is
 * often what the analysis turns on — while the mapping back to the real name stays on our side and
 * is applied to the output. The provider never holds both halves.
 *
 * What this does not claim: it will not catch a name it was not told about. Names are not a pattern.
 * That is why {@see redactWithNames()} takes the names from the record being discussed, where they
 * are known exactly, instead of guessing at capitalised words and mangling "Grinding Machine".
 */
class Redactor
{
    /**
     * South African identity number: 13 digits, sometimes spaced or hyphenated.
     *
     * Matched before phone numbers, because an unspaced ID number contains digit runs that a looser
     * phone pattern would otherwise claim first and half-redact.
     */
    private const SA_ID = '/\b(\d{6}[\s-]?\d{4}[\s-]?\d{3})\b/';

    private const EMAIL = '/\b[\w.+-]+@[\w-]+\.[\w.-]+\b/i';

    /** SA mobile and landline, with or without +27, spaces, hyphens or brackets. */
    private const PHONE = '/(?:\+27|0)\s?(?:\d[\s-]?){8,9}\d\b/';

    private const PASSPORT = '/\b[A-Z]{1,2}\d{6,9}\b/';

    /** A bank account long enough to move money with. */
    private const ACCOUNT = '/\b\d{9,12}\b/';

    /**
     * Redact structured identifiers.
     *
     * @return array{text:string, map:array<string,string>, count:int}
     */
    public function redact(string $text): array
    {
        $map = [];
        $counters = [];

        $replace = function (string $pattern, string $label) use (&$text, &$map, &$counters): void {
            $text = preg_replace_callback($pattern, function (array $m) use ($label, &$map, &$counters) {
                $original = $m[0];

                // The same identifier twice gets the same token — otherwise the model sees two
                // different people where the text described one.
                $existing = array_search($original, $map, true);

                if ($existing !== false) {
                    return $existing;
                }

                $counters[$label] = ($counters[$label] ?? 0) + 1;
                $token = "[{$label}_{$counters[$label]}]";
                $map[$token] = $original;

                return $token;
            }, $text) ?? $text;
        };

        // Order matters: the most specific pattern claims its text first.
        $replace(self::SA_ID, 'ID');
        $replace(self::EMAIL, 'EMAIL');
        $replace(self::PHONE, 'PHONE');
        $replace(self::PASSPORT, 'PASSPORT');
        $replace(self::ACCOUNT, 'ACCOUNT');

        return ['text' => $text, 'map' => $map, 'count' => count($map)];
    }

    /**
     * Redact identifiers plus a known set of names.
     *
     * The names come from the record — the injured person, the witnesses, the supervisor — where we
     * hold them as data rather than having to find them in prose. Longest first, so redacting
     * "Thabo" does not leave a dangling "Mokoena" behind in "Thabo Mokoena".
     *
     * @param  list<string>  $names
     * @return array{text:string, map:array<string,string>, count:int}
     */
    public function redactWithNames(string $text, array $names): array
    {
        $result = $this->redact($text);
        $text = $result['text'];
        $map = $result['map'];

        $names = array_values(array_filter(array_unique(array_map('trim', $names)),
            // Two characters is not a name worth matching; it is a substring waiting to corrupt
            // every word that contains it.
            fn ($n) => mb_strlen($n) > 2));

        usort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $index = 0;

        foreach ($names as $name) {
            $pattern = '/\b'.preg_quote($name, '/').'\b/iu';

            if (! preg_match($pattern, $text)) {
                continue;
            }

            $token = '[PERSON_'.(++$index).']';
            $text = preg_replace($pattern, $token, $text) ?? $text;
            $map[$token] = $name;
        }

        return ['text' => $text, 'map' => $map, 'count' => count($map)];
    }

    /**
     * Put the real values back into model output.
     *
     * Without this the practitioner reads "[PERSON_1] failed to isolate the machine", which is
     * useless to act on. The substitution happens here, inside the tenant boundary, after the
     * provider has already returned.
     *
     * @param  array<string,string>  $map
     */
    public function restore(string $text, array $map): string
    {
        return $map === [] ? $text : strtr($text, $map);
    }

    /**
     * Does this text still carry something that looks like a personal identifier?
     *
     * Used as an assertion at the boundary rather than a step in the pipeline: if redaction has a
     * gap, the call should fail rather than quietly leak. Tests assert on it directly.
     */
    public function containsIdentifiers(string $text): bool
    {
        foreach ([self::SA_ID, self::EMAIL, self::PHONE, self::PASSPORT] as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
