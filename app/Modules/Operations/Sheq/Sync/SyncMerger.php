<?php

namespace App\Modules\Operations\Sheq\Sync;

/**
 * Field-level merge (SHEQ-OFF-4).
 *
 * The rule this class exists to enforce is the one in the risk table: **a field worker's data is
 * never silently discarded**. Where the client loses, a conflict is recorded with both values so a
 * person can decide; where the field is a collection, both sides are kept.
 *
 * Record-level merge — the usual "last write wins on the whole row" — is what makes offline apps
 * hated. Two people editing different fields of the same incident is not a conflict, and treating
 * it as one throws away work that never actually collided.
 *
 * The class is deliberately pure: no database, no clock, no container. Every row of the SHEQ-OFF-4
 * table is a unit test, and the chaos suite runs thousands of generated scenarios through it
 * precisely because it can be called in a loop without touching anything.
 */
class SyncMerger
{
    /** @see MergeResult::$conflicts — the rule names the UI shows when explaining a conflict. */
    public const RULE_SERVER_ONLY = 'SERVER_ONLY';

    public const RULE_CLIENT_ONLY = 'CLIENT_ONLY';

    public const RULE_UNION = 'APPEND_ONLY_UNION';

    public const RULE_DRAFT_AUTHOR_INTENT = 'DRAFT_AUTHOR_INTENT';

    public const RULE_SERVER_WINS_CONFLICT = 'SERVER_WINS_CONFLICT';

    public const RULE_STATUS_SERVER_WINS = 'STATUS_SERVER_WINS';

    public const RULE_RESURRECT = 'DELETED_ON_SERVER';

    /**
     * Merge one client operation against current server state.
     *
     * @param  array<string,mixed>  $base  field values the client last saw
     * @param  array<string,mixed>  $server  current server values
     * @param  array<string,mixed>  $client  values the client is pushing
     */
    public function merge(array $base, array $server, array $client, EntitySpec $spec): MergeResult
    {
        $result = new MergeResult;

        foreach ($client as $field => $clientValue) {
            $baseValue = $base[$field] ?? null;
            $serverValue = $server[$field] ?? null;

            /**
             * Status is never taken from the client, even when the server has not moved.
             *
             * A client that was offline may have walked the record through transitions that are no
             * longer legal against current server state, so status only ever changes through the
             * re-validated transition path (SHEQ-OFF-6). Accepting it here would be the hole that
             * makes that validation pointless.
             */
            if ($field === $spec->statusField) {
                if (! $this->same($clientValue, $serverValue)) {
                    $result->rejectedStatus = $clientValue;
                    $result->note($field, self::RULE_STATUS_SERVER_WINS);
                }

                continue;
            }

            $clientChanged = ! $this->same($clientValue, $baseValue);

            // The client is echoing back what it was given. Nothing to merge, and treating it as a
            // change would manufacture conflicts out of an unmodified round trip.
            if (! $clientChanged) {
                continue;
            }

            $serverChanged = array_key_exists($field, $server)
                && ! $this->same($serverValue, $baseValue);

            /**
             * Collections merge before anything else, whoever changed.
             *
             * Taking the client's collection wholesale — even when the server did not move — drops
             * every entry the device never held. A phone that synced half a checklist and answered
             * one more question would erase the other half. The union is the only safe read of
             * "the client changed this collection".
             */
            if (in_array($field, $spec->appendOnly, true)) {
                // A keyed collection (inspection responses: question => answer) merges one level
                // deeper, so answering different questions on two devices keeps both answers and
                // only a genuinely contested question becomes a conflict.
                if ($this->isKeyed($clientValue) || $this->isKeyed($serverValue)) {
                    $this->mergeKeyed($result, $field, $baseValue, $serverValue, $clientValue);

                    continue;
                }

                $result->apply($field, $this->union($serverValue, $clientValue, $spec->collectionKey),
                    self::RULE_UNION);

                continue;
            }

            // ── Scalars. Changed on client only — take client.
            if (! $serverChanged) {
                $result->apply($field, $clientValue, self::RULE_CLIENT_ONLY);

                continue;
            }

            // A draft belongs to its author: their newer text supersedes whatever else touched it.
            if (in_array($spec->currentStatus, $spec->draftStates, true)) {
                $result->apply($field, $clientValue, self::RULE_DRAFT_AUTHOR_INTENT);

                continue;
            }

            /**
             * Beyond draft the server holds, because the record is now something other people have
             * acted on. The client's value is not discarded — it goes to the conflict queue with
             * both sides, which is the entire difference between this and losing their work.
             */
            $result->conflict($field, $baseValue, $serverValue, $clientValue,
                self::RULE_SERVER_WINS_CONFLICT);
        }

        return $result;
    }

