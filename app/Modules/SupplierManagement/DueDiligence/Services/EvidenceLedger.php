<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;

/**
 * The due-diligence evidence chain: what was claimed, what the source said, who looked, and when.
 *
 * Why a second ledger rather than the platform's `activity_log`: that table is rewritten by the
 * POPIA anonymisation and retention-purge commands, so it cannot be made immutable. A supplier
 * approval has to be defensible to an auditor years later, so the evidence behind it gets the same
 * treatment as the Procurement Governance audit trail — append-only at the database, plus a
 * per-tenant hash chain that makes tampering detectable even by someone who drops the trigger.
 *
 * Write failures are reported, not thrown (see `record`), because an evidence write that rolls back
 * the verification it was recording turns a logging fault into an outage. The reverse — a check that
 * succeeds while its evidence is silently dropped — is the thing that must never happen, which is
 * why the write runs inside the caller's transaction rather than after it.
 */
class EvidenceLedger
{
    /**
     * Record an entry, swallowing (but reporting) failures.
     *
     * @param  array<string,mixed>  $attributes  action, supplier_id, check_type, provider_code,
     *                                          status, summary, claimed, observed, payload, actor
     */
    public function record(int $companyId, array $attributes): ?int
    {
        try {
            return $this->write($companyId, $attributes);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** As record(), but lets the exception escape — for when the entry itself is the point. */
    public function write(int $companyId, array $attributes): int
    {
        // Nested transactions become savepoints, and the row lock taken while claiming the sequence
        // survives a savepoint release — so wrapping is safe even mid-transaction, and running
        // unwrapped is not: two writers would chain off the same predecessor and orphan one.
        return DB::transaction(fn () => $this->insert($companyId, $attributes));
    }

    private function insert(int $companyId, array $attributes): int
    {
        $actor = $attributes['actor'] ?? Auth::user();
        $sequence = $this->claimSequence($companyId);

        $previousHash = DB::table('dd_evidence_entries')
            ->where('company_id', $companyId)
            ->orderByDesc('sequence')
            ->value('hash');

        $payload = [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'sequence' => $sequence,
            'occurred_at' => $attributes['occurred_at'] ?? Carbon::now(),
            'supplier_id' => $attributes['supplier_id'] ?? null,
            'subject_type' => $attributes['subject_type'] ?? null,
            'subject_id' => $attributes['subject_id'] ?? null,
            'action' => $attributes['action'],
            'check_type' => $attributes['check_type'] ?? null,
            'provider_code' => $attributes['provider_code'] ?? null,
            'status' => $attributes['status'] ?? null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_type' => $attributes['actor_type'] ?? ($actor ? 'user' : 'system'),
            'actor_label' => $attributes['actor_label'] ?? $this->actorLabel($actor),
            'actor_role' => $attributes['actor_role'] ?? $this->actorRole($actor),
            'summary' => Str::limit((string) ($attributes['summary'] ?? $attributes['action']), 490, ''),
            'claimed' => isset($attributes['claimed']) ? json_encode($attributes['claimed']) : null,
            'observed' => isset($attributes['observed']) ? json_encode($attributes['observed']) : null,
            'payload' => isset($attributes['payload']) ? json_encode($attributes['payload']) : null,
            'previous_hash' => $previousHash,
            'created_at' => Carbon::now(),
        ];

        $payload = array_merge($payload, $this->requestContext());
        $payload['hash'] = $this->hash($payload);

        return (int) DB::table('dd_evidence_entries')->insertGetId($payload);
    }

    /**
     * Hand out the next gap-free number for this tenant, holding a row lock while doing it.
     *
     * The lock is what serialises concurrent writers: the second one waits here, so by the time it
     * reads `previous_hash` the first has already committed its row. A gap-free sequence also means
     * a deleted entry shows up as a hole rather than having to be inferred from timestamps.
     */
    private function claimSequence(int $companyId): int
    {
        $row = DB::table('dd_evidence_sequences')->where('company_id', $companyId)->lockForUpdate()->first();

        if (! $row) {
            // Two writers can race to create the counter; whoever loses the insert re-reads under
            // the lock instead of failing.
            try {
                DB::table('dd_evidence_sequences')->insert(['company_id' => $companyId, 'next_sequence' => 2]);

                return 1;
            } catch (\Illuminate\Database\QueryException) {
                $row = DB::table('dd_evidence_sequences')->where('company_id', $companyId)->lockForUpdate()->first();
            }
        }

        $sequence = (int) $row->next_sequence;
        DB::table('dd_evidence_sequences')->where('company_id', $companyId)->update(['next_sequence' => $sequence + 1]);

        return $sequence;
    }

    /**
     * Walk a tenant's chain and report the first position where it does not reconcile.
     *
     * @return array{ok:bool, checked:int, broken_at:int|null, reason:string|null}
     */
    public function verify(int $companyId, int $chunk = 1000): array
    {
        $checked = 0;
        $expectedPrevious = null;
        $expectedSequence = 1;
        $broken = null;

        DB::table('dd_evidence_entries')
            ->where('company_id', $companyId)
            ->orderBy('sequence')
            ->chunk($chunk, function ($rows) use (&$checked, &$expectedPrevious, &$expectedSequence, &$broken) {
                foreach ($rows as $row) {
                    if ((int) $row->sequence !== $expectedSequence) {
                        $broken = ['at' => (int) $row->sequence, 'reason' => "sequence gap: expected {$expectedSequence}"];

                        return false;
                    }

                    if ($row->previous_hash !== $expectedPrevious) {
                        $broken = ['at' => (int) $row->sequence, 'reason' => 'previous_hash does not match the entry before it'];

                        return false;
                    }

                    if ($this->hash((array) $row) !== $row->hash) {
                        $broken = ['at' => (int) $row->sequence, 'reason' => 'content does not match its hash'];

                        return false;
                    }

                    $expectedPrevious = $row->hash;
                    $expectedSequence++;
                    $checked++;
                }

                return true;
            });

        return [
            'ok' => $broken === null,
            'checked' => $checked,
            'broken_at' => $broken['at'] ?? null,
            'reason' => $broken['reason'] ?? null,
        ];
    }

    /**
     * The hash covers every field that carries meaning, in a fixed order.
     *
     * `hash` and `id` are excluded — one is the output, the other is assigned by the database. Any
     * other field being left out would be a field an attacker could edit undetected, so new columns
     * must be added here as well as to the migration.
     */
    private function hash(array $row): string
    {
        $material = [];

        foreach ([
            'uuid', 'company_id', 'sequence', 'occurred_at', 'supplier_id', 'subject_type', 'subject_id',
            'action', 'check_type', 'provider_code', 'status', 'actor_id', 'actor_type', 'actor_label',
            'actor_role', 'summary', 'claimed', 'observed', 'payload', 'ip', 'user_agent', 'route',
            'previous_hash', 'created_at',
        ] as $field) {
            $value = $row[$field] ?? null;

            // Timestamps arrive as Carbon on the way in and as strings on the way back out of the
            // database; normalising both to the same format is what lets a stored row re-hash to the
            // value it was written with.
            if ($value instanceof \DateTimeInterface) {
                $value = Carbon::instance($value)->format('Y-m-d H:i:s');
            } elseif ($field === 'occurred_at' || $field === 'created_at') {
                $value = $value === null ? null : Carbon::parse($value)->format('Y-m-d H:i:s');
            }

            $material[$field] = $value === null ? null : (string) $value;
        }

        return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function requestContext(): array
    {
        if (app()->runningInConsole()) {
            return ['ip' => null, 'user_agent' => null, 'route' => null];
        }

        return [
            'ip' => RequestFacade::ip(),
            'user_agent' => Str::limit((string) RequestFacade::userAgent(), 250, ''),
            'route' => Str::limit((string) RequestFacade::path(), 185, ''),
        ];
    }

    private function actorLabel(?Authenticatable $actor): ?string
    {
        if (! $actor) {
            return null;
        }

        // There is no users.name column in this schema — a person's name lives on hr_people.
        $person = $actor->person ?? null;

        return $person
            ? trim("{$person->first_name} {$person->surname}") ?: $actor->email
            : ($actor->email ?? null);
    }

    private function actorRole(?Authenticatable $actor): ?string
    {
        if (! $actor || ! method_exists($actor, 'getRoleNames')) {
            return null;
        }

        try {
            return $actor->getRoleNames()->implode(', ') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
