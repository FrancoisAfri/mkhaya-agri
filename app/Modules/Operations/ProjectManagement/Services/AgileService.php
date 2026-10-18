<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Epic;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Release;
use App\Modules\Operations\ProjectManagement\Models\Sprint;
use App\Modules\Operations\ProjectManagement\Models\Task;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprints, backlogs, velocity and releases (§24).
 *
 * The whole pack rests on stories being ordinary tasks. That means everything here is about
 * *arrangement* — what is in which sprint, in what order, worth how much — and nothing here has to
 * reimplement status, assignment or estimation.
 */
class AgileService
{
    /** How many closed sprints velocity averages over. Three is the usual convention. */
    private const VELOCITY_SPRINTS = 3;

    /** Gap between backlog ranks, wide enough that ordinary reordering never needs a rebalance. */
    private const RANK_GAP = 1024.0;

    public function __construct(private readonly InternalEmitter $emitter)
    {
    }

    // ── Sprints ─────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function createSprint(Project $project, array $data): Sprint
    {
        return Sprint::create([
            ...$data,
            'project_id' => $project->id,
            'number'     => (int) Sprint::where('project_id', $project->id)->max('number') + 1,
            'status'     => 'planned',
        ]);
    }

    /**
     * Start a sprint, freezing what was committed.
     *
     * Only one sprint runs at a time. Two active sprints on one project makes velocity, the
     * burn-down and "what am I working on" all ambiguous, and teams that think they want it
     * actually want two projects.
     */
    public function startSprint(Sprint $sprint): Sprint
    {
        if ($sprint->status !== 'planned') {
            throw new RuntimeException(__('project_management.agile.already_started'));
        }

        if (Sprint::where('project_id', $sprint->project_id)->active()->exists()) {
            throw new RuntimeException(__('project_management.agile.one_at_a_time'));
        }

        return DB::transaction(function () use ($sprint) {
            $sprint->update([
                'status'           => 'active',
                'started_at'       => now(),
                // Frozen here. Recomputing it later would let work added mid-sprint raise the
                // denominator, and every sprint would finish looking on target.
                'committed_points' => (float) Task::where('sprint_id', $sprint->id)->sum('story_points'),
            ]);

            return $sprint->refresh();
        });
    }

    /**
     * Close a sprint, and move what did not finish.
     *
     * Unfinished stories go back to the backlog rather than forward to the next sprint. Carrying
     * them automatically is how a sprint silently starts full before anybody has planned it, and it
     * hides the fact that the last one over-committed.
     */
    public function closeSprint(Sprint $sprint, ?string $retrospective = null): Sprint
    {
        if ($sprint->status !== 'active') {
            throw new RuntimeException(__('project_management.agile.not_active'));
        }

        return DB::transaction(function () use ($sprint, $retrospective) {
            $done = Task::where('sprint_id', $sprint->id)->where('status', 'done');

            $sprint->update([
                'status'           => 'closed',
                'closed_at'        => now(),
                'completed_points' => (float) $done->sum('story_points'),
                'retrospective'    => $retrospective,
            ]);

            Task::where('sprint_id', $sprint->id)
                ->where('status', '!=', 'done')
                ->update(['sprint_id' => null]);

            $this->emitter->emit($sprint->project, 'status.reported', [
                'subject_type' => 'project',
                'subject_id'   => $sprint->project->uuid,
                'source_ref'   => 'sprint:' . $sprint->uuid . ':closed',
                'payload'      => [
                    'status' => 'sprint_closed',
                    'note'   => __('project_management.agile.closed_note', [
                        'name'      => $sprint->name,
                        'completed' => (string) $sprint->completed_points,
                        'committed' => (string) $sprint->committed_points,
                    ]),
                ],
            ]);

            return $sprint->refresh();
        });
    }

    /**
     * Points per sprint over the last few closed sprints.
     *
     * Returns null rather than zero when there is nothing to average. A team's first sprint has no
     * velocity, and showing "0" invites somebody to plan against it.
     */
    public function velocity(Project $project): ?float
    {
        $closed = Sprint::where('project_id', $project->id)
            ->where('status', 'closed')
            ->whereNotNull('completed_points')
            ->orderByDesc('closed_at')
            ->limit(self::VELOCITY_SPRINTS)
            ->pluck('completed_points');

        return $closed->isEmpty() ? null : round((float) $closed->avg(), 1);
    }

