<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The approval workflow (WMS-191): levels by value, a deadline per level, delegation, escalation.
 *
 * A company sets a policy per kind of request: the levels it passes through, each with the
 * permission that may decide it and the value from which it applies. Without a policy a request
 * needs one approval from the kind's own permission, as before. Each level is decided by a
 * different person, never the requester; somebody away can delegate their authority for a period,
 * and the delegate's decision records on whose behalf it was made. A level past its deadline is
 * escalated through the notifier.
 */
class WarehouseApprovals
{
    /** The override that allowed the decision being recorded, if any. */
    private ?object $override = null;

    /** kind => [label, the permission a single-level approval needs] */
    public const SUBJECTS = [
        'ADJUSTMENT' => ['Stock adjustment', RoleCatalog::APPROVE_ADJUSTMENTS],
        'REVALUATION' => ['Revaluation', RoleCatalog::APPROVE_ACCOUNTING],
    ];

    public function policy(int $companyId, string $type): ?object
    {
        $p = DB::table('wms_approval_policies')->where('company_id', $companyId)->where('subject_type', $type)->where('is_active', true)->first();
        if ($p) {
            $p->levels = json_decode($p->levels, true);
            $p->escalate_to_permissions = $p->escalate_to_permissions ? json_decode($p->escalate_to_permissions, true) : [];
        }

        return $p;
    }

