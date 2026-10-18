<?php

namespace App\Modules\Procurement\Governance\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;

/**
 * The Universal Audit Trail (§7). Every capability in this module writes here, and nothing in this
 * module writes anywhere else for audit purposes.
 *
 * Each event carries who (and the role they held at the time), what, which record, when, from
 * where, the before/after values, and — for an approval — the decision and its comment. §7's
 * example line, "08 Aug 2026 10:42 — Procurement Manager approved Deviation DEV-2026-0042", is
 * `summary()` rendered from exactly those fields.
 *
 * Immutability has two layers, because they defend against different attacks. The table's
 * BEFORE UPDATE OR DELETE trigger stops anything reachable through the application. The hash chain
 * makes tampering *detectable* by someone who could drop that trigger: each event hashes its own
 * content together with the previous event's hash, so altering or removing any row breaks every
 * hash after it and `procgov:verify-ledger` reports the exact position where the chain parts.
 *
 * Writes are best-effort by design. An audit write that throws would roll back the business
 * transaction it was recording, which turns a logging fault into a service outage — and the failure
 * is reported so it is not silent. The one thing that must never happen is the reverse: a business
 * change that succeeds while its audit event is quietly dropped. That is why `write()` runs inside
 * the caller's transaction, not after it.
 */
class AuditLedger
{
    public function __construct(private readonly ReferenceGenerator $sequences) {}

    /**
     * Record an event.
     *
     * @param  array<string,mixed>  $attributes
     *                                           action           string  required — created | submitted | approved | scored | …
     *                                           entity_type      string  required — the table or domain type acted on
     *                                           entity_id        int|null
     *                                           entity_uuid      string|null
     *                                           entity_reference string|null — DEV-2026-0042
     *                                           record_id        int|null    — the 360 spine, when there is one
     *                                           capability       string|null — planning | tender_evaluation | …
     *                                           summary          string|null — rendered if omitted
     *                                           before/after     array|null  — only the fields that changed
     *                                           decision         string|null
     *                                           comment          string|null
     *                                           actor            Authenticatable|null — defaults to the authenticated user
     *                                           occurred_at      Carbon|null
     */
    public function record(int $companyId, array $attributes): ?int
    {
        try {
            return $this->write($companyId, $attributes);
        } catch (\Throwable $e) {
            // Reported, never rethrown: see the class docblock.
            report($e);

            return null;
        }
    }

    /**
     * As record(), but lets the exception escape. Use where the audit event is itself the point of
     * the operation — verifying the ledger, exporting it, or an approval whose only durable
     * artefact is the decision line.
     */
    public function write(int $companyId, array $attributes): int
    {
        // Wrapped even when the caller already has a transaction open (it nests as a savepoint):
        // the row lock taken while claiming the sequence must still be held when the previous hash
        // is read, or two concurrent writers chain off the same predecessor and one of them is
        // orphaned. Row locks survive a savepoint release, so nesting is safe; running unwrapped
        // is not.
        return DB::transaction(fn () => $this->insertEvent($companyId, $attributes));
    }

    private function insertEvent(int $companyId, array $attributes): int
    {
        $actor = $attributes['actor'] ?? Auth::user();
        $occurredAt = $attributes['occurred_at'] ?? Carbon::now();

        $before = $this->normaliseValues($attributes['before'] ?? null);
        $after = $this->normaliseValues($attributes['after'] ?? null);

        // Claiming the sequence takes a row lock, which also serialises the read of the previous
        // hash below — two concurrent writers cannot both chain off the same predecessor.
        $sequence = $this->sequences->claim($companyId, 'audit', 0);

        $previousHash = DB::table('procgov_audit_events')
            ->where('company_id', $companyId)
            ->orderByDesc('sequence')
            ->value('hash');

        $payload = [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'sequence' => $sequence,
            'occurred_at' => $occurredAt,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_type' => $attributes['actor_type'] ?? ($actor ? 'user' : 'system'),
            'actor_label' => $attributes['actor_label'] ?? $this->actorLabel($actor),
            'actor_role' => $attributes['actor_role'] ?? $this->actorRole($actor),
            'action' => $attributes['action'],
            'module' => $attributes['module'] ?? 'procurement_governance',
            'capability' => $attributes['capability'] ?? null,
            'entity_type' => $attributes['entity_type'],
            'entity_id' => $attributes['entity_id'] ?? null,
            'entity_uuid' => $attributes['entity_uuid'] ?? null,
            'entity_reference' => $attributes['entity_reference'] ?? null,
            'record_id' => $attributes['record_id'] ?? null,
            'summary' => Str::limit(
                $attributes['summary'] ?? $this->renderSummary($attributes, $actor),
                490,
                ''
            ),
            'before' => $before !== null ? json_encode($before) : null,
            'after' => $after !== null ? json_encode($after) : null,
            'decision' => $attributes['decision'] ?? null,
            'comment' => $attributes['comment'] ?? null,
            'previous_hash' => $previousHash,
            'created_at' => Carbon::now(),
        ];

        $payload = array_merge($payload, $this->requestContext());
        $payload['hash'] = $this->hash($payload);

        return (int) DB::table('procgov_audit_events')->insertGetId($payload);
    }

