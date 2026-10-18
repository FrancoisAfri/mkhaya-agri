<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hazard reporting and triage (§5.2).
 *
 * The requirement that shapes this class is SHEQ-HAZ-3, which says plainly that silent rejection
 * kills reporting culture and is the number one reason these systems die. So a hazard cannot be
 * rejected without a reason, the reason is recorded against the record, and the reporter is marked
 * as owed a notification. The system is deliberately unable to dismiss a report quietly.
 *
 * Triage is timed (SHEQ-HAZ-2): a per-tenant SLA sets `triage_due_at` at report time, so overdue
 * triage is a property of the data rather than something a report has to recompute.
 */
class HazardService
{
    public function __construct(private readonly ActionService $actions,
        private readonly EventPublisher $events) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:int, uuid:string, reference:string}
     */
    public function report(int $companyId, array $input, ?int $reportedBy = null): array
    {
        return DB::transaction(function () use ($companyId, $input, $reportedBy) {
            if (! empty($input['client_id'])) {
                $existing = DB::table('sheq_hazards')
                    ->where('company_id', $companyId)->where('client_id', $input['client_id'])
                    ->first(['id', 'uuid', 'reference']);

                if ($existing) {
                    return ['id' => $existing->id, 'uuid' => $existing->uuid,
                        'reference' => $existing->reference, 'replayed' => true];
                }
            }

            $slaHours = (int) (DB::table('sheq_settings')->where('company_id', $companyId)
                ->value('hazard_triage_sla_hours') ?? 24);

            $isAnonymous = (bool) ($input['is_anonymous'] ?? false);
            $uuid = (string) Str::uuid();
            $reference = $this->nextReference($companyId);
            $reportedAt = Carbon::now();

            $id = DB::table('sheq_hazards')->insertGetId([
                'company_id' => $companyId,
                'uuid' => $uuid,
                'reference' => $reference,
                'client_id' => $input['client_id'] ?? null,
                'site_id' => $input['site_id'] ?? null,
                'description' => $input['description'],
                'hazard_category' => $input['hazard_category'] ?? null,
                'location_text' => $input['location_text'] ?? null,
                'location_lat' => $input['location_lat'] ?? null,
                'location_lng' => $input['location_lng'] ?? null,
                'severity_est' => $input['severity_est'] ?? null,
                'status' => 'OPEN',
                'reported_at' => $reportedAt,
                'triage_due_at' => $reportedAt->copy()->addHours($slaHours),
                // SHEQ-INC-3 applies here too: an anonymous report keeps no reporter.
                'reported_by' => $isAnonymous ? null : $reportedBy,
                'is_anonymous' => $isAnonymous,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $isAnonymous ? null : $reportedBy, 'hazard.reported', $id, null, [
                'reference' => $reference,
                'category' => $input['hazard_category'] ?? null,
                'triage_due_at' => $reportedAt->copy()->addHours($slaHours)->toIso8601String(),
            ]);

            $this->events->publish($companyId, 'sheq.hazard.reported', 'HAZARD', $id, [
                'hazard_id' => $id,
                'reference' => $reference,
                'site_id' => $input['site_id'] ?? null,
                'category' => $input['hazard_category'] ?? null,
                'severity_est' => $input['severity_est'] ?? null,
            ], ['actor_id' => $isAnonymous ? null : $reportedBy, 'anonymous' => $isAnonymous]);

            return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
        });
    }

    /**
     * Triage a hazard. Outcomes per SHEQ-HAZ-3: raise an action, mark it controlled, or reject with
     * a reason. Rejection without a reason is refused outright.
     *
     * @return array{action:?array}
     */
    public function triage(
        int $companyId, string $uuid, string $outcome, ?int $actorId, array $options = []
    ): array {
        $hazard = $this->find($companyId, $uuid);

        if ($outcome === 'REJECTED' && trim((string) ($options['reason'] ?? '')) === '') {
            throw new RuntimeException(
                'A hazard cannot be rejected without a reason (SHEQ-HAZ-3). The person who reported '
                .'it is told what happened to it — silent rejection is how reporting stops.'
            );
        }

        return DB::transaction(function () use ($hazard, $companyId, $outcome, $actorId, $options) {
            $action = null;

            if ($outcome === 'ACTION') {
                $action = $this->actions->create($companyId, [
                    'title' => $options['action_title'] ?? Str::limit($hazard->description, 80),
                    'description' => $hazard->description,
                    'source_type' => 'HAZARD',
                    'source_id' => $hazard->id,
                    'site_id' => $hazard->site_id,
                    'assigned_to' => $options['assigned_to'] ?? null,
                    'priority' => $options['priority'] ?? $this->priorityFor($hazard->severity_est),
                    'due_date' => $options['due_date'] ?? Carbon::now()->addDays(14)->toDateString(),
                    'hierarchy_level' => $options['hierarchy_level'] ?? null,
                ], $actorId);
            }

            $status = match ($outcome) {
                'ACTION' => 'TRIAGED',
                'CONTROLLED' => 'CONTROLLED',
                'REJECTED' => 'REJECTED',
                default => 'TRIAGED',
            };

            DB::table('sheq_hazards')->where('id', $hazard->id)->update([
                'status' => $status,
                'triaged_at' => now(),
                'triaged_by' => $actorId,
                'rejection_reason' => $outcome === 'REJECTED' ? $options['reason'] : null,
                'closed_at' => in_array($status, ['CONTROLLED', 'REJECTED'], true) ? now() : null,
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'hazard.triaged', $hazard->id,
                ['status' => $hazard->status],
                ['status' => $status, 'action' => $action['reference'] ?? null],
                $options['reason'] ?? null);

            return ['action' => $action];
        });
    }

    /**
     * A hazard whose severity turns out to be an actual event escalates to an incident, keeping the
     * link so the original report is not orphaned.
     */
    public function linkToIncident(int $companyId, string $uuid, int $incidentId, ?int $actorId): void
    {
        $hazard = $this->find($companyId, $uuid);

        DB::table('sheq_hazards')->where('id', $hazard->id)->update([
            'escalated_to_incident_id' => $incidentId,
            'status' => 'TRIAGED',
            'triaged_at' => now(),
            'triaged_by' => $actorId,
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'hazard.escalated', $hazard->id, null,
            ['incident_id' => $incidentId]);
    }

    /** A reporter's severity estimate seeds the action's priority; a practitioner can override it. */
    private function priorityFor(?int $severity): string
    {
        return match (true) {
            $severity === null => 'MEDIUM',
            $severity >= 5 => 'CRITICAL',
            $severity === 4 => 'HIGH',
            $severity === 3 => 'MEDIUM',
            default => 'LOW',
        };
    }

    private function find(int $companyId, string $uuid): object
    {
        $hazard = DB::table('sheq_hazards')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($hazard === null) {
            throw new RuntimeException('Hazard not found.');
        }

        return $hazard;
    }

    private function nextReference(int $companyId): string
    {
        $prefix = DB::table('sheq_settings')->where('company_id', $companyId)->value('hazard_prefix') ?? 'HAZ';
        $year = Carbon::now()->year;

        $last = DB::table('sheq_hazards')->where('company_id', $companyId)
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
            'subject_type' => 'Hazard',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
