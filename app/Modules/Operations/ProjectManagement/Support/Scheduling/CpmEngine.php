<?php

namespace App\Modules\Operations\ProjectManagement\Support\Scheduling;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Critical path method (PM-1230, PM-1231).
 *
 * Two passes over a topologically ordered network. Forward, from the project start, gives each task
 * the earliest it can happen; backward, from the project finish, gives the latest it can happen
 * without delaying anything. The gap between them is float, and the tasks with none are the ones
 * where a day lost is a day lost off the end of the job.
 *
 * A pure function of its arguments (PM-ADR-005): tasks, links, a start date and a calendar in;
 * dates out. No database, no clock, no Eloquent — which is what makes the reference fixtures in
 * CpmEngineTest worth anything, and what lets the same engine answer "what if" against a
 * calendar nobody has saved.
 *
 * All arithmetic is in *working* minutes, delegated to WorkingTime. Adding elapsed time instead is
 * the single most common way a schedule ends up quietly wrong.
 */
final class CpmEngine
{
    /**
     * @param  list<ScheduleTask>  $tasks
     * @param  list<ScheduleLink>  $links
     */
    public function schedule(
        array $tasks,
        array $links,
        CarbonImmutable $projectStart,
        WorkingTime $calendar,
    ): ScheduleResult {
        if ($tasks === []) {
            return new ScheduleResult([], [], [], null, null);
        }

        $byId = [];

        foreach ($tasks as $task) {
            if (isset($byId[$task->id])) {
                throw new InvalidArgumentException("Task '{$task->id}' appears twice in the network.");
            }

            $byId[$task->id] = $task;
        }

        $this->assertLinksResolve($links, $byId);

        $successors = [];   // id => list<ScheduleLink>
        $predecessors = []; // id => list<ScheduleLink>

        foreach ($links as $link) {
            $successors[$link->predecessorId][] = $link;
            $predecessors[$link->successorId][] = $link;
        }

        $order = $this->topologicalOrder($byId, $successors, $predecessors);

        $conflicts = [];

        [$earlyStart, $earlyFinish] = $this->forwardPass(
            $order, $byId, $predecessors, $projectStart, $calendar, $conflicts
        );

        $projectFinish = $this->latest($earlyFinish);

        [$lateStart, $lateFinish] = $this->backwardPass(
            $order, $byId, $successors, $projectFinish, $calendar
        );

        $dates = $this->measureFloat(
            $byId, $successors, $earlyStart, $earlyFinish, $lateStart, $lateFinish, $calendar, $conflicts
        );

        return new ScheduleResult(
            tasks: $dates,
            criticalPath: $this->criticalPath($order, $dates, $successors),
            conflicts: $conflicts,
            start: $this->earliest($earlyStart),
            finish: $projectFinish,
        );
    }

    // ── Forward ──────────────────────────────────────────────────────────────