    /**
     * Walk a tenant's chain and report the first position where it does not reconcile.
     *
     * @return array{ok:bool, checked:int, broken_at:int|null, reason:string|null}
     */
    public function verify(int $companyId, int $chunk = 1000): array
    {
        $expectedPrevious = null;
        $checked = 0;
        $expectedSequence = null;

        $cursor = DB::table('procgov_audit_events')
            ->where('company_id', $companyId)
            ->orderBy('sequence')
            ->lazyById($chunk, 'id');

        foreach ($cursor as $event) {
            $checked++;

            // A gap means a row was removed; the sequence is claimed gap-free.
            if ($expectedSequence !== null && (int) $event->sequence !== $expectedSequence) {
                return [
                    'ok' => false,
                    'checked' => $checked,
                    'broken_at' => (int) $event->sequence,
                    'reason' => "sequence gap: expected {$expectedSequence}, found {$event->sequence}",
                ];
            }

            if ($event->previous_hash !== $expectedPrevious) {
                return [
                    'ok' => false,
                    'checked' => $checked,
                    'broken_at' => (int) $event->sequence,
                    'reason' => 'previous_hash does not match the preceding event',
                ];
            }

            if ($this->hash((array) $event) !== $event->hash) {
                return [
                    'ok' => false,
                    'checked' => $checked,
                    'broken_at' => (int) $event->sequence,
                    'reason' => 'event content does not match its recorded hash',
                ];
            }

            $expectedPrevious = $event->hash;
            $expectedSequence = (int) $event->sequence + 1;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The hashed fields are the ones whose alteration would change what the event *means*. IP and
     * user agent are deliberately outside the hash: they are environmental, they are occasionally
     * corrected by a proxy change, and including them would produce false tamper reports.
     */
    private function hash(array $payload): string
    {
        $material = [
            'company_id' => $payload['company_id'] ?? null,
            'sequence' => $payload['sequence'] ?? null,
            'occurred_at' => $this->stringify($payload['occurred_at'] ?? null),
            'actor_id' => $payload['actor_id'] ?? null,
            'actor_label' => $payload['actor_label'] ?? null,
            'actor_role' => $payload['actor_role'] ?? null,
            'action' => $payload['action'] ?? null,
            'entity_type' => $payload['entity_type'] ?? null,
            'entity_id' => $payload['entity_id'] ?? null,
            'entity_reference' => $payload['entity_reference'] ?? null,
            'record_id' => $payload['record_id'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'before' => $this->stringify($payload['before'] ?? null),
            'after' => $this->stringify($payload['after'] ?? null),
            'decision' => $payload['decision'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'previous_hash' => $payload['previous_hash'] ?? null,
        ];

        return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Values must hash identically whether they arrive as a Carbon from a fresh write or as a
     * string from a row read back during verification — otherwise every event would "fail"
     * verification the moment it was checked.
     */
    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc()->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    private function normaliseValues(mixed $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $values = (array) $values;

        if ($values === []) {
            return null;
        }

        // Stable ordering: an unordered array would hash differently on re-verification.
        ksort($values);

        return $values;
    }

    /** @return array<string,string|null> */
    private function requestContext(): array
    {
        if (! app()->runningInConsole() && app()->bound('request')) {
            $request = RequestFacade::instance();

            return [
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
                'session_id' => $request->hasSession() ? substr($request->session()->getId(), 0, 64) : null,
                'request_id' => substr((string) ($request->headers->get('X-Request-Id') ?: Str::uuid()), 0, 64),
                'route' => Str::limit($request->route()?->getName() ?: $request->path(), 185, ''),
            ];
        }

        return [
            'ip' => null,
            'user_agent' => null,
            'session_id' => null,
            'request_id' => null,
            'route' => 'console',
        ];
    }

    /**
     * There is no `users.name` column on this platform — a person's name lives on their HR record,
     * so the label is resolved through it and falls back to the login email.
     */
    private function actorLabel(?Authenticatable $actor): ?string
    {
        if (! $actor) {
            return 'System';
        }

        $person = $actor->person ?? null;

        if ($person) {
            $name = trim(($person->first_name ?? '').' '.($person->surname ?? ''));

            if ($name !== '') {
                return Str::limit($name, 185, '');
            }
        }

        return Str::limit((string) ($actor->email ?? "User #{$actor->getAuthIdentifier()}"), 185, '');
    }

    /** The role held at the time — not the role they hold when someone reads the log years later. */
    private function actorRole(?Authenticatable $actor): ?string
    {
        if (! $actor || ! method_exists($actor, 'getRoleNames')) {
            return null;
        }

        try {
            return Str::limit($actor->getRoleNames()->implode(', '), 115, '') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function renderSummary(array $attributes, ?Authenticatable $actor): string
    {
        $who = $this->actorRole($actor) ?: $this->actorLabel($actor) ?: 'System';
        $what = str_replace('_', ' ', (string) ($attributes['action'] ?? 'acted on'));
        $subject = $attributes['entity_reference']
            ?? $attributes['entity_uuid']
            ?? ('#'.($attributes['entity_id'] ?? '?'));
        $type = Str::headline(str_replace('procgov_', '', (string) $attributes['entity_type']));

        return trim("{$who} {$what} {$type} {$subject}");
    }
}
