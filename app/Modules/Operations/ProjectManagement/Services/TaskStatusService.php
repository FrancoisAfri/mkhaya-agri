<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The steps a project's work moves through (T-0008).
 *
 * Every project had the same five columns. This lets the people running one name their own —
 * "awaiting materials", "ready for inspection", "with the engineer" — without any of it reaching
 * the parts of the module that depend on what a status *means*.
 *
 * That separation is the whole design. A step carries a category drawn from the five built-in
 * states, and the evidence ledger, progress derivation, open-work counts, claims and the scheduler
 * all keep reading the category. A project whose steps were free-form labels would emit no
 * completion events and roll up no progress, and nothing would say so.
 */
class TaskStatusService
{
    /** Enough for a board that still fits on a screen; beyond it, the columns are the problem. */
    public const MAX_STEPS = 12;

    /**
     * The steps this project's board draws, in order.
     *
     * Falls back to the built-in five for a project that has defined none, which is every project
     * until somebody chooses otherwise — so this feature is invisible until it is wanted.
     *
     * @return list<array{key: string, label: string, category: string, is_default: bool, colour: ?string, id: ?string, custom: bool}>
     */
    public function stepsFor(Project $project): array
    {
        $own = TaskStatus::where('project_id', $project->id)->active()->ordered()->get();

        if ($own->isEmpty()) {
            return array_map(
                fn (array $s) => $s + ['id' => null, 'custom' => false],
                TaskStatus::builtIn(),
            );
        }

        return $own->map(fn (TaskStatus $s) => [
            'id'         => $s->uuid,
            'key'        => $s->key,
            'label'      => $s->label,
            'category'   => $s->category,
            'is_default' => $s->is_default,
            'colour'     => $s->colour,
            'custom'     => true,
        ])->all();
    }

    /**
     * What a task's status column means, and which column it sits in.
     *
     * Given whichever of the two the caller has, returns both. The board sends a step key; older
     * clients, the field app and the API send a plain status — and both have to keep working, so
     * neither is required to know about the other.
     *
     * @return array{status: string, status_key: ?string}
     */
    public function resolve(Project $project, string $keyOrStatus): array
    {
        foreach ($this->stepsFor($project) as $step) {
            if ($step['key'] === $keyOrStatus) {
                // A built-in step's key *is* the status, so status_key stays null for it — there is
                // no row to point at, and storing the name of a column that does not exist would
                // strand the task the moment somebody did define steps.
                return [
                    'status'     => $step['category'],
                    'status_key' => $step['custom'] ? $step['key'] : null,
                ];
            }
        }

        /*
         * Not one of this project's steps. If it is nonetheless a real built-in status, honour it:
         * the field app, the API and every existing integration send those, and refusing them would
         * break clients that have no idea this feature exists.
         */
        if (in_array($keyOrStatus, Task::STATUSES, true)) {
            return ['status' => $keyOrStatus, 'status_key' => null];
        }

        throw new InvalidArgumentException(
            __('project_management.steps.unknown_step', ['step' => $keyOrStatus])
        );
    }

    /**
     * Where a task lands when nobody said.
     *
     * A project that has named its own columns almost never wants the first one to be "Not
     * started" — a snagging board starts at "Raised", a design board at "Briefed" — and a new task
     * appearing in a column the team does not use is a task nobody sees.
     *
     * @return array{status: string, status_key: ?string}
     */
    public function defaultFor(Project $project): array
    {
        foreach ($this->stepsFor($project) as $step) {
            if ($step['is_default']) {
                return [
                    'status'     => $step['category'],
                    'status_key' => $step['custom'] ? $step['key'] : null,
                ];
            }
        }

        return ['status' => 'not_started', 'status_key' => null];
    }

    /** Which column a card should be drawn in — its step if it has one, else its plain status. */
    public function columnOf(Task $task): string
    {
        return $task->status_key ?: $task->status;
    }