    /**
     * Earliest each task can start and finish.
     *
     * @param  list<string>  $order
     * @param  array<string, ScheduleTask>  $byId
     * @param  array<string, list<ScheduleLink>>  $predecessors
     * @param  list<ScheduleConflict>  $conflicts
     * @return array{0: array<string,CarbonImmutable>, 1: array<string,CarbonImmutable>}
     */
    private function forwardPass(
        array $order,
        array $byId,
        array $predecessors,
        CarbonImmutable $projectStart,
        WorkingTime $calendar,
        array &$conflicts,
    ): array {
        $earlyStart = [];
        $earlyFinish = [];

        foreach ($order as $id) {
            $task = $byId[$id];

            $start = $calendar->next($projectStart);
            // Which predecessor is actually holding this task back. Kept so a clash can name it:
            // "the work it depends on" sends someone hunting through the network for which.
            $driver = null;

            foreach ($predecessors[$id] ?? [] as $link) {
                $candidate = $this->earliestFromLink($link, $task, $earlyStart, $earlyFinish, $calendar);

                if ($candidate > $start) {
                    $start = $candidate;
                    $driver = $byId[$link->predecessorId];
                }
            }

            $networkStart = $start;

            // A manually-scheduled task sits where it was put. It still drives its successors —
            // that is the difference between manual scheduling and no scheduling.
            if ($task->scheduleMode === 'manual' && $task->manualStart) {
                $start = $calendar->next($task->manualStart);
            }

            $start = $this->applyStartConstraint($task, $start, $networkStart, $driver, $calendar, $conflicts);

            // What actually happened outranks all of it. A schedule that insists a task started on
            // the day the plan wanted, when the crew arrived three days later, is fiction.
            if ($task->actualStart) {
                $start = $calendar->next($task->actualStart);
            }

            $finish = $task->actualEnd
                ? $calendar->previous($task->actualEnd)
                : $calendar->add($start, $task->durationMinutes);

            $finish = $this->applyFinishConstraint($task, $finish, $calendar);

            // A finish constraint can pull the finish earlier than the duration allows; the start
            // moves with it so the two stay a duration apart and the successors are told the truth.
            if (! $task->actualEnd && $finish < $calendar->add($start, $task->durationMinutes)) {
                $start = $calendar->subtract($finish, $task->durationMinutes);
            }

            $earlyStart[$id] = $start;
            $earlyFinish[$id] = $finish;
        }

        return [$earlyStart, $earlyFinish];
    }

    /**
     * @param  array<string,CarbonImmutable>  $earlyStart
     * @param  array<string,CarbonImmutable>  $earlyFinish
     */
    private function earliestFromLink(
        ScheduleLink $link,
        ScheduleTask $successor,
        array $earlyStart,
        array $earlyFinish,
        WorkingTime $calendar,
    ): CarbonImmutable {
        $predecessorStart = $earlyStart[$link->predecessorId];
        $predecessorFinish = $earlyFinish[$link->predecessorId];

        return match ($link->type) {
            // Constrains the successor's start directly.
            'fs' => $calendar->add($predecessorFinish, $link->lagMinutes),
            'ss' => $calendar->add($predecessorStart, $link->lagMinutes),
            // Constrains its finish, so the start is that less the duration.
            'ff' => $calendar->subtract(
                $calendar->add($predecessorFinish, $link->lagMinutes), $successor->durationMinutes
            ),
            'sf' => $calendar->subtract(
                $calendar->add($predecessorStart, $link->lagMinutes), $successor->durationMinutes
            ),
        };
    }

    /**
     * @param  list<ScheduleConflict>  $conflicts
     */
    private function applyStartConstraint(
        ScheduleTask $task,
        CarbonImmutable $start,
        CarbonImmutable $networkStart,
        ?ScheduleTask $driver,
        WorkingTime $calendar,
        array &$conflicts,
    ): CarbonImmutable {
        if ($task->constraintDate === null) {
            return $start;
        }

        $constrained = $calendar->next($task->constraintDate);

        return match ($task->constraintType) {
            // "Not before": only ever holds a task back.
            'snet' => $constrained > $start ? $constrained : $start,

            // "Must": the date wins, and if the network disagrees the person is told which of the
            // two facts they are looking at is the one that moved.
            'mso' => $this->pin($task, $constrained, $networkStart, $driver, $conflicts),

            default => $start,
        };
    }

    /**
     * @param  list<ScheduleConflict>  $conflicts
     */
    private function pin(
        ScheduleTask $task,
        CarbonImmutable $constrained,
        CarbonImmutable $networkStart,
        ?ScheduleTask $driver,
        array &$conflicts,
    ): CarbonImmutable {
        if ($networkStart > $constrained) {
            $conflicts[] = new ScheduleConflict(
                $task->id,
                'constraint_vs_predecessor',
                sprintf(
                    "'%s' must start on %s, but %s cannot be finished in time — the earliest it leaves is %s. Either that date moves or the work ahead of it has to be shortened.",
                    $task->label(),
                    $constrained->toFormattedDateString(),
                    $driver ? "'{$driver->label()}'" : 'the work it depends on',
                    $networkStart->toFormattedDateString(),
                ),
            );
        }

        return $constrained;
    }

