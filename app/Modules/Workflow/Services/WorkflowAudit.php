<?php

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Models\WorkflowAuditEvent;
use App\Modules\Workflow\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The workflow audit trail (PRS §18), on the pattern of the Tax module's ledger.
 *
 * Written inside the caller's transaction, so the audit row and the change it describes commit or
 * roll back together. Denied and failed operations are recorded with denied()/failed() outside it.
 * Each row is chained to the tenant's previous row by hash, and the table refuses UPDATE and DELETE
 * at the database, so a rewritten history is detectable as well as prevented.
 */
class WorkflowAudit
{
    private ?string $correlationId = null;

    /** Set by the engine when it acts for a person who is not signed in (a delegate, the tick). */
    private ?array $actor = null;

    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid();
    }

    /** Run $fn with audit rows attributed to the service (or AI) rather than the signed-in user. */
    public function as(string $type, string $name, callable $fn): mixed
    {
        $previous = $this->actor;
        $this->actor = ['type' => $type, 'name' => $name];
        try {
            return $fn();
        } finally {
            $this->actor = $previous;
        }
    }

    /**
     * @param  array<string, mixed>  $extra  instance (WorkflowInstance), definition_id, version, reason, before, after, context, company_id
     */
    public function record(string $action, Model|string $subject, array $extra = [], string $outcome = 'success'): WorkflowAuditEvent
    {
        $instance = $extra['instance'] ?? ($subject instanceof WorkflowInstance ? $subject : null);
        $subjectType = is_string($subject) ? $subject : class_basename($subject);
        $subjectId = is_string($subject) ? ($extra['subject_id'] ?? null) : ($subject->uuid ?? $subject->getKey());
        $companyId = $extra['company_id'] ?? (is_string($subject) ? null : $subject->company_id ?? null) ?? $instance?->company_id ?? Auth::user()?->company_id;
        $user = $this->actor ? null : Auth::user();

        $row = [
            'company_id' => $companyId,
            'actor_type' => $this->actor['type'] ?? ($user ? 'user' : 'service'),
            'actor_id' => $user?->id,
            'actor_name' => $this->actor['name'] ?? ($user ? $this->nameOf($user) : 'system'),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'instance_id' => $instance?->id,
            'definition_id' => $extra['definition_id'] ?? $instance?->definition_id,
            'version' => $extra['version'] ?? null,
            'outcome' => $outcome,
            'reason' => $extra['reason'] ?? null,
            'before' => $extra['before'] ?? null,
            'after' => $extra['after'] ?? null,
            'context' => $extra['context'] ?? null,
            'correlation_id' => $this->correlationId(),
            'created_at' => now(),
        ];
        foreach (['before', 'after', 'context'] as $k) {
            $row[$k] = $row[$k] === null ? null : json_decode(json_encode($row[$k]), true);
        }

        return DB::transaction(function () use ($row, $companyId) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('workflow_audit:' . ($companyId ?? 0))]);
            }
            $previous = WorkflowAuditEvent::query()->withoutGlobalScopes()->where('company_id', $companyId)->orderByDesc('id')->value('hash');
            $row['previous_hash'] = $previous;
            $row['hash'] = $this->hashOf($previous, $row['company_id'], $row['actor_id'], $row['action'], $row['subject_type'],
                $row['subject_id'], $row['outcome'], $row['reason'], $row['before'], $row['after'], $row['created_at']);

            return WorkflowAuditEvent::query()->withoutGlobalScopes()->create($row);
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

    /** Recompute the chain for a tenant; the id of the first broken link, or null when intact. */
    public function verifyChain(int $companyId): ?int
    {
        $previous = null;
        foreach (WorkflowAuditEvent::query()->withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->cursor() as $e) {
            $expected = $this->hashOf($previous, $e->company_id, $e->actor_id, $e->action, $e->subject_type,
                $e->subject_id, $e->outcome, $e->reason, $e->before, $e->after, $e->created_at);
            if ($e->previous_hash !== $previous || $e->hash !== $expected) {
                return $e->id;
            }
            $previous = $e->hash;
        }

        return null;
    }

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
