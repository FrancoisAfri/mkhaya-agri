<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Support\ChainVerification;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Support\SoleCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only supported way to write to the Evidence Ledger (PM-ADR-002).
 *
 * Everything that makes the ledger trustworthy lives here: the hash chain, the serialisation that
 * stops two concurrent appends forking it, idempotent replay for offline sync, and correction by
 * supersession. Writing an EvidenceEvent directly bypasses all of it, which is why the model
 * refuses updates and deletes outright.
 *
 * The chain is per (company_id, project_id): each event hashes its own canonical fields plus the
 * previous event's hash for that project. One tenant's activity therefore never interleaves into
 * another's chain, and a single project can be verified without reading the whole table.
 */
class EvidenceLedger
{
    /**
     * Version of the canonical hash input. Bump it when the hashed field set or its serialisation
     * changes; old rows keep their version so a change never retroactively invalidates them.
     */
    public const HASH_VERSION = 'v1';

    /** Fields fed to the hash, in this exact order. The order is part of the contract. */
    private const CANONICAL_FIELDS = [
        'uuid', 'company_id', 'project_id', 'subject_type', 'subject_id', 'subject_uuid',
        'event_type', 'source_module', 'source_ref', 'occurred_at', 'occurred_tz',
        'actor_type', 'actor_id', 'quantity', 'unit', 'amount', 'currency', 'confidence',
        'geo_lat', 'geo_lng', 'payload', 'attachments',
    ];

    /**
     * Decimal columns and their scale. Values are normalised to exactly this many places before
     * hashing *and* before insert, so the hash always describes what the database stores — which is
     * what makes verification possible at all.
     */
    private const DECIMAL_SCALES = [
        'quantity'   => 4,
        'amount'     => 2,
        'confidence' => 2,
        'geo_lat'    => 6,
        'geo_lng'    => 6,
    ];

    public function __construct(private readonly Outbox $outbox)
    {
    }