    /**
     * The burn-down for an active sprint: an ideal line and what actually remains.
     *
     * Remaining is computed from what is *not* done, against the committed total frozen at start —
     * so a story added on Wednesday shows as the line going up, which is exactly what a burn-down
     * is for.
     *
     * @return array{days:list<int>, ideal:list<float>, actual:list<?float>}
     */
    public function burndown(Sprint $sprint): array
    {
        $total = (float) ($sprint->committed_points ?? 0);
        $days = $sprint->totalDays();
        $today = $sprint->dayOf();

        $remaining = (float) Task::where('sprint_id', $sprint->id)
            ->where('status', '!=', 'done')
            ->sum('story_points');

        $out = ['days' => [], 'ideal' => [], 'actual' => []];

        for ($d = 1; $d <= $days; $d++) {
            $out['days'][] = $d;
            $out['ideal'][] = round($total - ($total / $days) * ($d - 1), 1);
            // Only up to today: drawing the actual line into the future would be a forecast
            // dressed as a measurement.
            $out['actual'][] = $today !== null && $d < $today ? null : ($d === $today ? $remaining : null);
        }

        return $out;
    }

    // ── Backlog ─────────────────────────────────────────────────────────────

    /**
     * Put a story between two others.
     *
     * Ranks are floats and the new one is the midpoint, so a drag touches exactly one row. With
     * integers the same operation renumbers everything below it, which on a 400-item backlog is
     * four hundred writes for one drag.
     */
    public function rank(Task $task, ?Task $after, ?Task $before): Task
    {
        $lower = $after?->backlog_rank;
        $upper = $before?->backlog_rank;

        $rank = match (true) {
            $lower !== null && $upper !== null => ($lower + $upper) / 2,
            $lower !== null                    => $lower + self::RANK_GAP,
            $upper !== null                    => $upper - self::RANK_GAP,
            default                            => self::RANK_GAP,
        };

        $task->update(['backlog_rank' => $rank]);

        return $task->refresh();
    }

    /**
     * Give every story on a project a rank.
     *
     * Needed once when a project starts using the backlog, because tasks created before then have
     * none and would all sort together at the top.
     */
    public function seedRanks(Project $project): int
    {
        $unranked = Task::where('project_id', $project->id)->whereNull('backlog_rank')->orderBy('id')->get();

        $start = (float) (Task::where('project_id', $project->id)->max('backlog_rank') ?? 0);

        foreach ($unranked as $i => $task) {
            $task->update(['backlog_rank' => $start + self::RANK_GAP * ($i + 1)]);
        }

        return $unranked->count();
    }

    // ── Epics and releases ──────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function createEpic(Project $project, array $data): Epic
    {
        $n = Epic::where('project_id', $project->id)->count() + 1;

        return Epic::create([...$data, 'project_id' => $project->id, 'key' => sprintf('E-%02d', $n)]);
    }

    /** @param array<string,mixed> $data */
    public function createRelease(Project $project, array $data): Release
    {
        return Release::create([...$data, 'project_id' => $project->id, 'status' => 'planned']);
    }

    /** Mark a release as gone out, and record what was in it. */
    public function ship(Release $release, array $data): Release
    {
        return DB::transaction(function () use ($release, $data) {
            $release->update([
                'status'      => 'released',
                'released_at' => now(),
                'revision'    => $data['revision'] ?? $release->revision,
                'notes'       => $data['notes'] ?? $release->notes,
                'released_by' => $data['released_by'] ?? null,
            ]);

            $this->emitter->emit($release->project, 'milestone.verified', [
                'subject_type' => 'project',
                'subject_id'   => $release->project->uuid,
                'source_ref'   => 'release:' . $release->uuid,
                'payload'      => [
                    'milestone'   => __('project_management.agile.released_milestone', ['version' => $release->version]),
                    'verified_by' => (string) ($release->revision ?: $release->version),
                ],
            ]);

            return $release->refresh();
        });
    }
}
