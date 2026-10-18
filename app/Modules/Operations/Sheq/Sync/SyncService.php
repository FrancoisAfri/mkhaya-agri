<?php

namespace App\Modules\Operations\Sheq\Sync;

use App\Modules\Operations\Sheq\Security\SpecialInformation;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * The server half of offline sync (§6.1).
 *
 * `push()` applies a device's operation log; `pull()` answers with a delta since the client's
 * cursor. Both are built around one commitment from the risk table: a field worker's data is never
 * silently lost. Concretely that means:
 *
 * - Every operation is recorded before it is applied, keyed by the client's UUIDv7, so a retry
 *   after a dropped connection replays the stored result instead of doubling the work (SHEQ-OFF-2).
 * - A merge that cannot keep a client value queues a conflict holding both sides (SHEQ-OFF-5).
 * - A rejected state transition returns the record *and the client's field data*, with an
 *   explanation, rather than discarding the push (SHEQ-OFF-6).
 * - An operation that throws is recorded as REJECTED with the reason, never lost.
 *
 * Each operation commits in its own transaction. One bad operation in a queue of forty must not
 * roll back the thirty-nine that were fine — a device that has been offline for a week would then
 * never drain.
 */
class SyncService
{
    public function __construct(
        private readonly SyncRegistry $registry,
        private readonly SyncMerger $merger,
        private readonly SpecialInformation $special,
    ) {}

    /**
     * Apply a device's queued operations, in the order it recorded them.
     *
     * @param  list<array<string,mixed>>  $operations
     * @return array{results:list<array<string,mixed>>, applied:int, conflicts:int, rejected:int, cursor:int}
     */
    public function push(int $companyId, array $operations, ?int $userId = null, ?string $deviceId = null): array
    {
        $results = [];
        $applied = 0;
        $conflicts = 0;
        $rejected = 0;

        // UUIDv7 sorts by creation time, so this replays the user's actual sequence of work rather
        // than whatever order the payload happened to arrive in.
        usort($operations, fn ($a, $b) => strcmp((string) ($a['client_id'] ?? ''), (string) ($b['client_id'] ?? '')));

        foreach ($operations as $operation) {
            $result = $this->applyOne($companyId, $operation, $userId, $deviceId);
            $results[] = $result;

            // A conflict still counts as applied: the non-conflicting fields were written, and
            // only the contested ones went to the queue.
            if ($result['status'] === 'REJECTED') {
                $rejected++;
            } else {
                $applied++;

                if ($result['status'] === 'CONFLICT') {
                    $conflicts++;
                }
            }
        }

        return [
            'results' => $results,
            'applied' => $applied,
            'conflicts' => $conflicts,
            'rejected' => $rejected,
            'cursor' => $this->cursor($companyId),
        ];
    }

