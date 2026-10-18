<?php

namespace App\Modules\Operations\Sheq\Sync;

/**
 * A child collection a field device may add rows to (REG-FIX-8).
 *
 * The three collections the requirement names — witness statements, evidence, causes — are the
 * ones two people genuinely fill in at the same incident at the same time, and that is why
 * record-level merge cannot carry them. Two devices each holding half of the witness statements is
 * the normal case at a serious incident, not an edge case: one person is with the injured worker
 * and another is taking statements at the gate.
 *
 * So these are **append-only and keyed on a client-generated uuid**, which gives three properties
 * that matter more here than anywhere else in the sync:
 *
 * **A row the server already has is left alone.** A device that retries its push does not
 * duplicate a statement, and a statement somebody has since signed server-side is not overwritten
 * by the unsigned copy still sitting on the phone.
 *
 * **A row the server does not have is inserted.** That is the whole point: the half of the
 * statements taken on the other device arrive without either device losing anything.
 *
 * **A row the CLIENT does not have is never deleted.** This is the one that would be catastrophic
 * and is the easiest to get wrong, because "sync the collection" reads naturally as "make the
 * server match the client". Doing that would delete the other device's statements the moment the
 * first one pushed, and the deletion would look like a successful sync.
 *
 * Nothing here can change an existing row. An amendment to a witness statement is a thing a person
 * does, with their name against it, on a screen — not something a phone does silently while
 * reconnecting.
 */
class ChildCollectionSpec
{
    /**
     * @param  string  $name  what the client calls this collection in its payload
     * @param  string  $table  where the rows live
     * @param  string  $parentKey  the column holding the parent entity's id
     * @param  list<string>  $writable  columns a device may set; everything else is ignored
     * @param  array<string,mixed>  $defaults  columns stamped on insert, not taken from the client
     * @param  list<string>  $required  columns that must be present and non-empty
     * @param  list<string>  $redacted  columns a device may write but may never read back
     */
    public function __construct(
        public readonly string $name,
        public readonly string $table,
        public readonly string $parentKey,
        public readonly array $writable,
        public readonly array $defaults = [],
        public readonly array $required = [],
        public readonly array $redacted = [],
        /**
         * Where the parent id comes from, when it is not the synced entity itself.
         *
         * Investigation causes hang off `sheq_investigations`, not off the incident — so a device
         * syncing an incident does not have the id the child row needs. This says how to get
         * there: `['table' => 'sheq_investigations', 'foreign' => 'incident_id']`.
         *
         * Where no such parent exists the rows are refused with a reason rather than dropped. A
         * root cause recorded before anybody has opened an investigation is not a finding, and
         * silently discarding it would be the exact data loss this requirement is about.
         *
         * @var array{table:string, foreign:string}|null
         */
        public readonly ?array $viaParent = null,
        public readonly bool $hasUuid = true,
        public readonly bool $softDeletes = true,
    ) {}

    /**
     * Strip a client row to the columns it is allowed to set.
     *
     * The same reasoning as `EntitySpec::only()`: a device cannot be allowed to reach a column it
     * has no business writing. On `sheq_incident_people` that includes `statement_signed_at` — a
     * phone must not be able to assert that a statement was signed.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public function only(array $row): array
    {
        return array_intersect_key($row, array_flip($this->writable));
    }

    /**
     * Whether a client row has everything it needs.
     *
     * A witness with no name and no person id is not a witness, and inserting it would put a blank
     * line in the statement list of a serious incident.
     *
     * @param  array<string,mixed>  $row
     * @return list<string>  the missing columns
     */
    public function missing(array $row): array
    {
        $missing = [];

        foreach ($this->required as $column) {
            if (! array_key_exists($column, $row)
                || $row[$column] === null
                || (is_string($row[$column]) && trim($row[$column]) === '')) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    /**
     * Columns safe to send back to a device.
     *
     * Witness statements and injury detail are special personal information under POPIA and are
     * encrypted at column level. A device that uploaded one has no need to download everybody
     * else's, and the pull is the only place that distinction can be enforced.
     *
     * @return list<string>
     */
    public function readable(): array
    {
        return array_values(array_diff($this->writable, $this->redacted));
    }
}