    /**
     * Append one event and return it.
     *
     * @param  array<string,mixed>  $attributes  May carry a client-generated `uuid` (PM-ADR-004) and
     *                                           an `idempotency_key`; both are honoured verbatim.
     */
    /**
     * Which company this evidence belongs to.
     *
     * ── Why this is not just CurrentCompany ──────────────────────────────────
     *
     * It was, and on a self-hosted installation that resolves to null: there is one customer, so
     * nobody ever "enters a tenant" and an administrator has no `users.company_id` either. The row
     * still landed correctly, because the column defaults to the session variable at the database —
     * but `lastHash()` was then asked for the last hash `where company_id is null`, found nothing,
     * and every event was written as the first link in a chain.
     *
     * So the chain silently never formed. Nothing errored, nothing looked wrong, and
     * `verifyChain()` reported zero events because it queries by the project's real company. For a
     * ledger whose own docblock says these guarantees must never be relaxed — derived progress and
     * cost figures stop being defensible without them — that is the worst shape a bug can take.
     *
     * SoleCompany answers only on a self-hosted edition and only when exactly one company exists;
     * two companies is genuine ambiguity and it returns null rather than guessing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function companyFor(array $attributes): ?int
    {
        return $attributes['company_id']
            ?? app(CurrentCompany::class)->id()
            ?? SoleCompany::id();
    }

    public function append(array $attributes): EvidenceEvent
    {
        $this->validate($attributes);

        return DB::transaction(function () use ($attributes) {
            $companyId = $this->companyFor($attributes);
            $projectId = (int) $attributes['project_id'];

            // Serialise appends to this chain *before* the idempotency check, so two simultaneous
            // retries of the same offline record cannot both find "nothing there yet".
            $this->lockChain($companyId, $projectId);

            if (! empty($attributes['idempotency_key'])) {
                $existing = EvidenceEvent::where('idempotency_key', $attributes['idempotency_key'])->first();
                if ($existing) {
                    return $existing;
                }
            }

            $attributes['company_id'] = $companyId;
            $attributes['uuid'] ??= (string) Str::uuid7();
            $attributes['confidence'] ??= '1.00';
            $attributes['payload'] ??= [];
            $attributes['attachments'] ??= [];

            // Normalise once; insert and hash both use the result, so they cannot disagree.
            $canonical = $this->normalise($attributes);

            $prevHash = $this->lastHash($companyId, $projectId);
            $canonical['prev_hash'] = $prevHash;
            $canonical['hash_version'] = self::HASH_VERSION;
            $canonical['hash'] = $this->hash($canonical, $prevHash);
            $canonical['idempotency_key'] = $attributes['idempotency_key'] ?? null;

            $event = EvidenceEvent::create($canonical);

            // Same transaction as the write: if this rolls back, so does the notification
            // (PM-ADR-008). Derivation consumes it to recompute the subject's metrics.
            $this->outbox->publish('pm.evidence.appended', $event->subject_type, $event->subject_uuid, [
                'evidence_uuid' => $event->uuid,
                'project_id'    => $event->project_id,
                'subject_id'    => $event->subject_id,
                'event_type'    => $event->event_type,
                'occurred_at'   => $event->occurred_at->toIso8601String(),
            ], $companyId);

            return $event;
        });
    }

    /**
     * Append many events to one project's chain in a single pass.
     *
     * The chain is sequential by construction — each event hashes the previous one's hash — so the
     * naive path re-reads the chain head once per event and opens a transaction per event. For a
     * device syncing a shift that is hundreds of round trips for a job that is inherently one
     * batch.
     *
     * This takes the chain lock once, reads the head once, and carries it forward in memory. That
     * is safe precisely *because* the advisory lock is held for the whole transaction: no other
     * writer can append to this project's chain in between, so the in-memory head cannot go stale.
     *
     * @param  list<array<string,mixed>>  $submissions  all for the same project
     * @return list<EvidenceEvent>
     */
    public function appendMany(int $projectId, array $submissions): array
    {
        if ($submissions === []) {
            return [];
        }

        foreach ($submissions as $submission) {
            $this->validate($submission);
        }

        return DB::transaction(function () use ($projectId, $submissions) {
            $companyId = $this->companyFor($submissions[0]);

            $this->lockChain($companyId, $projectId);
            $prevHash = $this->lastHash($companyId, $projectId);

            $events = [];
            $outbox = [];

            foreach ($submissions as $submission) {
                $submission['company_id'] = $companyId;
                $submission['project_id'] = $projectId;
                $submission['uuid'] ??= (string) Str::uuid7();
                $submission['confidence'] ??= '1.00';
                $submission['payload'] ??= [];
                $submission['attachments'] ??= [];

                $canonical = $this->normalise($submission);
                $canonical['prev_hash'] = $prevHash;
                $canonical['hash_version'] = self::HASH_VERSION;
                $canonical['hash'] = $this->hash($canonical, $prevHash);
                $canonical['idempotency_key'] = $submission['idempotency_key'] ?? null;

                $event = EvidenceEvent::create($canonical);

                $prevHash = $canonical['hash'];
                $events[] = $event;
                $outbox[] = [
                    'evidence_uuid' => $event->uuid,
                    'project_id'    => $event->project_id,
                    'subject_id'    => $event->subject_id,
                    'event_type'    => $event->event_type,
                    'occurred_at'   => $event->occurred_at->toIso8601String(),
                ];
            }

            // One outbox row for the batch rather than one per event: consumers care that a batch
            // landed, and 500 near-identical notifications would drown the queue the derivation
            // engine reads.
            $this->outbox->publish('pm.evidence.batch_appended', 'project', $submissions[0]['subject_uuid'] ?? null, [
                'project_id' => $projectId,
                'count'      => count($events),
                'events'     => $outbox,
            ], $companyId);

            return $events;
        });
    }

