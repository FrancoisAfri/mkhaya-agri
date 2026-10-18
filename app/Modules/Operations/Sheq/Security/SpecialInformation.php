<?php

namespace App\Modules\Operations\Sheq\Security;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encryption for POPIA special personal information (§9).
 *
 * The fields this covers — the nature of an injury, the body part affected, a witness statement,
 * an next-of-kin contact number — are health and personal information about identifiable people.
 * POPIA s26 treats health information as special personal information: the category where
 * processing is prohibited by default and permitted only on narrow grounds, and the one where a
 * breach is least defensible.
 *
 * What encrypting at the column level actually buys, since it is worth being precise: it does not
 * protect against a compromised application, which holds the key. It protects against every other
 * way this data escapes, which in practice is how it escapes — a database backup restored onto a
 * laptop, a read replica pointed at a reporting tool with looser access, a support engineer running
 * an ad-hoc query, a decommissioned disk. Those are the realistic incidents, and this closes them.
 *
 * **Reads tolerate plaintext.** Rows written before this existed are still plaintext, and a
 * migration that rewrote every historical row would be a long lock on a table under statutory
 * retention. Instead a value that will not decrypt is returned as-is: existing records keep
 * working, and everything written from now on is encrypted. New data is protected immediately
 * rather than after a backfill that might be deferred indefinitely.
 */
class SpecialInformation
{
    /**
     * The fields treated as special personal information.
     *
     * Listed here rather than at each call site so that adding a field is one edit, and so a
     * reviewer can see the full set of protected columns in one place. The set spans tables —
     * injury detail on `sheq_incident_people`, clinical findings on `sheq_medical_records` — because
     * what makes a column sensitive is what it contains, not which table it happens to live in.
     */
    public const PROTECTED_FIELDS = [
        // Incident involvement: what happened to a named person's body.
        'injury_nature', 'body_part', 'statement', 'external_contact',
        // Medical surveillance: the audiogram, the spirometry, the practitioner's note. The
        // fitness *outcome* is deliberately not here — a supervisor must be able to read that.
        'clinical_findings', 'practitioner_name',
    ];

    /** Encrypt a value for storage. Null and empty stay as they are — there is nothing to protect. */
    public function protect(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }

        return Crypt::encryptString($value);
    }

    /**
     * Read a stored value.
     *
     * Returns the input unchanged when it is not decryptable, which is how pre-encryption rows keep
     * working. The alternative — throwing — would mean a single legacy row breaks the incident
     * screen for a record that is under a forty-year retention obligation.
     */
    public function reveal(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    /**
     * Encrypt every protected field present in a row of attributes.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    public function protectRow(array $attributes): array
    {
        foreach (self::PROTECTED_FIELDS as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = $this->protect(
                    $attributes[$field] === null ? null : (string) $attributes[$field]
                );
            }
        }

        return $attributes;
    }

    /**
     * Decrypt every protected field on a fetched row.
     *
     * Accepts objects and arrays because the module reads through the query builder, which returns
     * stdClass, while some callers have already cast to array.
     *
     * @template T of object|array
     *
     * @param  T  $row
     * @return T
     */
    public function revealRow(object|array $row): object|array
    {
        foreach (self::PROTECTED_FIELDS as $field) {
            if (is_array($row)) {
                if (array_key_exists($field, $row)) {
                    $row[$field] = $this->reveal($row[$field] === null ? null : (string) $row[$field]);
                }

                continue;
            }

            if (property_exists($row, $field)) {
                $row->{$field} = $this->reveal($row->{$field} === null ? null : (string) $row->{$field});
            }
        }

        return $row;
    }

    /**
     * @param  iterable<object|array>  $rows
     * @return list<object|array>
     */
    public function revealAll(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[] = $this->revealRow($row);
        }

        return $out;
    }
}
