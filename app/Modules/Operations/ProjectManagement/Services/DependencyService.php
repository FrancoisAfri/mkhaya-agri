<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskDependency;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Precedence links between tasks (PM-1123) — the input the critical-path pass walks.
 *
 * The whole value here is cycle detection. A cycle is not merely invalid data: the forward pass
 * would never terminate, so the engine either hangs or reports nonsense. It is rejected at the
 * point of creation, and the error **names the path** — "Pour slab → Strike formwork → Pour slab" —
 * because "circular dependency detected" tells a site engineer nothing they can act on (Law 6).
 */
class DependencyService
{
    public function __construct(private readonly Outbox $outbox)
    {
    }

    /**
     * Link two tasks.
     *
     * @param  string  $type  fs | ss | ff | sf
     * @param  int  $lagMinutes  negative is a lead ("start two days before it finishes")
     */
    public function link(Task $predecessor, Task $successor, string $type = 'fs', int $lagMinutes = 0): TaskDependency
    {
        if (! in_array($type, TaskDependency::TYPES, true)) {
            throw new InvalidArgumentException("'{$type}' is not a dependency type; use fs, ss, ff or sf.");
        }

        if ($predecessor->project_id !== $successor->project_id) {
            throw new InvalidArgumentException('Tasks in different projects cannot depend on each other.');
        }

        if ($predecessor->id === $successor->id) {
            throw new InvalidArgumentException("'{$predecessor->name}' cannot depend on itself.");
        }

        if ($path = $this->cycleThrough($predecessor, $successor)) {
            throw new InvalidArgumentException(
                'That link would create a loop: ' . implode(' → ', $path) . '.'
            );
        }

        return DB::transaction(function () use ($predecessor, $successor, $type, $lagMinutes) {
            $dependency = TaskDependency::create([
                'project_id'     => $predecessor->project_id,
                'predecessor_id' => $predecessor->id,
                'successor_id'   => $successor->id,
                'dep_type'       => $type,
                'lag_minutes'    => $lagMinutes,
            ]);

            $this->outbox->publish('pm.dependency.created', 'task', $successor->uuid, [
                'predecessor' => $predecessor->uuid,
                'successor'   => $successor->uuid,
                'type'        => $type,
                'lag_minutes' => $lagMinutes,
            ]);

            return $dependency;
        });
    }

    public function unlink(TaskDependency $dependency): void
    {
        DB::transaction(function () use ($dependency) {
            $dependency->delete();

            $this->outbox->publish('pm.dependency.removed', 'task', $dependency->successor->uuid, [
                'predecessor' => $dependency->predecessor->uuid,
                'successor'   => $dependency->successor->uuid,
            ]);
        });
    }

    /**
     * The whole precedence graph for a project, as the scheduling engine wants it: plain arrays,
     * no models, no lazy loads (PM-ADR-005 — the engine touches no database).
     *
     * @return array{tasks: array<int,array<string,mixed>>, links: list<array<string,mixed>>}
     */
    public function graph(Project $project): array
    {
        $tasks = Task::where('project_id', $project->id)
            ->get(['id', 'uuid', 'name', 'duration_minutes', 'schedule_mode', 'constraint_type', 'constraint_date', 'calendar_id'])
            ->keyBy('id')
            ->map(fn (Task $t) => [
                'id'              => $t->id,
                'uuid'            => $t->uuid,
                'name'            => $t->name,
                'duration_minutes' => (int) $t->duration_minutes,
                'schedule_mode'   => $t->schedule_mode,
                'constraint_type' => $t->constraint_type,
                'constraint_date' => $t->constraint_date?->toDateString(),
                'calendar_id'     => $t->calendar_id,
            ])
            ->all();

        $links = TaskDependency::where('project_id', $project->id)
            ->get(['predecessor_id', 'successor_id', 'dep_type', 'lag_minutes'])
            ->map(fn (TaskDependency $d) => [
                'predecessor_id' => $d->predecessor_id,
                'successor_id'   => $d->successor_id,
                'type'           => $d->dep_type,
                'lag_minutes'    => (int) $d->lag_minutes,
            ])
            ->all();

        return ['tasks' => $tasks, 'links' => $links];
    }

    /**
     * Would linking predecessor → successor close a loop, and if so, through what?
     *
     * Walks forward from the successor looking for the predecessor. Loading the project's edges
     * once and walking in memory keeps this a single query however deep the chain runs — a
     * per-hop query would make a long chain of links unusably slow to validate.
     *
     * @return list<string>|null  the task names forming the loop, or null if there is none
     */
    private function cycleThrough(Task $predecessor, Task $successor): ?array
    {
        $edges = TaskDependency::where('project_id', $predecessor->project_id)
            ->get(['predecessor_id', 'successor_id'])
            ->groupBy('predecessor_id')
            ->map(fn ($group) => $group->pluck('successor_id')->all())
            ->all();

        $stack = [[$successor->id, [$successor->id]]];
        $seen = [];

        while ($stack !== []) {
            [$current, $path] = array_pop($stack);

            if ($current === $predecessor->id) {
                // The loop the link would close: successor … predecessor, then back to successor.
                return $this->nameThe(array_merge($path, [$successor->id]));
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($edges[$current] ?? [] as $next) {
                $stack[] = [$next, array_merge($path, [$next])];
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function nameThe(array $ids): array
    {
        $names = Task::whereIn('id', array_unique($ids))->pluck('name', 'id');

        return array_map(fn (int $id) => $names[$id] ?? "#{$id}", $ids);
    }
}