    private function applyFinishConstraint(ScheduleTask $task, CarbonImmutable $finish, WorkingTime $calendar): CarbonImmutable
    {
        if ($task->constraintDate === null) {
            return $finish;
        }

        $constrained = $calendar->previous($task->constraintDate);

        return match ($task->constraintType) {
            'fnet' => $constrained > $finish ? $constrained : $finish,
            'mfo'  => $constrained,
            // fnlt is a deadline, not a shove: it does not pull the work earlier, it shows up as
            // negative float in the backward pass, which is the honest way to say "this is late".
            default => $finish,
        };
    }

    // ── Backward ─────────────────────────────────────────────────────────────

    /**
     * Latest each task can start and finish without pushing the project out.
     *
     * @param  list<string>  $order
     * @param  array<string, ScheduleTask>  $byId
     * @param  array<string, list<ScheduleLink>>  $successors
     * @return array{0: array<string,CarbonImmutable>, 1: array<string,CarbonImmutable>}
     */
    private function backwardPass(
        array $order,
        array $byId,
        array $successors,
        CarbonImmutable $projectFinish,
        WorkingTime $calendar,
    ): array {
        $lateStart = [];
        $lateFinish = [];

        foreach (array_reverse($order) as $id) {
            $task = $byId[$id];

            $finish = $calendar->previous($projectFinish);

            foreach ($successors[$id] ?? [] as $link) {
                $candidate = $this->latestFromLink($link, $task, $lateStart, $lateFinish, $calendar);

                if ($candidate < $finish) {
                    $finish = $candidate;
                }
            }

            // A deadline is what makes float go negative — the whole point of setting one.
            if ($task->constraintType === 'fnlt' && $task->constraintDate) {
                $deadline = $calendar->previous($task->constraintDate);
                $finish = $deadline < $finish ? $deadline : $finish;
            }

            if ($task->constraintType === 'mfo' && $task->constraintDate) {
                $finish = $calendar->previous($task->constraintDate);
            }

            if ($task->constraintType === 'snlt' && $task->constraintDate) {
                $latestStart = $calendar->next($task->constraintDate);
                $impliedFinish = $calendar->add($latestStart, $task->durationMinutes);
                $finish = $impliedFinish < $finish ? $impliedFinish : $finish;
            }

            $lateFinish[$id] = $finish;
            $lateStart[$id] = $calendar->subtract($finish, $task->durationMinutes);
        }

        return [$lateStart, $lateFinish];
    }

    /**
     * @param  array<string,CarbonImmutable>  $lateStart
     * @param  array<string,CarbonImmutable>  $lateFinish
     */
    private function latestFromLink(
        ScheduleLink $link,
        ScheduleTask $predecessor,
        array $lateStart,
        array $lateFinish,
        WorkingTime $calendar,
    ): CarbonImmutable {
        $successorStart = $lateStart[$link->successorId];
        $successorFinish = $lateFinish[$link->successorId];

        return match ($link->type) {
            'fs' => $calendar->subtract($successorStart, $link->lagMinutes),
            'ff' => $calendar->subtract($successorFinish, $link->lagMinutes),
            // These constrain the predecessor's *start*, so convert back to a finish.
            'ss' => $calendar->add(
                $calendar->subtract($successorStart, $link->lagMinutes), $predecessor->durationMinutes
            ),
            'sf' => $calendar->add(
                $calendar->subtract($successorFinish, $link->lagMinutes), $predecessor->durationMinutes
            ),
        };
    }

