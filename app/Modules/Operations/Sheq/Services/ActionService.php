<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The unified CAPA engine (§5.5).
 *
 * One action entity for every source — incident, hazard, inspection, audit, risk line — because
 * SHEQ-CAP-1 requires a single "My Actions" list and separate per-feature tables make that
 * impossible to assemble afterwards.
 *
 * The rules encoded here are the ones that get quietly dropped in most implementations, and each is
 * the difference between a CAPA system and a task list:
 *
 * - **Self-verification of critical work is blocked** (SHEQ-CAP-2). Marking your own critical action
 *   effective is how a corrective action becomes a formality.
 * - **Extensions are counted, reasoned and audited** (SHEQ-CAP-4). A due date that can be edited
 *   silently means "overdue" measures nothing.
 * - **A NOT_EFFECTIVE review spawns a successor** (SHEQ-CAP-3), linked to the original, rather than
 *   closing the loop on an action that did not work.
 */
class ActionService
{
    /** Priorities requiring independent verification (SHEQ-CAP-2). */
    private const INDEPENDENT_VERIFICATION = ['CRITICAL', 'HIGH'];

    public function __construct(private readonly EventPublisher $events) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:int, uuid:string, reference:string}
     */
    public function create(int $companyId, array $input, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $input, $actorId) {
            if (! empty($input['client_id'])) {
                $existing = DB::table('sheq_actions')
                    ->where('company_id', $companyId)->where('client_id', $input['client_id'])
                    ->first(['id', 'uuid', 'reference']);

                if ($existing) {
                    return ['id' => $existing->id, 'uuid' => $existing->uuid,
                        'reference' => $existing->reference, 'replayed' => true];
                }
            }

            $uuid = (string) Str::uuid();
            $reference = $this->nextReference($companyId);
            $dueDate = $input['due_date'] ?? Carbon::now()->addDays(30)->toDateString();

            $id = DB::table('sheq_actions')->insertGetId([
                'company_id' => $companyId,
                'uuid' => $uuid,
                'reference' => $reference,
                'client_id' => $input['client_id'] ?? null,
                'action_type' => $input['action_type'] ?? 'CORRECTIVE',
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'source_type' => $input['source_type'] ?? 'MANUAL',
                'source_id' => $input['source_id'] ?? null,
                'hierarchy_level' => $input['hierarchy_level'] ?? null,
                'site_id' => $input['site_id'] ?? null,
                'assigned_to' => $input['assigned_to'] ?? null,
                /*
                 * The contractor who owes it, where one does (CON-18).
                 *
                 * Separate from `assigned_to`, which is a user id — a contractor's
                 * administrator has no account here — and separate from `source_type`, which
                 * says what prompted the action rather than who must do it. An inspection of a
                 * contractor's scaffolding is sourced to the inspection and owed by the
                 * contractor, and conflating the two makes either question unanswerable.
                 *
                 * This is what makes an action visible on that contractor's own portal.
                 */
                'contractor_id' => $input['contractor_id'] ?? null,
                'priority' => $input['priority'] ?? 'MEDIUM',
                'due_date' => $dueDate,
                'original_due_date' => $dueDate,
                'status' => 'OPEN',
                'evidence_required' => $input['evidence_required'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'action.created', $id, null, [
                'reference' => $reference, 'title' => $input['title'],
                'source' => ($input['source_type'] ?? 'MANUAL').':'.($input['source_id'] ?? '-'),
                'priority' => $input['priority'] ?? 'MEDIUM', 'due_date' => $dueDate,
            ]);

            $this->events->publish($companyId, 'sheq.action.created', 'ACTION', $id, [
                'action_id' => $id,
                'reference' => $reference,
                'title' => $input['title'],
                'due_date' => $input['due_date'] ?? null,
                'priority' => $input['priority'] ?? null,
                'source_type' => $input['source_type'] ?? null,
            ], ['actor_id' => $actorId]);

            // An action nobody owns is an action nobody does, so the assignment is announced
            // separately — that is what an LMS or a task inbox subscribes to.
            if (! empty($input['assigned_to'])) {
                $this->events->publish($companyId, 'sheq.action.assigned', 'ACTION', $id, [
                    'action_id' => $id,
                    'assigned_to' => (int) $input['assigned_to'],
                ], ['actor_id' => $actorId]);
            }

            return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
        });
    }

    /** The assignee reports the work done; it is not closed until someone verifies it. */
    public function complete(int $companyId, string $uuid, ?int $actorId, ?string $notes = null): void
    {
        $action = $this->find($companyId, $uuid);

        DB::transaction(function () use ($action, $companyId, $actorId, $notes) {
            DB::table('sheq_actions')->where('id', $action->id)->update([
                'status' => 'PENDING_VERIFICATION',
                'completed_at' => now(),
                'completion_notes' => $notes,
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'action.completed', $action->id,
                ['status' => $action->status], ['status' => 'PENDING_VERIFICATION', 'notes' => $notes]);
        });
    }

    /**
     * Verify and close. Refuses self-verification on critical and high priority work.
     *
     * @throws RuntimeException
     */
    public function verify(int $companyId, string $uuid, ?int $actorId, ?string $notes = null): void
    {
        $action = $this->find($companyId, $uuid);

        if (in_array($action->priority, self::INDEPENDENT_VERIFICATION, true)
            && $actorId !== null
            && (int) $action->assigned_to === $actorId) {
            throw new RuntimeException(
                "A {$action->priority} action must be verified by someone other than the person who "
                .'completed it (SHEQ-CAP-2). Ask another practitioner to verify this one.'
            );
        }

        DB::transaction(function () use ($action, $companyId, $actorId, $notes) {
            $reviewDue = DB::table('sheq_settings')->where('company_id', $companyId)
                ->value('capa_effectiveness_review_days') ?? 90;

            DB::table('sheq_actions')->where('id', $action->id)->update([
                'status' => 'VERIFIED_CLOSED',
                'verified_at' => now(),
                'verified_by' => $actorId,
                'verification_notes' => $notes,
                'effectiveness_review_due' => Carbon::now()->addDays((int) $reviewDue)->toDateString(),
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'action.verified', $action->id,
                ['status' => $action->status], ['status' => 'VERIFIED_CLOSED', 'notes' => $notes]);

            $this->events->publish($companyId, 'sheq.action.closed', 'ACTION', (int) $action->id, [
                'action_id' => (int) $action->id,
                'reference' => $action->reference ?? null,
                'closed_at' => Timestamps::utc(Carbon::now())->toIso8601String(),
            ], ['actor_id' => $actorId]);
        });
    }

    /**
     * Extend a due date. Explicit, reasoned, counted and audited (SHEQ-CAP-4) — the alternative is
     * a system where every action is delivered "on time" because the target moved.
     */
    public function extend(int $companyId, string $uuid, string $newDueDate, string $reason, ?int $actorId): void
    {
        $action = $this->find($companyId, $uuid);

        if (trim($reason) === '') {
            throw new RuntimeException('An extension needs a reason (SHEQ-CAP-4).');
        }

        DB::transaction(function () use ($action, $companyId, $newDueDate, $reason, $actorId) {
            DB::table('sheq_actions')->where('id', $action->id)->update([
                'due_date' => $newDueDate,
                'extension_count' => $action->extension_count + 1,
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'action.extended', $action->id,
                ['due_date' => $action->due_date],
                ['due_date' => $newDueDate, 'extension_count' => $action->extension_count + 1],
                $reason);
        });
    }

    /**
     * Record an effectiveness review. NOT_EFFECTIVE spawns a successor linked to the original
     * (SHEQ-CAP-3) instead of leaving a closed action that demonstrably did not work.
     */
    public function reviewEffectiveness(
        int $companyId, string $uuid, string $result, ?int $actorId, ?string $notes = null
    ): ?array {
        $action = $this->find($companyId, $uuid);

        return DB::transaction(function () use ($action, $companyId, $result, $actorId, $notes) {
            DB::table('sheq_actions')->where('id', $action->id)->update([
                'effectiveness_result' => $result,
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'action.effectiveness_reviewed', $action->id, null,
                ['result' => $result, 'notes' => $notes]);

            if ($result !== 'NOT_EFFECTIVE') {
                return null;
            }

            $successor = $this->create($companyId, [
                'action_type' => 'CORRECTIVE',
                'title' => 'Follow-up: '.$action->title,
                'description' => "The original action {$action->reference} was reviewed as not effective."
                    .($notes ? ' Reviewer notes: '.$notes : ''),
                'source_type' => $action->source_type,
                'source_id' => $action->source_id,
                'site_id' => $action->site_id,
                'assigned_to' => $action->assigned_to,
                'priority' => $action->priority,
                'due_date' => Carbon::now()->addDays(30)->toDateString(),
            ], $actorId);

            DB::table('sheq_actions')->where('id', $successor['id'])
                ->update(['supersedes_action_id' => $action->id]);

            return $successor;
        });
    }

    private function find(int $companyId, string $uuid): object
    {
        $action = DB::table('sheq_actions')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($action === null) {
            throw new RuntimeException('Action not found.');
        }

        return $action;
    }

    private function nextReference(int $companyId): string
    {
        $prefix = DB::table('sheq_settings')->where('company_id', $companyId)->value('action_prefix') ?? 'ACT';
        $year = Carbon::now()->year;

        $last = DB::table('sheq_actions')->where('company_id', $companyId)
            ->where('reference', 'like', "{$prefix}-{$year}-%")->orderByDesc('reference')->value('reference');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }

    private function audit(
        int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before = null, ?array $after = null, ?string $reason = null
    ): void {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Action',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
