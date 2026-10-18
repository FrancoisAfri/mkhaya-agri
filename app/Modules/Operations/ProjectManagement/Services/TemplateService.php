<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectTemplate;
use App\Modules\Operations\ProjectManagement\Models\StageGate;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creating a project from a template, and turning a project back into one (§3).
 *
 * ── Why dates are offsets, not dates ────────────────────────────────────────
 *
 * A template stores `start_offset_days` and `duration_days`, never a calendar date. A template
 * carrying real dates is correct on the day it is written and wrong every day after, and the
 * failure is quiet: somebody creates a job in March from a template written in January and every
 * task is two months in the past.
 *
 * ── Why the whole thing is one transaction ──────────────────────────────────
 *
 * A half-applied template is worse than none — a WBS with no tasks under it looks like a plan
 * somebody abandoned, and the natural response is to delete the project and start again, which
 * loses the code that has already been quoted.
 */
class TemplateService
{
    public function __construct(
        private readonly WbsService $wbs,
        private readonly TaskService $tasks,
        private readonly RegisterService $registers,
    ) {
    }

    /**
     * Lay a template's plan over a project that already exists.
     *
     * Applied *after* creation rather than during it, so the project's own code, client and dates
     * are settled first and the template never overwrites what somebody typed.
     *
     * @return array{wbs:int, tasks:int, gates:int, risks:int}
     */
    public function apply(Project $project, ProjectTemplate $template): array
    {
        return DB::transaction(function () use ($project, $template) {
            $counts = ['wbs' => 0, 'tasks' => 0, 'gates' => 0, 'risks' => 0];

            // The plan's anchor. Everything else is an offset from it, so a template used in March
            // produces March dates.
            $start = $project->planned_start ?? now()->startOfDay();

            // ── The breakdown structure, depth first ──────────────────────────
            $nodesByKey = [];

            foreach ($template->section('wbs') as $spec) {
                $parent = isset($spec['parent']) ? ($nodesByKey[$spec['parent']] ?? null) : null;

                $node = $this->wbs->create($project, [
                    'name'         => (string) $spec['name'],
                    'node_type'    => $spec['node_type'] ?? 'activity',
                    'is_milestone' => (bool) ($spec['is_milestone'] ?? false),
                    'created_by'   => Auth::id(),
                ], $parent);

                if (isset($spec['key'])) {
                    $nodesByKey[$spec['key']] = $node;
                }

                $counts['wbs']++;
            }

            // ── Tasks ─────────────────────────────────────────────────────────
            foreach ($template->section('tasks') as $spec) {
                $offset = (int) ($spec['start_offset_days'] ?? 0);
                $duration = max(1, (int) ($spec['duration_days'] ?? 1));

                $this->tasks->create($project, [
                    'name'             => (string) $spec['name'],
                    'description'      => $spec['description'] ?? null,
                    'planned_start'    => $start->copy()->addDays($offset)->toDateString(),
                    'planned_end'      => $start->copy()->addDays($offset + $duration - 1)->toDateString(),
                    'estimated_hours'  => $spec['estimated_hours'] ?? null,
                    'is_milestone'     => (bool) ($spec['is_milestone'] ?? false),
                    'priority'         => $spec['priority'] ?? 'medium',
                    'created_by'       => Auth::id(),
                ], null, isset($spec['wbs']) ? ($nodesByKey[$spec['wbs']] ?? null) : null);

                $counts['tasks']++;
            }

            // ── Stage gates ───────────────────────────────────────────────────
            foreach (array_values($template->section('gates')) as $i => $spec) {
                StageGate::create([
                    'project_id' => $project->id,
                    'stage'      => $spec['stage'] ?? 'initiation',
                    'name'       => (string) $spec['name'],
                    'criteria'   => $spec['criteria'] ?? null,
                    'position'   => $i + 1,
                ]);

                $counts['gates']++;
            }

            // ── Starter risks ─────────────────────────────────────────────────
            // The ones this kind of job always has. A register that starts empty is one somebody
            // fills in after the first thing goes wrong.
            foreach ($template->section('risks') as $spec) {
                $this->registers->createRisk($project, [
                    'title'             => (string) $spec['title'],
                    'description'       => $spec['description'] ?? null,
                    'category'          => $spec['category'] ?? 'other',
                    'probability'       => (int) ($spec['probability'] ?? 3),
                    'impact'            => (int) ($spec['impact'] ?? 3),
                    'response_strategy' => $spec['strategy'] ?? 'reduce',
                    'mitigation'        => $spec['mitigation'] ?? null,
                ]);

                $counts['risks']++;
            }

            $project->update(['template_id' => $template->id]);
            $template->increment('times_used');

            return $counts;
        });
    }

    /**
     * Turn a project that went well into a template.
     *
     * Dates are converted back to offsets from the project's start, which is the only form that
     * survives being reused. Anything without a planned start is skipped rather than defaulted to
     * day zero — a template that silently stacks half its tasks on the first morning is one people
     * abandon after using it once.
     */
    public function captureFrom(Project $project, array $meta): ProjectTemplate
    {
        return DB::transaction(function () use ($project, $meta) {
            $start = $project->planned_start ?? $project->created_at;

            $nodes = WbsNode::where('project_id', $project->id)->orderBy('id')->get();
            $byId = $nodes->keyBy('id');

            $wbs = $nodes->map(fn (WbsNode $n) => array_filter([
                'key'          => 'n' . $n->id,
                'parent'       => $n->parent_id ? 'n' . $n->parent_id : null,
                'name'         => $n->name,
                'node_type'    => $n->node_type,
                'is_milestone' => $n->is_milestone ? true : null,
            ], fn ($v) => $v !== null))->values()->all();

            $tasks = Task::where('project_id', $project->id)
                ->whereNotNull('planned_start')
                ->orderBy('planned_start')
                ->get()
                ->map(function (Task $t) use ($start, $byId) {
                    $offset = $start ? $start->diffInDays($t->planned_start, false) : 0;
                    $duration = $t->planned_end ? max(1, $t->planned_start->diffInDays($t->planned_end) + 1) : 1;

                    return array_filter([
                        'name'              => $t->name,
                        'description'       => $t->description,
                        'wbs'               => $t->wbs_node_id && $byId->has($t->wbs_node_id) ? 'n' . $t->wbs_node_id : null,
                        'start_offset_days' => (int) $offset,
                        'duration_days'     => (int) $duration,
                        'estimated_hours'   => $t->estimated_hours !== null ? (float) $t->estimated_hours : null,
                        'is_milestone'      => $t->is_milestone ? true : null,
                        'priority'          => $t->priority,
                    ], fn ($v) => $v !== null);
                })->values()->all();

            $gates = StageGate::where('project_id', $project->id)->orderBy('position')->get()
                ->map(fn (StageGate $g) => ['stage' => $g->stage, 'name' => $g->name, 'criteria' => $g->criteria])
                ->values()->all();

            return ProjectTemplate::create([
                'name'        => $meta['name'],
                'industry'    => $meta['industry'] ?? 'other',
                'sub_type'    => $meta['sub_type'] ?? null,
                'description' => $meta['description'] ?? null,
                'payload'     => ['wbs' => $wbs, 'tasks' => $tasks, 'gates' => $gates, 'risks' => []],
                'created_by'  => Auth::id(),
            ]);
        });
    }
}