    /**
     * Correct an earlier observation: append the replacement, then point the original at it.
     *
     * The original stays in the ledger — that is the whole point. Derivation ignores superseded
     * events, but an auditor can still see what was originally claimed, by whom, and when it was
     * corrected.
     */
    public function supersede(EvidenceEvent $original, array $correction): EvidenceEvent
    {
        if ($original->isSuperseded()) {
            throw new InvalidArgumentException("Evidence {$original->uuid} is already superseded.");
        }

        return DB::transaction(function () use ($original, $correction) {
            $correction['project_id'] ??= $original->project_id;
            $correction['subject_type'] ??= $original->subject_type;
            $correction['subject_id'] ??= $original->subject_id;
            $correction['subject_uuid'] ??= $original->subject_uuid;
            $correction['occurred_at'] ??= $original->occurred_at;
            $correction['occurred_tz'] ??= $original->occurred_tz;
            $correction['source_module'] ??= 'pm';
            $correction['event_type'] ??= 'correction';
            $correction['actor_type'] ??= 'user';
            $correction['payload'] = array_merge($correction['payload'] ?? [], [
                'supersedes' => $original->uuid,
            ]);

            $replacement = $this->append($correction);

            // The one mutation the append-only trigger permits, and only from NULL, once. It goes
            // through the query builder because the model refuses every update by design.
            DB::table('pm_evidence_events')
                ->where('uuid', $original->uuid)
                ->where('occurred_at', $original->occurred_at)   // partition key — prunes to one partition
                ->update(['superseded_by' => $replacement->uuid]);

            $original->superseded_by = $replacement->uuid;

            return $replacement;
        });
    }

