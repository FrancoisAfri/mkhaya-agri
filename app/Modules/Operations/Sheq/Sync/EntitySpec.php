<?php

namespace App\Modules\Operations\Sheq\Sync;

/**
 * What the merger needs to know about one syncable entity (SHEQ-OFF-4).
 *
 * Held as data rather than as `if ($entityType === 'INCIDENT')` branches inside the merger, because
 * the merge rules are identical for every entity and only the vocabulary differs. Adding a syncable
 * entity should be a registry entry, not a new code path through conflict resolution.
 */
class EntitySpec
{
    /**
     * @param  string  $table  where the entity lives
     * @param  list<string>  $syncable  fields a client may push; anything else is ignored outright
     * @param  list<string>  $appendOnly  collections that union instead of conflicting
     * @param  list<string>  $draftStates  statuses where the author's intent still wins
     * @param  array<string,list<string>>  $transitions  status => statuses reachable from it
     */
    public function __construct(
        public readonly string $type,
        public readonly string $table,
        public readonly array $syncable,
        public readonly array $appendOnly = [],
        public readonly array $draftStates = ['DRAFT'],
        public readonly string $statusField = 'status',
        public readonly ?string $collectionKey = 'id',
        public readonly array $transitions = [],
        public readonly ?string $siteField = 'site_id',
        /**
         * The domain service that creates this entity, as [class, method] taking
         * (companyId, input, userId).
         *
         * A sync create delegates here rather than inserting directly, because the domain path is
         * where the reference is generated, the pack classifies the record, statutory obligations
         * are scheduled and the audit entry is written. An incident inserted straight into the
         * table would exist with no statutory category and no notification deadline — the exact
         * failure the module was built to prevent, arriving through the back door.
         *
         * @var array{0:class-string,1:string}|null
         */
        public readonly ?array $createVia = null,
        /**
         * Columns stored as jsonb. They come back from the driver as strings, and merging a string
         * against a decoded array silently treats one side as empty — which cost the server's half
         * of an inspection's answers before this existed.
         *
         * @var list<string>
         */
        public readonly array $jsonFields = [],
        /**
         * Append-only child collections a device may add rows to (REG-FIX-8).
         *
         * Distinct from `appendOnly`, which unions a jsonb column on this row. These are separate
         * tables, and they are the collections two people genuinely fill in at the same incident
         * at the same time — witness statements and investigation causes. Record-level merge
         * cannot carry them, because the normal case at a serious incident is two devices each
         * holding half of the statements.
         *
         * @var list<ChildCollectionSpec>
         */
        public readonly array $childCollections = [],
        public string $currentStatus = 'DRAFT',
    ) {}

    /** The child collection a client named, if this entity has it. */
    public function childCollection(string $name): ?ChildCollectionSpec
    {
        foreach ($this->childCollections as $child) {
            if ($child->name === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Decode this entity's JSON columns wherever they arrive as strings.
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public function decode(array $values): array
    {
        foreach ($this->jsonFields as $field) {
            if (isset($values[$field]) && is_string($values[$field])) {
                $decoded = json_decode($values[$field], true);
                $values[$field] = is_array($decoded) ? $decoded : $values[$field];
            }
        }

        return $values;
    }

    /** A copy positioned at the record's actual status, so draft rules apply to the right record. */
    public function at(string $status): self
    {
        $clone = clone $this;
        $clone->currentStatus = $status;

        return $clone;
    }

    /** Strip anything the client is not permitted to push (SHEQ-OFF-6 is not the only gate). */
    public function only(array $values): array
    {
        return array_intersect_key($values, array_flip($this->syncable));
    }

    public function allowsTransition(string $from, string $to): bool
    {
        // An entity with no declared state machine cannot be transitioned by a client at all.
        if ($this->transitions === []) {
            return false;
        }

        return in_array($to, $this->transitions[$from] ?? [], true);
    }
}