    public function savePolicy(int $companyId, string $type, array $levels, int $dueHours, array $escalateTo, bool $active, User $by): void
    {
        if (! isset(self::SUBJECTS[$type])) {
            throw new RuntimeException("Unknown approval kind {$type}.");
        }
        if ($levels === []) {
            throw new RuntimeException('A policy needs at least one level.');
        }
        foreach ($levels as $i => $l) {
            if (! in_array($l['permission'] ?? null, RoleCatalog::all(), true)) {
                throw new RuntimeException('Level '.($i + 1).' names a permission the warehouse does not have.');
            }
            if ($i > 0 && (float) $l['min_value'] < (float) $levels[$i - 1]['min_value']) {
                throw new RuntimeException('Each level must start at or above the value of the level before it.');
            }
        }
        DB::table('wms_approval_policies')->updateOrInsert(['company_id' => $companyId, 'subject_type' => $type], [
            'levels' => json_encode(array_values(array_map(fn ($l) => ['name' => (string) $l['name'], 'permission' => $l['permission'], 'min_value' => (float) $l['min_value']], $levels))),
            'due_hours' => max(1, $dueHours), 'escalate_to_permissions' => json_encode(array_values($escalateTo)), 'is_active' => $active,
            'updated_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
        app(WarehouseAudit::class)->record($companyId, 'approval_policy.changed', 'APPROVAL_POLICY', $type, "Approval levels for {$type} changed", null,
            ['levels' => $levels, 'due_hours' => $dueHours, 'escalate_to' => $escalateTo, 'active' => $active], $by);
    }

    /** Lay out the levels a request passes through. Without a policy there is nothing to lay out. */
    public function start(int $companyId, string $type, int $id, string $ref, float $value, ?int $requestedBy): void
    {
        $p = $this->policy($companyId, $type);
        if (! $p || DB::table('wms_approval_steps')->where('subject_type', $type)->where('subject_id', $id)->exists()) {
            return;
        }
        $levels = array_values(array_filter($p->levels, fn ($l, $i) => $i === 0 || $value + 1e-9 >= (float) $l['min_value'], ARRAY_FILTER_USE_BOTH));
        foreach ($levels as $i => $l) {
            DB::table('wms_approval_steps')->insert(['company_id' => $companyId, 'subject_type' => $type, 'subject_id' => $id, 'subject_ref' => $ref, 'level' => $i + 1,
                'name' => $l['name'], 'permission' => $l['permission'], 'value' => round($value, 2), 'status' => 'PENDING', 'requested_by' => $requestedBy,
                'due_at' => now()->addHours($p->due_hours * ($i + 1)), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Record an approval. True when the request is fully approved and may take effect; false when
     * another level still has to decide.
     */
    public function approve(int $companyId, string $type, int $id, string $ref, float $value, ?int $requestedBy, User $user, ?string $note): bool
    {
        $this->start($companyId, $type, $id, $ref, $value, $requestedBy);
        $step = $this->current($type, $id);
        if (! $step) {
            $this->assertMay($user, self::SUBJECTS[$type][1], $type, $requestedBy, null);
            $this->noteOverride($type, $ref);

            return true;
        }
        $behalf = $this->assertMay($user, $step->permission, $type, $requestedBy, $step);
        DB::table('wms_approval_steps')->where('id', $step->id)->update(['status' => 'APPROVED', 'decided_by' => $user->id, 'on_behalf_of' => $behalf,
            'decided_at' => now(), 'note' => $note, 'override_id' => $this->override?->id, 'updated_at' => now()]);
        $this->noteOverride($type, $ref);
        app(WarehouseAudit::class)->record($companyId, 'approval.approved', $type, $ref, "Level {$step->level} ({$step->name}) approved".($behalf ? " on behalf of user #{$behalf}" : ''),
            null, ['level' => (int) $step->level, 'on_behalf_of' => $behalf, 'override' => $this->override?->id], $user);
        $next = $this->current($type, $id);
        if ($next) {
            $hours = (int) ($this->policy($companyId, $type)?->due_hours ?? 24);
            DB::table('wms_approval_steps')->where('id', $next->id)->update(['due_at' => now()->addHours($hours), 'updated_at' => now()]);

            return false;
        }

        return true;
    }

    public function reject(string $type, int $id, ?int $requestedBy, User $user, string $note): void
    {
        $step = $this->current($type, $id);
        if (! $step) {
            $this->assertMay($user, self::SUBJECTS[$type][1], $type, $requestedBy, null);

            return;
        }
        $behalf = $this->assertMay($user, $step->permission, $type, $requestedBy, $step);
        DB::table('wms_approval_steps')->where('id', $step->id)->update(['status' => 'REJECTED', 'decided_by' => $user->id, 'on_behalf_of' => $behalf,
            'decided_at' => now(), 'note' => $note, 'updated_at' => now()]);
        app(WarehouseAudit::class)->record((int) $step->company_id, 'approval.rejected', $type, $step->subject_ref, "Level {$step->level} ({$step->name}) rejected: {$note}", null, null, $user);
        $this->close($type, $id);
    }

    private function noteOverride(string $type, string $ref): void
    {
        if ($this->override) {
            app(WarehouseOverrides::class)->used($this->override, $type, $ref);
        }
    }

    /** A withdrawn request leaves nothing waiting. */
    public function close(string $type, int $id): void
    {
        DB::table('wms_approval_steps')->where('subject_type', $type)->where('subject_id', $id)->where('status', 'PENDING')
            ->update(['status' => 'SKIPPED', 'updated_at' => now()]);
    }

    public function current(string $type, int $id): ?object
    {
        return DB::table('wms_approval_steps')->where('subject_type', $type)->where('subject_id', $id)->where('status', 'PENDING')->orderBy('level')->first();
    }

    /** Whether this person may decide the request's current level -- for screens and controllers. */
    public function mayDecide(User $user, string $type, int $id, ?int $requestedBy): bool
    {
        try {
            $step = $this->current($type, $id);
            $this->assertMay($user, $step->permission ?? self::SUBJECTS[$type][1], $type, $requestedBy, $step);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @return int|null on whose behalf the decision is made (null: in their own right) */
    private function assertMay(User $user, string $permission, string $type, ?int $requestedBy, ?object $step): ?int
    {
        $this->override = null;
        if ($requestedBy !== null && $requestedBy === (int) $user->id) {
            // Only an open emergency override lets somebody decide their own request (WMS-201).
            $this->override = app(WarehouseOverrides::class)->active($user, $type);
            if (! $this->override) {
                throw new RuntimeException('A request is decided by somebody other than the person who made it.');
            }
        }
        if ($step && DB::table('wms_approval_steps')->where('subject_type', $step->subject_type)->where('subject_id', $step->subject_id)
            ->where('decided_by', $user->id)->where('status', 'APPROVED')->exists()) {
            throw new RuntimeException('Each level is decided by a different person; you have already approved an earlier level.');
        }
        if ($user->can($permission)) {
            return null;
        }
        foreach ($this->delegationsTo($user, $type) as $d) {
            $from = User::query()->withoutGlobalScopes()->find($d->from_user_id);
            if ($from && $from->can($permission) && (int) $d->from_user_id !== $requestedBy) {
                return (int) $d->from_user_id;
            }
        }
        throw new RuntimeException('You may not decide this level, in your own right or as a delegate.');
    }

    private function delegationsTo(User $user, string $type): \Illuminate\Support\Collection
    {
        return DB::table('wms_delegations')->where('to_user_id', $user->id)->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())->get()
            ->filter(fn ($d) => $d->subject_types === null || in_array($type, json_decode($d->subject_types, true) ?? [], true))->values();
    }

    /** @return list<int> who may decide this step: holders of its permission and their active delegates */
    public function deciders(int $companyId, object $step): array
    {
        $holders = app(WarehouseNotifier::class)->holders($companyId, $step->permission);
        $delegates = DB::table('wms_delegations')->where('company_id', $companyId)->whereIn('from_user_id', $holders)->whereNull('revoked_at')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->get()
            ->filter(fn ($d) => $d->subject_types === null || in_array($step->subject_type, json_decode($d->subject_types, true) ?? [], true))->pluck('to_user_id')->all();
        $decided = DB::table('wms_approval_steps')->where('subject_type', $step->subject_type)->where('subject_id', $step->subject_id)->whereNotNull('decided_by')->pluck('decided_by')->all();

        return array_values(array_diff(array_unique(array_map('intval', array_merge($holders, $delegates))), array_map('intval', $decided)));
    }

    /** Requests waiting on this person, now. */
    public function inbox(User $user, int $companyId): array
    {
        return DB::table('wms_approval_steps')->where('company_id', $companyId)->where('status', 'PENDING')->orderBy('due_at')->limit(300)->get()
            ->filter(fn ($s) => ($this->current($s->subject_type, (int) $s->subject_id)?->id === $s->id)
                && $this->mayDecide($user, $s->subject_type, (int) $s->subject_id, $s->requested_by ? (int) $s->requested_by : null))
            ->map(fn ($s) => ['id' => $s->id, 'type' => $s->subject_type, 'subject_id' => (int) $s->subject_id, 'ref' => $s->subject_ref, 'level' => (int) $s->level,
                'name' => $s->name, 'value' => (float) $s->value, 'due_at' => Carbon::parse($s->due_at)->toIso8601String(), 'overdue' => Carbon::parse($s->due_at)->isPast(),
                'escalated' => $s->escalated_at !== null,
                'levels' => DB::table('wms_approval_steps')->where('subject_type', $s->subject_type)->where('subject_id', $s->subject_id)->orderBy('level')
                    ->get(['level', 'name', 'status'])->map(fn ($l) => (array) $l)->all()])->values()->all();
    }

    public function delegate(int $companyId, int $fromUserId, int $toUserId, ?array $types, Carbon $starts, Carbon $ends, string $reason, User $by): int
    {
        if ($fromUserId === $toUserId) {
            throw new RuntimeException('Delegate to somebody else.');
        }
        if ($fromUserId !== (int) $by->id && ! $by->can(RoleCatalog::MANAGE_WAREHOUSES)) {
            throw new RuntimeException('You may delegate only your own authority.');
        }
        if ($ends->lte($starts)) {
            throw new RuntimeException('A delegation ends after it starts.');
        }
        if (blank($reason)) {
            throw new RuntimeException('Say why the authority is delegated.');
        }
        foreach ([$fromUserId, $toUserId] as $u) {
            if (! User::query()->withoutGlobalScopes()->where('company_id', $companyId)->whereKey($u)->exists()) {
                throw new RuntimeException('Both people must belong to this company.');
            }
        }

        app(WarehouseAudit::class)->record($companyId, 'delegation.created', 'DELEGATION', "{$fromUserId}->{$toUserId}", "Authority delegated: {$reason}", null,
            ['from' => $fromUserId, 'to' => $toUserId, 'types' => $types, 'starts_at' => $starts->toIso8601String(), 'ends_at' => $ends->toIso8601String()], $by);

        return DB::table('wms_delegations')->insertGetId(['company_id' => $companyId, 'from_user_id' => $fromUserId, 'to_user_id' => $toUserId,
            'subject_types' => $types ? json_encode(array_values(array_intersect($types, array_keys(self::SUBJECTS)))) : null,
            'starts_at' => $starts, 'ends_at' => $ends, 'reason' => $reason, 'created_by' => $by->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Levels past their deadline: stamped once and raised to the policy's escalation recipients. */
    public function escalateOverdue(int $companyId): int
    {
        $n = 0;
        foreach (DB::table('wms_approval_steps')->where('company_id', $companyId)->where('status', 'PENDING')->whereNull('escalated_at')->where('due_at', '<', now())->get() as $s) {
            if ($this->current($s->subject_type, (int) $s->subject_id)?->id !== $s->id) {
                continue;
            }
            DB::table('wms_approval_steps')->where('id', $s->id)->update(['escalated_at' => now(), 'updated_at' => now()]);
            $to = $this->policy($companyId, $s->subject_type)?->escalate_to_permissions ?? [];
            $people = collect($to)->flatMap(fn ($p) => app(WarehouseNotifier::class)->holders($companyId, $p))->unique()->reject(fn ($id) => (int) $id === (int) $s->requested_by)->all();
            if ($people) {
                app(WarehouseNotifier::class)->raise($companyId, 'APPROVAL', [['key' => "APPROVAL:{$s->subject_type}:{$s->subject_id}:{$s->level}:ESCALATED", 'warehouse_id' => null,
                    'recipients' => array_fill_keys($people, 'ESCALATION'), 'title' => self::SUBJECTS[$s->subject_type][0]." {$s->subject_ref} is overdue at {$s->name}",
                    'message' => 'It was due '.Carbon::parse($s->due_at)->toDayDateTimeString().' and nobody has decided it.', 'url' => '/warehouse/approvals']]);
            }
            $n++;
        }

        return $n;
    }
}
