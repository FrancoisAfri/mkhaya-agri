<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use Illuminate\Support\Str;

/**
 * POPIA, made operational rather than promised.
 *
 * Director and beneficial-owner records are personal information about identifiable people, gathered
 * for one stated purpose. The Act's requirements are not vague, and each maps onto something this
 * code has to actually do:
 *
 *  - Minimality (s10). Identifiers are collected only where a check genuinely needs them. The
 *    screening matcher can work on name alone; an ID number improves accuracy, so it is asked for and
 *    not demanded.
 *  - Purpose limitation (s13-15). A supplier's director ID was given to verify and screen that
 *    supplier. Using it for anything else — marketing, enrichment, a model — is a new purpose that
 *    would need its own basis, which is why the redaction below is absolute rather than advisory.
 *  - Security safeguards (s19). Encrypted at rest, masked on screen, never in logs.
 *  - Retention (s14). Kept while the supplier relationship needs it, then destroyed.
 *  - Data subject participation (s23-25). A person can ask what is held about them, and have it
 *    corrected or deleted.
 *
 * The redaction is the part that earns this class. Everything else is a property of the schema; this
 * is a rule that has to hold at every call site, and the only way that is true is if the AI-facing
 * code cannot obtain the values in the first place.
 */
class PersonalDataGuard
{
    /** Fields that must never leave the system in the clear. */
    private const SENSITIVE = [
        'id_number', 'passport_number', 'date_of_birth', 'dob', 'tcs_pin',
        'bank_account_number', 'account_number', 'tax_reference_number',
    ];

    /**
     * Strip personal identifiers out of anything about to be handed to an AI provider.
     *
     * Called on the way into every prompt this module builds. An ID number in a prompt has left the
     * country, entered a third party's logs and possibly its training data, and no retention policy
     * of ours reaches it there — so the value never goes, and the fact that a value exists does.
     * "id_number: [redacted]" is enough for a model to reason about completeness, and useless to
     * anybody who intercepts it.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function redact(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $clean[$key] = $this->redact($value);

                continue;
            }

            $clean[$key] = $this->isSensitive((string) $key) && $this->present($value)
                ? '[redacted]'
                : $value;
        }

        return $clean;
    }

    /**
     * Strip identifiers out of free text.
     *
     * The array form covers structured payloads; this covers the ones assembled into a sentence
     * before anybody thought about it. South African ID numbers are thirteen digits and passports are
     * alphanumeric, so both are recognisable without knowing which field they came from.
     */
    public function redactText(string $text): string
    {
        // A 13-digit South African ID, with or without spacing.
        $text = (string) preg_replace('/\b\d{6}[\s-]?\d{4}[\s-]?\d{2}[\s-]?\d\b/', '[redacted id]', $text);

        // Bank account numbers: 9-13 digits standing alone.
        $text = (string) preg_replace('/\b\d{9,13}\b/', '[redacted number]', $text);

        return $text;
    }

    /** An empty field has nothing to redact, and "[redacted]" where there was nothing is a lie. */
    private function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    public function isSensitive(string $field): bool
    {
        $normalised = Str::lower($field);

        foreach (self::SENSITIVE as $sensitive) {
            if (Str::contains($normalised, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The lawful basis for holding a supplier's people, recorded rather than assumed.
     *
     * POPIA asks on what ground personal information is processed. For supplier due diligence it is
     * ordinarily legitimate interest together with legal obligation — anti-money-laundering and
     * procurement-governance duties are real and specific. Recording it per record means the answer
     * to "why do you have my ID number" is a stored fact rather than a lawyer's reconstruction.
     */
    public static function lawfulBases(): array
    {
        return [
            'legal_obligation' => 'Legal obligation — anti-money-laundering, sanctions and procurement law',
            'legitimate_interest' => 'Legitimate interest — verifying who we contract and pay',
            'contract' => 'Performance of a contract with the supplier',
            'consent' => 'Consent given by the person',
        ];
    }

    /**
     * How long a person's record is kept after the supplier relationship ends.
     *
     * Five years is the ordinary South African anti-money-laundering retention period for
     * verification records. It is a default, not a ceiling: a tenant with a different obligation sets
     * its own, and the point is that a period exists at all — "kept indefinitely because nobody chose"
     * is the position POPIA exists to end.
     */
    public const DEFAULT_RETENTION_YEARS = 5;
}
