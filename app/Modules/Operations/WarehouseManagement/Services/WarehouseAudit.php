<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The warehouse audit ledger (WMS-203): security-relevant and business-critical actions, each
 * hash-chained to the one before it in its company and protected by a database trigger.
 *
 * Writing is part of the action: it runs in the caller's transaction, and if the record cannot be
 * written the action does not happen. Values are redacted of anything that looks like a secret.
 */
class WarehouseAudit
{
    private const SECRET = '/pass|secret|token|api[_-]?key|authorization|credential/i';

    public function record(int $companyId, string $action, string $entityType, ?string $entityRef, string $summary, ?array $before = null, ?array $after = null, ?User $actor = null): int
    {
        if ($companyId <= 0) {
            return 0; // platform-wide rows (shared defaults) belong to no company's ledger
        }
        // During a fresh install, models are saved by migrations that run before this table exists.
        static $ready = null;
        if (! ($ready ??= \Illuminate\Support\Facades\Schema::hasTable('wms_audit_events'))) {
            $ready = null;

            return 0;
        }
        $actor ??= auth()->user();
        $token = $actor && method_exists($actor, 'currentAccessToken') ? $actor->currentAccessToken() : null;
        $request = app()->runningInConsole() ? null : request();

        return DB::transaction(function () use ($companyId, $action, $entityType, $entityRef, $summary, $before, $after, $actor, $token, $request) {
            // One writer per company at a time, so sequences and the chain never fork.
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [7310, $companyId]);
            $last = DB::table('wms_audit_events')->where('company_id', $companyId)->orderByDesc('sequence')->first(['sequence', 'hash']);
            $row = ['company_id' => $companyId, 'sequence' => (int) ($last->sequence ?? 0) + 1, 'occurred_at' => now()->startOfSecond()->toIso8601String(),
                'actor_id' => $actor?->id, 'actor_label' => $actor ? ($token instanceof \Laravel\Sanctum\PersonalAccessToken ? "{$actor->email} (token {$token->name})" : $actor->email) : 'system',
                'actor_type' => $actor ? ($token instanceof \Laravel\Sanctum\PersonalAccessToken ? 'TOKEN' : 'USER') : 'SYSTEM', 'action' => $action, 'entity_type' => $entityType,
                'entity_ref' => $entityRef !== null ? mb_substr($entityRef, 0, 80) : null, 'summary' => mb_substr($summary, 0, 500),
                'before' => $before !== null ? json_encode($this->redact($before)) : null, 'after' => $after !== null ? json_encode($this->redact($after)) : null];
            $row['previous_hash'] = $last->hash ?? null;
            $row['hash'] = $this->hash($row);

            return DB::table('wms_audit_events')->insertGetId($row + ['ip' => $request?->ip(), 'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
                'created_at' => now()]);
        });
    }

    /** @return array{ok: bool, checked: int, broken_at: ?int, reason: ?string} */
    public function verify(int $companyId): array
    {
        $previous = false;
        $checked = 0;
        foreach (DB::table('wms_audit_events')->where('company_id', $companyId)->orderBy('sequence')->cursor() as $r) {
            $material = ['company_id' => (int) $r->company_id, 'sequence' => (int) $r->sequence, 'occurred_at' => $r->occurred_at, 'actor_id' => $r->actor_id ? (int) $r->actor_id : null,
                'actor_label' => $r->actor_label, 'actor_type' => $r->actor_type, 'action' => $r->action, 'entity_type' => $r->entity_type, 'entity_ref' => $r->entity_ref,
                'summary' => $r->summary, 'before' => $r->before, 'after' => $r->after, 'previous_hash' => $r->previous_hash];
            // The first remaining row anchors the chain (older ones may have passed retention).
            if ($previous !== false && $r->previous_hash !== $previous) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r->sequence, 'reason' => 'A row before this one was removed or altered.'];
            }
            if (! hash_equals($r->hash, $this->hash($material))) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) $r->sequence, 'reason' => 'This row does not match its hash.'];
            }
            $previous = $r->hash;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
    }

    private function hash(array $r): string
    {
        // jsonb reorders object keys and the database returns timestamps in its own zone: hash a canonical form.
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };
        $norm = fn ($v) => $v === null ? null : $sort(is_string($v) ? json_decode($v, true) : $v);
        $material = [(int) $r['company_id'], (int) $r['sequence'], \Illuminate\Support\Carbon::parse($r['occurred_at'])->utc()->format('Y-m-d\TH:i:s'), $r['actor_id'] !== null ? (int) $r['actor_id'] : null, $r['actor_label'], $r['actor_type'],
            $r['action'], $r['entity_type'], $r['entity_ref'], $r['summary'], $norm($r['before']), $norm($r['after']), $r['previous_hash']];

        return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET, $k)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }

        return $data;
    }
}