    // ── Float ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, ScheduleTask>  $byId
     * @param  array<string, list<ScheduleLink>>  $successors
     * @param  array<string,CarbonImmutable>  $earlyStart
     * @param  array<string,CarbonImmutable>  $earlyFinish
     * @param  array<string,CarbonImmutable>  $lateStart
     * @param  array<string,CarbonImmutable>  $lateFinish
     * @param  list<ScheduleConflict>  $conflicts
     * @return array<string, ScheduledDates>
     */
    private function measureFloat(
        array $byId,
        array $successors,
        array $earlyStart,
        array $earlyFinish,
        array $lateStart,
        array $lateFinish,
        WorkingTime $calendar,
        array &$conflicts,
    ): array {
        $dates = [];

        foreach ($byId as $id => $task) {
            $total = $calendar->between($earlyFinish[$id], $lateFinish[$id]);

            // Free float: how far this can slip before *anything else* has to move. Measured to the
            // earliest start of each successor across the link, not to the project end.
            $free = $total;

            foreach ($successors[$id] ?? [] as $link) {
                $slack = match ($link->type) {
                    'fs' => $calendar->between($calendar->add($earlyFinish[$id], $link->lagMinutes), $earlyStart[$link->successorId]),
                    'ss' => $calendar->between($calendar->add($earlyStart[$id], $link->lagMinutes), $earlyStart[$link->successorId]),
                    'ff' => $calendar->between($calendar->add($earlyFinish[$id], $link->lagMinutes), $earlyFinish[$link->successorId]),
                    'sf' => $calendar->between($calendar->add($earlyStart[$id], $link->lagMinutes), $earlyFinish[$link->successorId]),
                };

                $free = min($free, $slack);
            }

            if ($total < 0) {
                $conflicts[] = new ScheduleConflict(
                    $id,
                    $task->constraintType === 'fnlt' ? 'deadline_missed' : 'negative_float',
                    $this->lateMessage($task, $total, $lateFinish[$id], $earlyFinish[$id], $calendar),
                );
            }

            $dates[$id] = new ScheduledDates(
                earlyStart: $earlyStart[$id],
                earlyFinish: $earlyFinish[$id],
                lateStart: $lateStart[$id],
                lateFinish: $lateFinish[$id],
                totalFloatMinutes: $total,
                freeFloatMinutes: max(0, $free),
                // Zero float or worse: a day lost here is a day lost off the end of the job.
                isCritical: $total <= 0,
            );
        }

        return $dates;
    }

    private function lateMessage(
        ScheduleTask $task,
        int $floatMinutes,
        CarbonImmutable $lateFinish,
        CarbonImmutable $earlyFinish,
        WorkingTime $calendar,
    ): string {
        // Days on *this* calendar. Assuming eight hours would report a nine-hour site as later
        // than it is, and a round-the-clock one as three times later.
        $perDay = max(1, $calendar->minutesPerWorkingDay());
        $days = round(abs($floatMinutes) / $perDay, 1);

        if ($task->constraintType === 'fnlt') {
            return sprintf(
                "'%s' cannot finish by %s. The earliest it can be done is %s — about %s working day(s) late.",
                $task->label(),
                $lateFinish->toFormattedDateString(),
                $earlyFinish->toFormattedDateString(),
                $days,
            );
        }

        return sprintf(
            "'%s' is about %s working day(s) behind what the rest of the schedule needs from it.",
            $task->label(),
            $days,
        );
    }

    /**
     * The longest zero-float chain, in order.
     *
     * Every critical task matters, but a *path* is what people walk when they are looking for time
     * to take out, so the order is worth preserving.
     *
     * @param  list<string>  $order
     * @param  array<string, ScheduledDates>  $dates
     * @param  array<string, list<ScheduleLink>>  $successors
     * @return list<string>
     */
    private function criticalPath(array $order, array $dates, array $successors): array
    {
        $best = [];
        $longest = [];  // id => the longest critical chain ending at this task

        foreach ($order as $id) {
            if (! $dates[$id]->isCritical) {
                continue;
            }

            $longest[$id] = [$id];

            foreach ($order as $candidate) {
                if ($candidate === $id || ! isset($longest[$candidate])) {
                    continue;
                }

                $linked = false;

                foreach ($successors[$candidate] ?? [] as $link) {
                    if ($link->successorId === $id) {
                        $linked = true;

                        break;
                    }
                }

                if ($linked && count($longest[$candidate]) + 1 > count($longest[$id])) {
                    $longest[$id] = [...$longest[$candidate], $id];
                }
            }

            if (count($longest[$id]) > count($best)) {
                $best = $longest[$id];
            }
        }

        return $best;
    }