    /**
     * The client edited a record the server has deleted (SHEQ-OFF-4, last row).
     *
     * Resurrected as a conflict rather than dropped. Somebody stood in front of a hazard and typed
     * this; a tombstone on the server is not a reason to throw it away without asking.
     */
    public function resurrect(array $base, array $client, EntitySpec $spec): MergeResult
    {
        $result = new MergeResult;
        $result->resurrected = true;

        foreach ($client as $field => $clientValue) {
            if ($field === $spec->statusField) {
                continue;
            }

            if ($this->same($clientValue, $base[$field] ?? null)) {
                continue;
            }

            $result->conflict($field, $base[$field] ?? null, null, $clientValue, self::RULE_RESURRECT);
        }

        return $result;
    }

    /** A map (question => answer), as opposed to a list of items. */
    private function isKeyed(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * Merge a keyed collection entry by entry — the same rule as a scalar field, one level down.
     *
     * Entries only one side touched are kept. An entry both sides changed keeps the server's and
     * raises a conflict named `field.key`, so the queue can point at the exact question rather than
     * at "the answers".
     */
    private function mergeKeyed(MergeResult $result, string $field, mixed $base, mixed $server, mixed $client): void
    {
        $base = is_array($base) ? $base : [];
        $server = is_array($server) ? $server : [];
        $client = is_array($client) ? $client : [];

        $merged = $server;
        $contested = [];

        foreach ($client as $key => $value) {
            if ($this->same($value, $base[$key] ?? null)) {
                continue;
            }

            $serverChanged = array_key_exists($key, $server)
                && ! $this->same($server[$key], $base[$key] ?? null);

            if (! $serverChanged) {
                $merged[$key] = $value;

                continue;
            }

            $contested[] = $key;
            $result->conflict($field.'.'.$key, $base[$key] ?? null, $server[$key], $value,
                self::RULE_SERVER_WINS_CONFLICT);
        }

        // The field still gets written: every uncontested entry from both sides survives.
        $result->apply($field, $merged, self::RULE_UNION);

        foreach ($contested as $key) {
            $result->note($field.'.'.$key, self::RULE_SERVER_WINS_CONFLICT);
        }
    }

    /**
     * Union of two collections, preserving order: server entries first, then client entries the
     * server does not already have.
     *
     * Identity comes from `collectionKey` when the members are records — two clients adding a cause
     * with the same id are adding the same cause, while two free-text comments that happen to match
     * are still two comments.
     */
    private function union(mixed $server, mixed $client, ?string $key): array
    {
        $server = is_array($server) ? array_values($server) : [];
        $client = is_array($client) ? array_values($client) : [];

        $merged = $server;

        foreach ($client as $item) {
            if (! $this->contains($merged, $item, $key)) {
                $merged[] = $item;
            }
        }

        return $merged;
    }

    private function contains(array $haystack, mixed $needle, ?string $key): bool
    {
        foreach ($haystack as $item) {
            if ($key !== null && is_array($item) && is_array($needle)
                && array_key_exists($key, $item) && array_key_exists($key, $needle)) {
                if ($this->same($item[$key], $needle[$key])) {
                    return true;
                }

                continue;
            }

            if ($this->same($item, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Equality that survives a JSON round trip.
     *
     * A value that left the server as int 3 comes back from a mobile client as "3" or 3.0 depending
     * on the platform's JSON encoder. Treating that as a change would generate a conflict on every
     * sync for every untouched numeric field — the fastest way to make the conflict queue useless.
     */
    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return json_encode($this->normalise($a)) === json_encode($this->normalise($b));
        }

        // One side is a collection and the other is not: different, and never string-cast. A field
        // whose shape changed is a real change, and casting an array to a string to find that out
        // both warns and compares "Array" against the value.
        if (is_array($a) || is_array($b)) {
            return false;
        }

        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return filter_var($a, FILTER_VALIDATE_BOOLEAN) === filter_var($b, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (string) (float) $a === (string) (float) $b;
        }

        return (string) $a === (string) $b;
    }

    private function normalise(array $value): array
    {
        // Key order is an encoding artefact, not a change.
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->normalise($v);
            }
        }

        return $value;
    }
}
