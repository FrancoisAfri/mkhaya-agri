<?php

namespace App\Modules\Operations\ProjectManagement\Http\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The field bundle: everything a phone needs to work for a day with no signal (EPIC-060).
 *
 * One request, because the loop that matters happens in a yard with one bar of reception before the
 * bakkie leaves. Six round trips to assemble a day's work is six chances to fail halfway and leave
 * someone on site with a partial copy.
 *
 * `?since=` makes it a delta. The first sync of a project is large; every one after it carries only
 * what moved, which is what makes syncing on the way out of the yard realistic.
 */
class SyncApiController extends Controller
{
    /** Evidence older than this is history; a phone does not need it to record today's work. */
    private const EVIDENCE_DAYS = 30;

    /** A hard ceiling per collection, so one enormous project cannot produce an unusable response. */
    private const MAX_ROWS = 2000;

    public function __construct(private readonly PermissionEvaluator $evaluator)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $since = $request->query('since') ? CarbonImmutable::parse($request->query('since')) : null;

        $projects = $this->projectsFor($user);
        $projectIds = $projects->pluck('id');

        // Assigned work first: on a large job, what this person is on is the part that has to fit.
        $taskIds = TaskAssignment::whereIn('project_id', $projectIds)
            ->whereIn('member_id', ProjectMember::whereIn('project_id', $projectIds)
                ->where('user_id', $user->id)->pluck('id'))
            ->pluck('task_id');

        $tasks = Task::whereIn('project_id', $projectIds)
            ->real()
            ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
            // Assigned tasks, then the rest of the project — the phone gets the important half even
            // if it stops reading at the limit.
            ->orderByRaw('CASE WHEN id IN (' . ($taskIds->isEmpty() ? '0' : $taskIds->implode(',')) . ') THEN 0 ELSE 1 END')
            ->orderBy('planned_start')
            ->limit(self::MAX_ROWS)
            ->get();

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'since'     => $since?->toIso8601String(),
            'projects'  => $projects->map(fn (Project $p) => [
                'id'       => $p->uuid,
                'code'     => $p->code,
                'name'     => $p->name,
                'status'   => $p->status,
                'timezone' => $p->timezone,
                'currency' => $p->currency,
            ])->values(),
            'tasks' => $tasks->map(fn (Task $t) => [
                'id'            => $t->uuid,
                'project_id'    => $projects->firstWhere('id', $t->project_id)?->uuid,
                'code'          => $t->code,
                'name'          => $t->name,
                'status'        => $t->status,
                'priority'      => $t->priority,
                'planned_start' => $t->planned_start?->toIso8601String(),
                'planned_end'   => $t->planned_end?->toIso8601String(),
                'progress_pct'  => (float) $t->progress_pct,
                'is_milestone'  => (bool) $t->is_milestone,
                'is_assigned'   => $taskIds->contains($t->id),
                'planned_quantity' => $t->planned_quantity === null ? null : (float) $t->planned_quantity,
                'quantity_unit'    => $t->quantity_unit,
                'updated_at'    => $t->updated_at?->toIso8601String(),
            ])->values(),
            'checklists' => ChecklistItem::whereIn('task_id', $tasks->pluck('id'))
                ->when($since, fn ($q) => $q->where('updated_at', '>', $since))
                ->limit(self::MAX_ROWS)
                ->get()
                ->map(fn (ChecklistItem $i) => [
                    'id'      => $i->uuid,
                    'task_id' => $tasks->firstWhere('id', $i->task_id)?->uuid,
                    'text'    => $i->text,
                    'is_done' => (bool) $i->is_done,
                ])->values(),
            'evidence' => $this->recentEvidence($projectIds, $since, $projects),
            // The vocabulary travels with the bundle so a device can validate before spending a bad
            // connection on a round trip that will be rejected.
            'taxonomy' => [
                'version' => EvidenceTaxonomy::VERSION,
                'types'   => EvidenceTaxonomy::types(),
            ],
            'limits' => [
                'evidence_days' => self::EVIDENCE_DAYS,
                'max_rows'      => self::MAX_ROWS,
                // Truthful about truncation: a client that silently received 2,000 of 5,000 tasks
                // would show a foreman a list missing the work they came for.
                'truncated'     => $tasks->count() >= self::MAX_ROWS,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int, Project> */
    private function projectsFor($user): \Illuminate\Support\Collection
    {
        $visible = $this->evaluator->visibleProjectIds($user);

        return Project::query()
            ->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->orderBy('code')
            ->limit(200)
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     * @return \Illuminate\Support\Collection<int, array<string,mixed>>
     */
    private function recentEvidence($projectIds, ?CarbonImmutable $since, $projects): \Illuminate\Support\Collection
    {
        $floor = $since ?? CarbonImmutable::now()->subDays(self::EVIDENCE_DAYS);

        return EvidenceEvent::whereIn('project_id', $projectIds)
            ->where('occurred_at', '>=', $floor)
            ->orderByDesc('occurred_at')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (EvidenceEvent $e) => [
                'id'           => $e->uuid,
                'project_id'   => $projects->firstWhere('id', $e->project_id)?->uuid,
                'subject_type' => $e->subject_type,
                'subject_id'   => $e->subject_uuid,
                'event_type'   => $e->event_type,
                'occurred_at'  => $e->occurred_at?->toIso8601String(),
                'quantity'     => $e->quantity === null ? null : (float) $e->quantity,
                'unit'         => $e->unit,
                'payload'      => $e->payload,
                'superseded'   => $e->superseded_by !== null,
            ])
            ->values();
    }
}