    // ── Structure ────────────────────────────────────────────────────────────

    /**
     * @param  list<ScheduleLink>  $links
     * @param  array<string, ScheduleTask>  $byId
     */
    private function assertLinksResolve(array $links, array $byId): void
    {
        foreach ($links as $link) {
            foreach ([$link->predecessorId, $link->successorId] as $id) {
                if (! isset($byId[$id])) {
                    throw new InvalidArgumentException(
                        "A dependency refers to '{$id}', which is not a task in this schedule."
                    );
                }
            }
        }
    }

    /**
     * Kahn's algorithm. A cycle leaves tasks unprocessed, and rather than reporting a count we walk
     * the remainder to name the loop — "a → b → c → a" is something a person can go and fix.
     *
     * @param  array<string, ScheduleTask>  $byId
     * @param  array<string, list<ScheduleLink>>  $successors
     * @param  array<string, list<ScheduleLink>>  $predecessors
     * @return list<string>
     */
    private function topologicalOrder(array $byId, array $successors, array $predecessors): array
    {
        $remaining = [];
        $queue = [];

        foreach ($byId as $id => $_) {
            $remaining[$id] = count($predecessors[$id] ?? []);

            if ($remaining[$id] === 0) {
                $queue[] = $id;
            }
        }

        $order = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;

            foreach ($successors[$id] ?? [] as $link) {
                if (--$remaining[$link->successorId] === 0) {
                    $queue[] = $link->successorId;
                }
            }
        }

        if (count($order) !== count($byId)) {
            throw new InvalidArgumentException(
                'These tasks depend on each other in a loop, so none of them can be scheduled: '
                . $this->describeCycle($byId, $successors, $order) . '.'
            );
        }

        return $order;
    }

    /**
     * @param  array<string, ScheduleTask>  $byId
     * @param  array<string, list<ScheduleLink>>  $successors
     * @param  list<string>  $scheduled
     */
    private function describeCycle(array $byId, array $successors, array $scheduled): string
    {
        $stuck = array_diff(array_keys($byId), $scheduled);
        $start = (string) reset($stuck);

        // Walk forward through the stuck set until a task repeats — that repeat is the loop.
        $path = [];
        $seen = [];
        $current = $start;

        while ($current !== null && ! isset($seen[$current])) {
            $seen[$current] = true;
            $path[] = $current;
            $current = null;

            foreach ($successors[$path[count($path) - 1]] ?? [] as $link) {
                if (in_array($link->successorId, $stuck, true)) {
                    $current = $link->successorId;

                    break;
                }
            }
        }

        if ($current !== null) {
            $path[] = $current;
        }

        return implode(' → ', array_map(fn (string $id) => $byId[$id]->label(), $path));
    }

    /** @param array<string,CarbonImmutable> $dates */
    private function earliest(array $dates): ?CarbonImmutable
    {
        $earliest = null;

        foreach ($dates as $date) {
            if ($earliest === null || $date < $earliest) {
                $earliest = $date;
            }
        }

        return $earliest;
    }

    /** @param array<string,CarbonImmutable> $dates */
    private function latest(array $dates): CarbonImmutable
    {
        $latest = null;

        foreach ($dates as $date) {
            if ($latest === null || $date > $latest) {
                $latest = $date;
            }
        }

        return $latest;
    }
}