    /**
     * Walk a project's chain and confirm every link: each event's prev_hash must match the event
     * before it, and each event's own hash must still describe its contents.
     *
     * Linear in the project's event count, so this is an on-demand and scheduled audit, never a
     * request-path check.
     */
    public function verifyChain(?int $companyId, int $projectId): ChainVerification
    {
        $expectedPrev = null;
        $checked = 0;

        $events = EvidenceEvent::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->orderBy('id')
            ->cursor();

        foreach ($events as $event) {
            $checked++;

            if ($event->prev_hash !== $expectedPrev) {
                return ChainVerification::broken($checked, $event->uuid, 'prev_hash does not match the preceding event');
            }

            // A row written under an older hash version is chain-checked but not recomputed: its
            // hash was correct under the rules of its time, and resurrecting those rules here is
            // deliberately out of scope.
            if ($event->hash_version === self::HASH_VERSION) {
                $recomputed = $this->hash($this->normalise($event->attributesToHash()), $event->prev_hash);

                if (! hash_equals($event->hash, $recomputed)) {
                    return ChainVerification::broken($checked, $event->uuid, 'stored hash does not match the event contents');
                }
            }

            $expectedPrev = $event->hash;
        }

        return ChainVerification::intact($checked);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Serialise appends to one project's chain.
     *
     * A `SELECT ... FOR UPDATE` on the last event would not be enough: two transactions can lock
     * the same last row, and each then inserts a new one the other never saw — a forked chain with
     * two events claiming the same prev_hash. A transaction-scoped advisory lock keyed on the chain
     * itself has no such gap, and is released automatically on commit or rollback.
     */
    private function lockChain(?int $companyId, int $projectId): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtext(?)::bigint)',
            ['pm_evidence:' . ($companyId ?? 0) . ':' . $projectId]
        );
    }

    /** The hash of the most recent event in this project's chain, or null if it is the first. */
    private function lastHash(?int $companyId, int $projectId): ?string
    {
        return EvidenceEvent::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('project_id', $projectId)
            ->orderByDesc('id')
            ->value('hash');
    }

    /**
     * Put every hashed field into one exact form, whether it arrived from a caller or from the
     * database. Without this, an append hashing `5` and a verification reading back `5.0000` would
     * disagree and every chain would look broken.
     */
    private function normalise(array $attributes): array
    {
        $out = $attributes;

        $out['occurred_at'] = $attributes['occurred_at'] instanceof Carbon
            ? $attributes['occurred_at']->copy()->utc()
            : Carbon::parse($attributes['occurred_at'])->utc();

        foreach (self::DECIMAL_SCALES as $field => $scale) {
            $out[$field] = $this->normaliseDecimal($attributes[$field] ?? null, $scale, $field);
        }

        foreach (['payload', 'attachments'] as $field) {
            $value = $attributes[$field] ?? [];
            $out[$field] = is_string($value) ? (json_decode($value, true) ?? []) : $value;
        }

        return $out;
    }

    /**
     * Fixed-scale decimal as a string.
     *
     * Money never passes through a PHP float (PM-ADR-006) — a float has already lost exactness and
     * would make the hash describe something other than what was banked, so `amount` must arrive as
     * a string or an integer. Measurements (quantity, confidence, coordinates) may arrive as floats,
     * because a sensor reading is a float in the first place.
     *
     * Extra precision is rejected rather than silently rounded: the caller decides how to round its
     * own data, and a hash over a value nobody chose is a hash nobody can reproduce.
     */
    private function normaliseDecimal(mixed $value, int $scale, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value)) {
            if ($field === 'amount') {
                throw new InvalidArgumentException(
                    'Money must be supplied as a string or integer, never a float (PM-ADR-006). '
                    . "Received a float for '{$field}'."
                );
            }

            $value = sprintf('%.' . $scale . 'F', $value);
        }

        $string = trim((string) $value);

        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $string)) {
            throw new InvalidArgumentException("'{$field}' must be a decimal number, received '{$string}'.");
        }

        $negative = str_starts_with($string, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($string, '+-'), 2), 2, '');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(
                "'{$field}' allows {$scale} decimal places, received '{$string}'. Round it before recording."
            );
        }

        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($fraction, $scale, '0');

        $result = $scale > 0 ? "{$integer}.{$fraction}" : $integer;

        // Avoid "-0.00": a signed zero would hash differently from the zero the database stores.
        return $negative && (int) $integer === 0 && (int) $fraction === 0 ? $result : ($negative ? "-{$result}" : $result);
    }

    /**
     * SHA-256 over a canonical rendering of the event plus the previous hash. Fixed field order,
     * UTC timestamps to microsecond precision, decimals as fixed-scale strings, JSON with sorted
     * keys — everything that could vary between two renderings of the same event is pinned.
     */
    private function hash(array $attributes, ?string $prevHash): string
    {
        $parts = [];

        foreach (self::CANONICAL_FIELDS as $field) {
            $parts[] = $field . '=' . $this->canonicalise($attributes[$field] ?? null);
        }

        $parts[] = 'prev_hash=' . ($prevHash ?? '');

        return hash('sha256', implode("\n", $parts));
    }

    private function canonicalise(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode(
                $this->sortKeysDeep($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        }

        return (string) $value;
    }

    /** Key order must not depend on how the array happened to be built. */
    private function sortKeysDeep(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? $this->sortKeysDeep($v) : $v, $value);
    }

    private function validate(array $attributes): void
    {
        foreach (['project_id', 'subject_type', 'event_type', 'source_module', 'occurred_at', 'occurred_tz', 'actor_type'] as $required) {
            if (($attributes[$required] ?? null) === null || $attributes[$required] === '') {
                throw new InvalidArgumentException("Evidence requires '{$required}'.");
            }
        }

        if (! in_array($attributes['subject_type'], EvidenceEvent::SUBJECT_TYPES, true)) {
            throw new InvalidArgumentException("Unknown evidence subject type '{$attributes['subject_type']}'.");
        }

        if (! in_array($attributes['actor_type'], EvidenceEvent::ACTOR_TYPES, true)) {
            throw new InvalidArgumentException("Unknown evidence actor type '{$attributes['actor_type']}'.");
        }

        // Money without a currency is unusable downstream (DoD 7). The database enforces this too;
        // failing here gives the caller a sentence rather than an SQLSTATE.
        if (($attributes['amount'] ?? null) !== null && empty($attributes['currency'])) {
            throw new InvalidArgumentException('Evidence carrying an amount must state its currency.');
        }

        $confidence = (float) ($attributes['confidence'] ?? 1);
        if ($confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Confidence must be between 0.00 and 1.00.');
        }

        // A timestamp without its originating zone is ambiguous the moment it crosses a boundary.
        if (! in_array($attributes['occurred_tz'], timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("'{$attributes['occurred_tz']}' is not a valid IANA timezone.");
        }
    }
}
