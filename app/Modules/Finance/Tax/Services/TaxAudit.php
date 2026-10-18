<?php

namespace App\Modules\Finance\Tax\Services;

use App\Modules\Finance\Tax\Models\TaxAuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the tax audit trail (§17).
 *
 * Called inside the caller's transaction, so the audit row and the change it describes commit or
 * roll back together: a failed operation cannot leave a successful-looking audit entry behind.
 * Denied and failed operations are recorded with `denied()` / `failed()` outside that transaction,
 * because the point of those is that nothing else was written.
 *
 * Each row is chained to the tenant's previous row by hash, and the table refuses UPDATE and
 * DELETE at the database, so a rewritten history is detectable as well as prevented.
 */
class TaxAudit
{
    private ?string $correlationId = null;

    /** One id across every event written while handling one request or job. */
    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $extra  entity_id, tax_type, period_id, rule_version_id, reason, before, after, context
     */
    public function record(string $action, Model|string $subject, array $extra = [], string $outcome = 'success'): TaxAuditEvent
    {
        $subjectType = is_string($subject) ? $subject : class_basename($subject);
        $subjectId = is_string($subject) ? ($extra['subject_id'] ?? null) : $subject->getKey();
        $companyId = $extra['company_id'] ?? (is_string($subject) ? null : $subject->company_id ?? null) ?? Auth::user()?->company_id;
        $user = Auth::user();

        $row = [
            'company_id' => $companyId,
            'entity_id' => $extra['entity_id'] ?? (is_string($subject) ? null : $subject->entity_id ?? null),
            'actor_type' => $user ? 'user' : 'service',
            'actor_id' => $user?->id,
            'actor_name' => $user ? $this->nameOf($user) : ($extra['service'] ?? 'system'),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'tax_type' => $extra['tax_type'] ?? null,
            'period_id' => $extra['period_id'] ?? null,
            'rule_version_id' => $extra['rule_version_id'] ?? null,
            'outcome' => $outcome,
            'reason' => $extra['reason'] ?? null,
            'before' => $extra['before'] ?? null,
            'after' => $extra['after'] ?? null,
            'context' => $extra['context'] ?? null,
            'correlation_id' => $this->correlationId(),
            'created_at' => now(),
        ];

        // Stored as jsonb and read back as decoded JSON, so hash exactly that: a date object here
        // and its ISO string there would never verify against each other.
        foreach (['before', 'after', 'context'] as $k) {
            $row[$k] = $row[$k] === null ? null : json_decode(json_encode($row[$k]), true);
        }

        return DB::transaction(function () use ($row, $companyId) {
            // Serialise the chain per tenant: two writers reading the same "previous" would fork it.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('tax_audit:' . ($companyId ?? 0))]);
            }

            $previous = TaxAuditEvent::query()->withoutGlobalScopes()
                ->where('company_id', $companyId)->orderByDesc('id')->value('hash');

            $row['previous_hash'] = $previous;
            $row['hash'] = $this->hashOf($previous, $row['company_id'], $row['actor_id'], $row['action'], $row['subject_type'],
                $row['subject_id'], $row['outcome'], $row['reason'], $row['before'], $row['after'], $row['created_at']);

            return TaxAuditEvent::create($row);
        });
    }

    public function denied(string $action, Model|string $subject, string $reason, array $extra = []): void
    {
        $this->record($action, $subject, $extra + ['reason' => $reason], 'denied');
    }

    public function failed(string $action, Model|string $subject, string $reason, array $extra = []): void
    {
        $this->record($action, $subject, $extra + ['reason' => $reason], 'failed');
    }

    /** Recompute the chain for a tenant; the first broken link, or null when intact. */
    public function verifyChain(int $companyId): ?int
    {
        $previous = null;
        foreach (TaxAuditEvent::query()->withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->cursor() as $e) {
            $expected = $this->hashOf($previous, $e->company_id, $e->actor_id, $e->action, $e->subject_type,
                $e->subject_id, $e->outcome, $e->reason, $e->before, $e->after, $e->created_at);
            if ($e->previous_hash !== $previous || $e->hash !== $expected) {
                return $e->id;
            }
            $previous = $e->hash;
        }

        return null;
    }

    /**
     * The hash over a canonical form. jsonb stores object keys in its own order, so the arrays are
     * key-sorted and the timestamp reduced to UTC seconds before hashing — otherwise a row read
     * back from the database would never verify against the hash written for it.
     */
    private function hashOf(?string $previous, ...$fields): string
    {
        $canon = function ($v) use (&$canon) {
            if ($v instanceof \DateTimeInterface) {
                return \Illuminate\Support\Carbon::instance($v)->utc()->format('Y-m-d\TH:i:s\Z');
            }
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v);
                }

                return array_map($canon, $v);
            }

            return $v === null ? null : (string) $v;
        };

        return hash('sha256', ($previous ?? '') . '|' . json_encode(array_map($canon, $fields), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function nameOf(?object $user): ?string
    {
        if (! $user) {
            return null;
        }
        $person = $user->person ?? null;

        return $person ? (trim(($person->first_name ?? '') . ' ' . ($person->surname ?? '')) ?: $user->email) : $user->email;
    }
}
