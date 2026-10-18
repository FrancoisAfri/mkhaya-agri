<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskRecurrence;
use App\Modules\Operations\ProjectManagement\Support\Recurrence\Rrule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recurring tasks (PM-1122).
 *
 * The rule hangs off a template task nobody works on; each occurrence is copied from it and is an
 * ordinary task in every other respect. That is what keeps the rest of the module simple: the
 * ledger, the rollup and the scheduler need no concept of recurrence at all.
 *
 * Occurrences are materialised a horizon ahead rather than computed on read, because a task that
 * exists only when someone opens the right screen cannot be assigned, commented on, or completed
 * offline — and offline completion is the point of the field app (EPIC-060).
 */
class RecurrenceService
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    /**
     * Attach a rule to a task, which becomes the template.
     *
     * @param  array{timezone?:string,starts_at?:string|CarbonImmutable,ends_at?:string|CarbonImmutable|null,horizon_days?:int}  $options
     */
    public function create(Project $project, Task $template, string $rrule, array $options = []): TaskRecurrence
    {
        if ($template->project_id !== $project->id) {
            throw new InvalidArgumentException('That task belongs to a different project.');
        }

        if ($template->occurrence_of_id) {
            throw new InvalidArgumentException(
                'That task is already an occurrence of a recurring task; put the rule on the original.'
            );
        }

        // Parse before writing: an unsupported rule must fail here, with its reason, rather than
        // silently generate nothing at 02:00 and be discovered as a gap in the schedule.
        Rrule::parse($rrule);

        $timezone = $options['timezone'] ?? $project->timezone ?? config('app.timezone');
        $startsAt = isset($options['starts_at'])
            ? CarbonImmutable::parse($options['starts_at'], $timezone)
            : CarbonImmutable::parse($template->planned_start ?? now(), $timezone);

        $horizon = (int) ($options['horizon_days'] ?? 60);

        if ($horizon < 1 || $horizon > TaskRecurrence::MAX_HORIZON_DAYS) {
            throw new InvalidArgumentException(
                'The horizon must be between 1 and ' . TaskRecurrence::MAX_HORIZON_DAYS . ' days.'
            );
        }

        return DB::transaction(function () use ($project, $template, $rrule, $timezone, $startsAt, $horizon, $options) {
            $template->forceFill(['is_recurrence_template' => true])->save();

            return TaskRecurrence::create([
                'project_id'       => $project->id,
                'template_task_id' => $template->id,
                // Stored as written, not as parsed: round-tripping through the parser would drop
                // the author's ordering and make the rule they typed unrecognisable to them.
                'rrule'            => $rrule,
                'timezone'         => $timezone,
                'starts_at'        => $startsAt,
                'ends_at'          => isset($options['ends_at']) && $options['ends_at']
                    ? CarbonImmutable::parse($options['ends_at'], $timezone)
                    : null,
                'horizon_days'     => $horizon,
                'is_active'        => true,
                'created_by'       => Auth::id(),
            ]);
        });
    }

    /**
     * Generate the occurrences now due for one rule.
     *
     * Idempotent by construction: every generated task carries (occurrence_of_id, occurrence_date)
     * under a unique index, so a second run in the same window inserts nothing. That matters more
     * than it looks — a scheduled command that overlaps itself, or a manual run after a failure,
     * must not double the site's inspection list.
     *
     * @return list<Task>
     */
    public function generate(TaskRecurrence $recurrence, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone($recurrence->timezone);

        if (! $recurrence->is_active) {
            return [];
        }

        $rule = Rrule::parse($recurrence->rrule);
        $start = CarbonImmutable::parse($recurrence->starts_at, $recurrence->timezone);

        // Resume from just after the last one made, so a rule whose horizon has already been filled
        // does no work at all on the next run.
        $from = $recurrence->last_occurrence_at
            ? CarbonImmutable::parse($recurrence->last_occurrence_at, $recurrence->timezone)->addSecond()
            : $start;

        $to = $now->addDays($recurrence->horizon_days);

        if ($recurrence->ends_at) {
            $end = CarbonImmutable::parse($recurrence->ends_at, $recurrence->timezone);
            $to = $to->min($end);
        }

        $occurrences = $rule->between($start, $from, $to);

        if ($occurrences === []) {
            $recurrence->forceFill(['last_generated_at' => $now])->save();

            return [];
        }

        $template = $recurrence->template()->withTrashed()->first();

        if (! $template || $template->trashed()) {
            // The template is gone; the rule has nothing to copy. Deactivate rather than fail on a
            // schedule every night.
            $recurrence->forceFill(['is_active' => false, 'last_generated_at' => $now])->save();

            return [];
        }

        $created = [];

        foreach ($occurrences as $occurrence) {
            if ($task = $this->materialise($recurrence, $template, $occurrence)) {
                $created[] = $task;
            }
        }

        $recurrence->forceFill([
            'last_generated_at'  => $now,
            'last_occurrence_at' => end($occurrences),
            'generated_count'    => $recurrence->generated_count + count($created),
        ])->save();

        return $created;
    }

    /**
     * Generate for every active rule in the current tenant.
     *
     * @return array{rules:int,tasks:int}
     */
    public function generateDue(?CarbonImmutable $now = null): array
    {
        $rules = 0;
        $tasks = 0;

        TaskRecurrence::due()->orderBy('id')->chunkById(100, function ($chunk) use (&$rules, &$tasks, $now) {
            foreach ($chunk as $recurrence) {
                $rules++;
                $tasks += count($this->generate($recurrence, $now));
            }
        });

        return ['rules' => $rules, 'tasks' => $tasks];
    }

    public function pause(TaskRecurrence $recurrence): TaskRecurrence
    {
        $recurrence->forceFill(['is_active' => false])->save();

        return $recurrence;
    }

    public function resume(TaskRecurrence $recurrence): TaskRecurrence
    {
        $recurrence->forceFill(['is_active' => true])->save();

        return $recurrence;
    }

    /**
     * Stop a series.
     *
     * Occurrences already generated stay: they are real work, some of it already done, and deleting
     * a fortnight of completed inspections because someone cancelled the rule would destroy the
     * record of what happened. Only the future is cancelled, and only what nobody has touched.
     */
    public function stop(TaskRecurrence $recurrence, bool $cancelUntouchedFuture = true): int
    {
        return DB::transaction(function () use ($recurrence, $cancelUntouchedFuture) {
            $recurrence->forceFill(['is_active' => false])->save();

            if (! $cancelUntouchedFuture) {
                return 0;
            }

            return Task::where('occurrence_of_id', $recurrence->id)
                ->where('occurrence_date', '>', now())
                ->where('status', 'not_started')
                ->whereNull('actual_start')
                ->update(['status' => 'cancelled']);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** One occurrence, or null if it already exists. */
    private function materialise(TaskRecurrence $recurrence, Task $template, CarbonImmutable $occurrence): ?Task
    {
        $exists = Task::withTrashed()
            ->where('occurrence_of_id', $recurrence->id)
            ->where('occurrence_date', $occurrence)
            ->exists();

        if ($exists) {
            return null;
        }

        $duration = (int) ($template->duration_minutes ?: 0);

        try {
            return $this->makeOccurrence($recurrence, $template, $occurrence, $duration);
        } catch (QueryException $e) {
            // 23505: the unique index caught a concurrent run that got there first. The check above
            // is the fast path; this is the one that actually holds, because two runs can both pass
            // the check before either inserts. Skipping is the correct outcome — the occurrence
            // exists, which is all the caller wanted.
            if ($e->getCode() === '23505') {
                return null;
            }

            throw $e;
        }
    }

    private function makeOccurrence(
        TaskRecurrence $recurrence,
        Task $template,
        CarbonImmutable $occurrence,
        int $duration,
    ): Task {
        return $this->tasks->create(
            $recurrence->project,
            [
                'name'             => $template->name,
                'description'      => $template->description,
                'priority'         => $template->priority,
                'duration_minutes' => $duration,
                'estimated_hours'  => $template->estimated_hours,
                'is_milestone'     => $template->is_milestone,
                'currency'         => $template->currency,
                'calendar_id'      => $template->calendar_id,
                // Calendar-unaware for now: an occurrence spans wall-clock minutes. The scheduling
                // engine (PM-1230) owns working-time arithmetic, and duplicating a lesser version
                // of it here would be a second answer to the same question.
                'planned_start'    => $occurrence,
                'planned_end'      => $duration > 0 ? $occurrence->addMinutes($duration) : $occurrence,
                'occurrence_of_id' => $recurrence->id,
                'occurrence_date'  => $occurrence,
                'created_by'       => $recurrence->created_by,
            ],
            null,
            $template->wbsNode,
        );
    }
}