    /**
     * Give a project its own steps, replacing whatever it had.
     *
     * Sent whole rather than one at a time because the order and the single default are properties
     * of the set: applying a reorder as five separate edits would leave the board briefly showing
     * two defaults, or none.
     *
     * @param  list<array{id?: ?string, label: string, category: string, is_default?: bool, colour?: ?string}>  $steps
     */
    public function replace(Project $project, array $steps): void
    {
        $steps = array_values($steps);

        if ($steps === []) {
            throw new InvalidArgumentException(__('project_management.steps.at_least_one'));
        }

        if (count($steps) > self::MAX_STEPS) {
            throw new InvalidArgumentException(
                __('project_management.steps.too_many', ['max' => self::MAX_STEPS])
            );
        }

        foreach ($steps as $step) {
            if (! in_array($step['category'] ?? null, Task::BOARD_STATUSES, true)) {
                throw new InvalidArgumentException(
                    __('project_management.steps.bad_category', ['label' => $step['label'] ?? '?'])
                );
            }
        }

        /*
         * Somewhere to finish and somewhere to give up. Without a done step the board has no column
         * to drag a card into to complete it, and completion is what drives claims, progress and
         * the evidence ledger — a project could be worked for months and record nothing.
         */
        $categories = array_column($steps, 'category');

        if (! in_array('done', $categories, true)) {
            throw new InvalidArgumentException(__('project_management.steps.needs_done'));
        }

        DB::transaction(function () use ($project, $steps) {
            $existing = TaskStatus::where('project_id', $project->id)->get()->keyBy('uuid');
            $kept = [];
            $usedKeys = [];

            foreach ($steps as $position => $step) {
                $current = ! empty($step['id']) ? $existing->get($step['id']) : null;

                // A rename keeps its key. Tasks reference the key, so regenerating it on every save
                // would strand every card in the column each time somebody fixed a typo.
                $key = $current?->key ?? $this->uniqueKey($step['label'], $usedKeys);
                $usedKeys[] = $key;

                $attributes = [
                    'project_id' => $project->id,
                    'key'        => $key,
                    'label'      => trim($step['label']),
                    'category'   => $step['category'],
                    'position'   => $position,
                    // Exactly one, and the first if nobody said. A board where a new task lands
                    // nowhere in particular is worse than one with an opinion.
                    'is_default' => false,
                    'is_active'  => true,
                    'colour'     => $step['colour'] ?? null,
                ];

                if ($current) {
                    $current->forceFill($attributes)->save();
                    $kept[] = $current->id;
                } else {
                    $kept[] = TaskStatus::create($attributes + [
                        'uuid'       => (string) Str::uuid(),
                        'created_by' => Auth::id(),
                    ])->id;
                }
            }

            $defaultIndex = $this->defaultIndex($steps);

            // Cleared first, then set: the partial unique index refuses two defaults, and updating
            // in place would trip it halfway through.
            TaskStatus::where('project_id', $project->id)->update(['is_default' => false]);
            TaskStatus::whereKey($kept[$defaultIndex])->update(['is_default' => true]);

            $this->retire($project, $kept);
        });
    }

    /** @param list<array<string,mixed>> $steps */
    private function defaultIndex(array $steps): int
    {
        foreach ($steps as $i => $step) {
            if (! empty($step['is_default'])) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Remove the steps that are no longer in the set, moving anything still sitting in them.
     *
     * Deleting a column out from under live tasks would make them vanish from the board while
     * remaining perfectly real in the database — the worst kind of missing work, because nothing
     * reports it. They move to the default step instead, keeping the meaning they already had where
     * that is still one of the categories on offer.
     *
     * @param  list<int>  $kept
     */
    private function retire(Project $project, array $kept): void
    {
        $dropped = TaskStatus::where('project_id', $project->id)
            ->whereNotIn('id', $kept)
            ->get();

        if ($dropped->isEmpty()) {
            return;
        }

        $default = TaskStatus::where('project_id', $project->id)->where('is_default', true)->first();

        foreach ($dropped as $step) {
            Task::where('project_id', $project->id)
                ->where('status_key', $step->key)
                ->update([
                    'status_key' => $default?->key,
                    // The task's meaning follows the column it has been moved into, because that is
                    // now the only column it is in. Anything else would show a card in "Not
                    // started" that the ledger still counts as done.
                    'status'     => $default?->category ?? 'not_started',
                ]);

            $step->delete();
        }
    }

    /** @param list<string> $taken */
    private function uniqueKey(string $label, array $taken): string
    {
        $base = TaskStatus::keyFor($label);
        $key = $base;
        $n = 2;

        while (in_array($key, $taken, true)) {
            $key = Str::limit($base, 36, '') . '_' . $n++;
        }

        return $key;
    }

    /**
     * How many tasks sit in each step — so the settings screen can say what a removal would move.
     *
     * @return array<string, int>
     */
    public function counts(Project $project): array
    {
        return Task::where('project_id', $project->id)
            ->real()
            ->selectRaw('coalesce(status_key, status) as col, count(*) as total')
            ->groupBy('col')
            ->pluck('total', 'col')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return Collection<int, TaskStatus> */
    public function own(Project $project): Collection
    {
        return TaskStatus::where('project_id', $project->id)->ordered()->get();
    }
}