    /**
     * @param  array<string,mixed>  $operation
     * @return array<string,mixed>
     */
    private function applyOne(int $companyId, array $operation, ?int $userId, ?string $deviceId): array
    {
        $clientId = (string) ($operation['client_id'] ?? '');

        if ($clientId === '') {
            return [
                'client_id' => null, 'status' => 'REJECTED',
                'reason' => 'Every operation needs a client_id; it is the idempotency key.',
            ];
        }

        // SHEQ-OFF-2: replaying an operation is a no-op returning the original result. Checked
        // before any work, and again below via the unique index in case two retries race.
        $existing = DB::table('sheq_sync_operations')
            ->where('company_id', $companyId)->where('client_id', $clientId)
            ->first(['status', 'result', 'rejection_reason']);

        if ($existing !== null) {
            return [
                'client_id' => $clientId,
                'status' => $existing->status,
                'replayed' => true,
                'result' => json_decode((string) $existing->result, true),
                'reason' => $existing->rejection_reason,
            ];
        }

        try {
            return DB::transaction(fn () => $this->run($companyId, $clientId, $operation, $userId, $deviceId));
        } catch (Throwable $e) {
            /**
             * Recorded rather than swallowed. An operation that blew up is still a thing the user
             * did, and it has to be visible — both so the device stops retrying it forever and so
             * somebody can see what happened to it.
             */
            $this->record($companyId, $clientId, $operation, $userId, $deviceId,
                'REJECTED', null, $e->getMessage());

            return [
                'client_id' => $clientId, 'status' => 'REJECTED',
                'reason' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function run(int $companyId, string $clientId, array $operation, ?int $userId, ?string $deviceId): array
    {
        $spec = $this->registry->get((string) ($operation['entity_type'] ?? ''));
        $type = strtoupper((string) $operation['operation'] ?? 'UPDATE');
        $uuid = $operation['entity_uuid'] ?? null;

        // Decoded on all three sides before anything is compared. A jsonb column arrives from the
        // driver as a string, and comparing a string to a decoded array makes one side look empty.
        $payload = $spec->decode($spec->only((array) ($operation['payload'] ?? [])));
        $base = $spec->decode($spec->only((array) ($operation['base'] ?? [])));

        if ($type === 'CREATE') {
            return $this->create($companyId, $clientId, $spec, $operation, $payload, $userId, $deviceId);
        }

        $row = DB::table($spec->table)
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if ($row === null) {
            return $this->handleMissing($companyId, $clientId, $spec, $operation, $base, $payload, $userId, $deviceId);
        }

        $current = $spec->decode((array) $row);
        $withStatus = $spec->at((string) ($current[$spec->statusField] ?? 'DRAFT'));

        if ($type === 'TRANSITION') {
            return $this->transition($companyId, $clientId, $withStatus, $operation, $row, $userId, $deviceId);
        }

        $merge = $this->merger->merge($base, $current, $payload, $withStatus);

        /**
         * The guarantee, asserted rather than assumed. If a field the client changed is neither
         * written nor queued as a conflict, their data has gone — the P0 defect in §12. Failing
         * loudly here beats discovering it from a worker who stopped using the app.
         */
        $lost = $merge->unaccountedFor($payload, $base, $spec->statusField);

        if ($lost !== []) {
            throw new \RuntimeException(
                'Merge would have dropped client data for: '.implode(', ', $lost)
            );
        }

        if ($merge->values !== []) {
            DB::table($spec->table)->where('id', $row->id)->update(
                $this->encodeForStorage($merge->values) + ['updated_at' => now()]
            );

            $this->recordChange($companyId, $spec, (int) $row->id, (string) $row->uuid,
                $current[$spec->siteField] ?? null, 'UPDATED');
        }

        $conflictIds = $this->queueConflicts($companyId, $spec, (string) $row->uuid, $merge, $userId);

        /*
         * The child collections (REG-FIX-8), after the record-level merge and regardless of
         * whether it conflicted.
         *
         * Deliberately not gated on the merge outcome. A witness statement is not in conflict
         * with anything — it is a thing that happened — so withholding it because two devices
         * disagreed about the incident's description would lose evidence over an editorial
         * dispute.
         */
        $children = $this->appendChildren($companyId, $spec, (int) $row->id,
            (array) ($operation['children'] ?? []), $userId);

        $result = [
            'client_id' => $clientId,
            'entity_uuid' => (string) $row->uuid,
            'status' => $merge->hasConflicts() ? 'CONFLICT' : 'APPLIED',
            'applied_fields' => array_keys($merge->values),
            'rules' => $merge->rules,
            'conflicts' => $merge->conflicts,
            'conflict_ids' => $conflictIds,
        ];

        if ($children !== []) {
            $result['children'] = $children;
        }

        if ($merge->rejectedStatus !== null) {
            $result['status_rejected'] = [
                'value' => $merge->rejectedStatus,
                'reason' => 'Status is set by the server; push a TRANSITION operation instead.',
            ];
        }

        $operationId = $this->record($companyId, $clientId, $operation, $userId, $deviceId,
            $result['status'], $result);

        if ($conflictIds !== []) {
            DB::table('sheq_sync_conflicts')->whereIn('id', $conflictIds)
                ->update(['operation_id' => $operationId, 'updated_at' => now()]);
        }

        return $result;
    }

    /**
     * A create from the field.
     *
     * Delegated to the entity's own domain service, never inserted directly. That service is where
     * the reference is generated, the regulatory pack classifies the record, statutory obligations
     * start their clocks and the audit entry is written. A raw insert would produce an incident with
     * no statutory category and no notification deadline — precisely what this module exists to
     * prevent, arriving through the sync endpoint.
     *
     * The client's UUID is passed through as `client_id`, which those services already treat as an
     * offline-replay key, so a device that retries a create gets the original record back.
     */
    private function create(int $companyId, string $clientId, EntitySpec $spec, array $operation,
        array $payload, ?int $userId, ?string $deviceId): array
    {
        $uuid = (string) ($operation['entity_uuid'] ?? Str::uuid());

        $existing = DB::table($spec->table)
            ->where('company_id', $companyId)->where('uuid', $uuid)->first(['id', 'uuid']);

        if ($existing !== null) {
            // The record landed on a previous attempt whose response the device never received.
            $result = ['client_id' => $clientId, 'entity_uuid' => $uuid, 'status' => 'APPLIED',
                'created' => false, 'note' => 'Already present; treated as a replay.'];
            $this->record($companyId, $clientId, $operation, $userId, $deviceId, 'APPLIED', $result);

            return $result;
        }

        if ($spec->createVia === null) {
            throw new \RuntimeException(
                "A {$spec->type} cannot be created from a device; it is scheduled in the office."
            );
        }

        [$class, $method] = $spec->createVia;

        $created = app($class)->{$method}($companyId, $payload + [
            'uuid' => $uuid,
            'client_id' => $uuid,
        ], $userId);

        $entityId = (int) ($created['id'] ?? $created['incident_id'] ?? 0);
        $entityUuid = (string) ($created['uuid'] ?? $uuid);

        $this->recordChange($companyId, $spec, $entityId, $entityUuid,
            $payload[$spec->siteField] ?? null, 'CREATED');

        $result = ['client_id' => $clientId, 'entity_uuid' => $entityUuid, 'entity_id' => $entityId,
            'status' => 'APPLIED', 'created' => true,
            // Handed straight back: the device shows the worker the reference the office will use.
            'reference' => $created['reference'] ?? null];

        $this->record($companyId, $clientId, $operation, $userId, $deviceId, 'APPLIED', $result);

        return $result;
    }

    /**
     * SHEQ-OFF-6: a transition is re-validated against *current* server state.
     *
     * A device that was offline may have walked a record through steps that were legal when it
     * started and are not now. Rejection returns the record with an explanation and keeps the
     * client's data — the operation is stored, so nothing the user typed is thrown away.
     */
    private function transition(int $companyId, string $clientId, EntitySpec $spec, array $operation,
        object $row, ?int $userId, ?string $deviceId): array
    {
        $from = (string) ($row->{$spec->statusField} ?? '');
        $to = (string) (($operation['payload']['to_status'] ?? $operation['to_status']) ?? '');

        if ($from === $to) {
            $result = ['client_id' => $clientId, 'entity_uuid' => $row->uuid, 'status' => 'APPLIED',
                'note' => 'Already in that state.'];
            $this->record($companyId, $clientId, $operation, $userId, $deviceId, 'APPLIED', $result);

            return $result;
        }

        if (! $spec->allowsTransition($from, $to)) {
            $reason = sprintf(
                '%s cannot move from %s to %s. The record moved while the device was offline; '
                .'your entry has been kept.',
                ucfirst(strtolower($spec->type)), $from ?: 'an unknown state', $to ?: 'an unknown state'
            );

            $result = [
                'client_id' => $clientId,
                'entity_uuid' => $row->uuid,
                'status' => 'REJECTED',
                'reason' => $reason,
                // Returned so the client can show the user where the record actually is now.
                'server_status' => $from,
                'retained_payload' => $operation['payload'] ?? [],
            ];

            $this->record($companyId, $clientId, $operation, $userId, $deviceId,
                'REJECTED', $result, $reason);

            return $result;
        }

        DB::table($spec->table)->where('id', $row->id)->update([
            $spec->statusField => $to,
            'updated_at' => now(),
        ]);

        $this->recordChange($companyId, $spec, (int) $row->id, (string) $row->uuid,
            $row->{$spec->siteField} ?? null, 'UPDATED');

        $result = ['client_id' => $clientId, 'entity_uuid' => $row->uuid, 'status' => 'APPLIED',
            'from' => $from, 'to' => $to];

        $this->record($companyId, $clientId, $operation, $userId, $deviceId, 'APPLIED', $result);

        return $result;
    }

    /** The record is gone from the server but the client edited it (SHEQ-OFF-4, last row). */
    private function handleMissing(int $companyId, string $clientId, EntitySpec $spec, array $operation,
        array $base, array $payload, ?int $userId, ?string $deviceId): array
    {
        $merge = $this->merger->resurrect($base, $payload, $spec);
        $uuid = (string) ($operation['entity_uuid'] ?? '');

        $conflictIds = $this->queueConflicts($companyId, $spec, $uuid, $merge, $userId);

        $result = [
            'client_id' => $clientId,
            'entity_uuid' => $uuid,
            'status' => 'CONFLICT',
            'resurrected' => true,
            'reason' => 'This record no longer exists on the server. Your edits are held for review.',
            'conflicts' => $merge->conflicts,
            'conflict_ids' => $conflictIds,
        ];

        $operationId = $this->record($companyId, $clientId, $operation, $userId, $deviceId,
            'CONFLICT', $result);

        if ($conflictIds !== []) {
            DB::table('sheq_sync_conflicts')->whereIn('id', $conflictIds)
                ->update(['operation_id' => $operationId, 'updated_at' => now()]);
        }

        return $result;
    }

    /** @return list<int> */
    private function queueConflicts(int $companyId, EntitySpec $spec, string $uuid,
        MergeResult $merge, ?int $userId): array
    {
        $ids = [];

        foreach ($merge->conflicts as $conflict) {
            $ids[] = DB::table('sheq_sync_conflicts')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'entity_type' => $spec->type,
                'entity_uuid' => $uuid,
                'field' => $conflict['field'],
                'base_value' => json_encode($conflict['base']),
                'server_value' => json_encode($conflict['server']),
                'client_value' => json_encode($conflict['client']),
                'rule' => $conflict['rule'],
                'reported_by' => $userId,
                'detected_at' => Timestamps::utc(Carbon::now()),
                'status' => 'OPEN',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * Delta pull (SHEQ-OFF-3).
     *
     * The client sends the highest sequence it holds and receives everything after it. Full-table
     * pulls are what make a sync burn a day's data allowance on a site with one bar of signal.
     *
     * @param  array<string,int>  $cursors  entity type => last sequence seen
     * @param  list<int>  $siteIds  the user's assigned sites (SHEQ-OFF-1)
     * @return array<string,mixed>
     */
    public function pull(int $companyId, array $cursors = [], array $siteIds = [], int $limit = 500): array
    {
        $entities = [];
        $highest = 0;

        foreach ($this->registry->all() as $type => $spec) {
            $since = (int) ($cursors[$type] ?? 0);

            $changes = DB::table('sheq_sync_changes')
                ->where('company_id', $companyId)
                ->where('entity_type', $type)
                ->where('sequence', '>', $since)
                // A change with no site is company-wide and belongs to everyone; scoping it out
                // would hide records a user is entitled to see.
                ->when($siteIds !== [], fn ($q) => $q->where(
                    fn ($w) => $w->whereIn('site_id', $siteIds)->orWhereNull('site_id')
                ))
                ->orderBy('sequence')->limit($limit)
                ->get();

            if ($changes->isEmpty()) {
                $entities[$type] = ['cursor' => $since, 'changes' => [], 'has_more' => false];

                continue;
            }

            $lastSequence = (int) $changes->last()->sequence;
            $highest = max($highest, $lastSequence);

            $ids = $changes->where('change_type', '!=', 'DELETED')->pluck('entity_id')->all();
            $records = $ids === [] ? collect() : DB::table($spec->table)
                ->where('company_id', $companyId)->whereIn('id', $ids)
                ->get(array_merge(['id', 'uuid', $spec->statusField], $spec->syncable))
                ->keyBy('id');

            $children = $this->pullChildren($companyId, $spec, $ids);

            $entities[$type] = [
                'cursor' => $lastSequence,
                'changes' => $changes->map(fn ($change) => [
                    'sequence' => (int) $change->sequence,
                    'entity_uuid' => $change->entity_uuid,
                    'change_type' => $change->change_type,
                    'occurred_at' => $change->occurred_at,
                    'record' => $records->get($change->entity_id),
                    // REG-FIX-8: what the other devices have added, redacted.
                    'children' => $children[$change->entity_id] ?? null,
                ])->values()->all(),
                // Told plainly, so a client knows to pull again rather than assuming it is current.
                'has_more' => $changes->count() >= $limit,
            ];
        }

        return [
            'entities' => $entities,
            'cursor' => max($highest, $this->cursor($companyId)),
            'server_time' => Timestamps::utc(Carbon::now())->toIso8601String(),
        ];
    }

    /**
     * The child-collection rows for records being pulled, with the sensitive columns withheld
     * (REG-FIX-8).
     *
     * A second device needs to know a witness statement EXISTS — so it does not take the same
     * statement again, and so its own copy of the incident is not misleadingly short. It does not
     * need the text. Witness statements and injury detail are POPIA s26 special personal
     * information encrypted at column level, and a phone that uploaded one has no business
     * downloading everybody else's.
     *
     * So this returns the readable columns and a flag saying whether the withheld content is
     * present on the server. That is enough to render "3 statements taken, 1 signed" without a
     * single word of anybody's medical history leaving the building.
     *
     * @param  list<int>  $entityIds
     * @return array<int, array<string, list<array<string,mixed>>>>
     */
    private function pullChildren(int $companyId, EntitySpec $spec, array $entityIds): array
    {
        if ($spec->childCollections === [] || $entityIds === []) {
            return [];
        }

        $out = [];

        foreach ($spec->childCollections as $child) {
            /*
             * A collection reached through another table is skipped here. Resolving it would mean
             * a join per record, and a device cannot add to it anyway until the parent exists —
             * so the information would be read-only detail bought at the cost of a query the
             * sync endpoint runs on every pull.
             */
            if ($child->viaParent !== null) {
                continue;
            }

            $columns = array_merge(['uuid', $child->parentKey], $child->readable());

            if (Schema::hasColumn($child->table, 'statement_signed_at')) {
                $columns[] = 'statement_signed_at';
            }

            $rows = DB::table($child->table)
                ->where('company_id', $companyId)
                ->whereIn($child->parentKey, $entityIds)
                ->when(
                    $child->softDeletes && Schema::hasColumn($child->table, 'deleted_at'),
                    fn ($q) => $q->whereNull('deleted_at'),
                )
                ->get($columns);

            foreach ($rows as $row) {
                $values = (array) $row;
                $parent = (int) $values[$child->parentKey];
                unset($values[$child->parentKey]);

                // Said rather than silently absent, so a device can show that a statement was
                // taken without being able to read it — and so an empty field does not look like
                // a statement nobody wrote down.
                foreach ($child->redacted as $withheld) {
                    $values[$withheld.'_present'] = DB::table($child->table)
                        ->where('uuid', $row->uuid)->whereNotNull($withheld)->exists();
                }

                $values['redacted'] = $child->redacted;

                $out[$parent][$child->name][] = $values;
            }
        }

        return $out;
    }

    /** The tenant's current high-water mark. */
    public function cursor(int $companyId): int
    {
        return (int) DB::table('sheq_sync_changes')
            ->where('company_id', $companyId)->max('sequence');
    }

    /**
     * The conflict queue behind SHEQ-OFF-5, oldest first — a conflict that has sat for a week is
     * the one most likely to have been forgotten.
     *
     * @return list<array<string,mixed>>
     */
    public function conflicts(int $companyId, bool $openOnly = true): array
    {
        return DB::table('sheq_sync_conflicts')
            ->where('company_id', $companyId)
            ->when($openOnly, fn ($q) => $q->where('status', 'OPEN'))
            ->orderBy('detected_at')
            ->get()->map(fn ($row) => [
                'uuid' => $row->uuid,
                'entity_type' => $row->entity_type,
                'entity_uuid' => $row->entity_uuid,
                'field' => $row->field,
                'base_value' => json_decode((string) $row->base_value, true),
                'server_value' => json_decode((string) $row->server_value, true),
                'client_value' => json_decode((string) $row->client_value, true),
                'rule' => $row->rule,
                'detected_at' => $row->detected_at,
                'status' => $row->status,
            ])->all();
    }

    /**
     * Resolve one conflict the way a human decided (SHEQ-OFF-5).
     *
     * Taking the client value writes it through; taking the server value changes nothing but still
     * closes the item. Either way the row keeps both values forever — the record of what was
     * discarded is the point.
     */
    public function resolveConflict(int $companyId, string $conflictUuid, string $resolution,
        ?int $userId = null, ?string $notes = null): array
    {
        return DB::transaction(function () use ($companyId, $conflictUuid, $resolution, $userId, $notes) {
            $conflict = DB::table('sheq_sync_conflicts')
                ->where('company_id', $companyId)->where('uuid', $conflictUuid)->first();

            if ($conflict === null) {
                throw new \RuntimeException('Conflict not found.');
            }

            if ($conflict->status === 'RESOLVED') {
                throw new \RuntimeException('That conflict has already been resolved.');
            }

            $resolution = strtoupper($resolution);

            if (! in_array($resolution, ['TOOK_SERVER', 'TOOK_CLIENT'], true)) {
                throw new \RuntimeException("Unknown resolution '{$resolution}'.");
            }

            if ($resolution === 'TOOK_CLIENT') {
                $spec = $this->registry->get($conflict->entity_type);
                $value = json_decode((string) $conflict->client_value, true);

                $row = DB::table($spec->table)
                    ->where('company_id', $companyId)->where('uuid', $conflict->entity_uuid)
                    ->first(['id', 'uuid', $spec->siteField]);

                if ($row !== null) {
                    DB::table($spec->table)->where('id', $row->id)->update([
                        $conflict->field => is_array($value) ? json_encode($value) : $value,
                        'updated_at' => now(),
                    ]);

                    $this->recordChange($companyId, $spec, (int) $row->id, (string) $row->uuid,
                        $row->{$spec->siteField} ?? null, 'UPDATED');
                }
            }

            DB::table('sheq_sync_conflicts')->where('id', $conflict->id)->update([
                'status' => 'RESOLVED',
                'resolution' => $resolution,
                'resolved_by' => $userId,
                'resolved_at' => Timestamps::utc(Carbon::now()),
                'resolution_notes' => $notes,
                'updated_at' => now(),
            ]);

            return ['uuid' => $conflictUuid, 'status' => 'RESOLVED', 'resolution' => $resolution];
        });
    }

    /** Append to the change feed. Every mutation that a device could care about passes through. */
    /**
     * Add the rows a device collected offline to this entity's child collections (REG-FIX-8).
     *
     * Append-only and keyed on the client's uuid, which gives the three properties that matter:
     *
     * **A row the server already has is left alone.** A retried push does not duplicate a witness
     * statement, and a statement somebody has since signed server-side is not overwritten by the
     * unsigned copy still on the phone.
     *
     * **A row the server does not have is inserted.** The half of the statements taken on the
     * other device arrives, and neither device loses anything.
     *
     * **A row the CLIENT does not have is never deleted.** This is the one that would be
     * catastrophic and the easiest to get wrong, because "sync the collection" reads naturally as
     * "make the server match the client" — which would delete the other device's statements the
     * moment the first one pushed, and the deletion would look like a successful sync.
     *
     * Nothing here changes an existing row. Amending a witness statement is something a person
     * does with their name against it, not something a phone does while reconnecting.
     *
     * @param  array<string, list<array<string,mixed>>>  $children  collection name => rows
     * @return array<string, array<string,mixed>>
     */
    private function appendChildren(int $companyId, EntitySpec $spec, int $entityId,
        array $children, ?int $userId): array
    {
        $out = [];

        foreach ($children as $name => $rows) {
            $child = $spec->childCollection((string) $name);

            if ($child === null) {
                /*
                 * Named rather than ignored. A client pushing a collection this version does not
                 * support is a version mismatch, and silence would let a device believe for
                 * months that its statements were arriving.
                 */
                $out[$name] = ['status' => 'UNSUPPORTED', 'inserted' => 0, 'skipped' => 0,
                    'rejected' => count((array) $rows),
                    'reason' => "'{$name}' is not a syncable collection on {$spec->type}."];

                continue;
            }

            $parentId = $this->childParentId($companyId, $child, $entityId);

            if ($parentId === null) {
                $out[$name] = ['status' => 'NO_PARENT', 'inserted' => 0, 'skipped' => 0,
                    'rejected' => count((array) $rows),
                    'reason' => sprintf('There is no open %s for this record yet, so these rows '
                        .'have nowhere to attach. They are not lost — the device should keep them '
                        .'and retry.', str_replace(['sheq_', '_'], ['', ' '], $child->table))];

                continue;
            }

            $out[$name] = $this->appendOne($companyId, $child, $parentId, (array) $rows, $userId);
        }

        return $out;
    }

    /**
     * The id the child row attaches to.
     *
     * Usually the entity itself. Investigation causes hang off `sheq_investigations`, so the
     * parent is resolved through it — and where no investigation is open the rows are refused with
     * a reason rather than dropped, because a root cause recorded before anybody investigated is
     * not a finding and silently discarding it is the data loss this requirement is about.
     */
    private function childParentId(int $companyId, ChildCollectionSpec $child, int $entityId): ?int
    {
        if ($child->viaParent === null) {
            return $entityId;
        }

        $id = DB::table($child->viaParent['table'])
            ->where('company_id', $companyId)
            ->where($child->viaParent['foreign'], $entityId)
            ->when(
                Schema::hasColumn($child->viaParent['table'], 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at'),
            )
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function appendOne(int $companyId, ChildCollectionSpec $child, int $parentId,
        array $rows, ?int $userId): array
    {
        $inserted = 0;
        $skipped = 0;
        $rejected = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $uuid = $row['uuid'] ?? null;

            if (! is_string($uuid) || ! Str::isUuid($uuid)) {
                /*
                 * Without a client uuid there is no way to tell a retry from a new statement, so
                 * the choice would be between duplicating evidence and discarding it. Refused
                 * with a reason so the client can fix its payload.
                 */
                $rejected[] = ['uuid' => $uuid, 'reason' => 'A client-generated uuid is required: '
                    .'without one a retry cannot be told apart from a new row.'];

                continue;
            }

            $values = $child->only($row) + $child->defaults;
            $missing = $child->missing($values);

            if ($missing !== []) {
                $rejected[] = ['uuid' => $uuid,
                    'reason' => 'Missing: '.implode(', ', $missing)];

                continue;
            }

            // "At least one of" — a witness with neither a name nor a person record is a blank
            // line in the statement list of a serious incident.
            if ($child->table === 'sheq_incident_people'
                && empty($values['hr_person_id']) && empty($values['contractor_id'])
                && trim((string) ($values['external_name'] ?? '')) === '') {
                $rejected[] = ['uuid' => $uuid,
                    'reason' => 'A person needs a name or a personnel record.'];

                continue;
            }

            $exists = DB::table($child->table)
                ->where('company_id', $companyId)->where('uuid', $uuid)->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $values['uuid'] = $uuid;
            $values['company_id'] = $companyId;
            $values[$child->parentKey] = $parentId;
            $values['created_at'] = now();
            $values['updated_at'] = now();

            /*
             * Encrypted on the way in, through the same helper the online path uses. Witness
             * statements and injury detail are POPIA s26 special personal information, and a row
             * arriving through the sync endpoint must not be the one copy stored in clear.
             */
            DB::table($child->table)->insert(
                $child->redacted === [] ? $values : $this->special->protectRow($values)
            );

            $inserted++;
        }

        return [
            'status' => $rejected === [] ? 'APPLIED' : 'PARTIAL',
            'inserted' => $inserted,
            // Named `skipped`, not `duplicate`: a device retrying is normal behaviour on a bad
            // connection, not an error to report back as one.
            'skipped' => $skipped,
            'rejected' => count($rejected),
            'rejections' => $rejected,
        ];
    }

    private function recordChange(int $companyId, EntitySpec $spec, int $entityId, string $uuid,
        mixed $siteId, string $changeType): void
    {
        DB::table('sheq_sync_changes')->insert([
            'company_id' => $companyId,
            'entity_type' => $spec->type,
            'entity_id' => $entityId,
            'entity_uuid' => $uuid,
            'site_id' => $siteId ?: null,
            'change_type' => $changeType,
            'occurred_at' => Timestamps::utc(Carbon::now()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function record(int $companyId, string $clientId, array $operation, ?int $userId,
        ?string $deviceId, string $status, ?array $result, ?string $reason = null): int
    {
        return DB::table('sheq_sync_operations')->insertGetId([
            'company_id' => $companyId,
            'client_id' => $clientId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'entity_type' => strtoupper((string) ($operation['entity_type'] ?? 'UNKNOWN')),
            'entity_uuid' => $operation['entity_uuid'] ?? null,
            'operation' => strtoupper((string) ($operation['operation'] ?? 'UPDATE')),
            'base_version' => $operation['base_version'] ?? null,
            'base' => json_encode($operation['base'] ?? null),
            'payload' => json_encode($operation['payload'] ?? []),
            'status' => $status,
            'result' => json_encode($result),
            'rejection_reason' => $reason,
            'received_at' => Timestamps::utc(Carbon::now()),
            'applied_at' => $status === 'REJECTED' ? null : Timestamps::utc(Carbon::now()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Arrays go to the database as JSON; scalars go as they are. Append-only collections are
     * merged as PHP lists and have to land back in the jsonb column they came from.
     */
    private function encodeForStorage(array $values): array
    {
        foreach ($values as $field => $value) {
            if (is_array($value)) {
                $values[$field] = json_encode($value);
            }
        }

        return $values;
    }
}
