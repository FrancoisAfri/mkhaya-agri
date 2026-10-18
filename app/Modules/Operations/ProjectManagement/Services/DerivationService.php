<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Support\Derivation\DerivationEngine;
use App\Modules\Operations\ProjectManagement\Support\Derivation\DerivationRules;
use App\Modules\Operations\ProjectManagement\Support\Derivation\DerivedFigure;
use App\Modules\Operations\ProjectManagement\Support\Derivation\EvidenceRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Derivation against the real ledger (PM-1200…1204).
 *
 * The stored figures are a cache and are treated as one: they can be deleted and rebuilt from the
 * ledger at any time, and `reconcile()` exists to prove they still match what the evidence says.
 * The ledger is the system of record; this table is what makes reading it fast.
 *
 * An override lets a person state a different number, but never anonymously and never destructively
 * — the derived value and the evidence behind it stay in the row, underneath.
 */
class DerivationService
{
    public function __construct(
        private readonly DerivationEngine $engine,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Recompute every metric for one task and store the result.
     *
     * @return array<string, DerivedFigure>
     */
    public function deriveTask(Task $task): array
    {
        $figures = $this->engine->derive(
            $this->evidenceFor('task', $task->id, $task->uuid),
            ['planned_quantity' => $task->planned_quantity],
        );

        DB::transaction(function () use ($task, $figures) {
            foreach ($figures as $figure) {
                $this->store('task', $task->id, $task->uuid, $figure);
            }

            $this->mirrorProgress($task, $figures['progress_pct'] ?? null);
        });

        return $figures;
    }

    /**
     * Recompute a whole project: every leaf task, then the parents that summarise them.
     *
     * Leaves only, because a summary task's progress is the weighted roll-up of its children
     * (TaskService::rollUp). Deriving both would give a parent two different answers depending on
     * which ran last.
     *
     * @return array{tasks:int, figures:int}
     */
    public function deriveProject(Project $project): array
    {
        $tasks = 0;
        $figures = 0;

        Task::where('project_id', $project->id)
            ->real()
            ->whereDoesntHave('children')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$tasks, &$figures) {
                foreach ($chunk as $task) {
                    $tasks++;
                    $figures += count($this->deriveTask($task));
                }
            });

        // Roll the leaves up into their summaries, once, after everything below has settled.
        $this->rollUpSummaries($project);

        return ['tasks' => $tasks, 'figures' => $figures];
    }

    /**
     * Recompute and report where the stored figures had drifted (PM-1204).
     *
     * Drift is not normal. It means evidence arrived without derivation running, or that derivation
     * itself changed — both worth knowing about, which is why this reports rather than silently
     * correcting.
     *
     * @return array{checked:int, diverged:list<array<string,mixed>>}
     */
    public function reconcile(Project $project): array
    {
        $before = DerivedValue::where('subject_type', 'task')
            ->whereIn('subject_id', Task::where('project_id', $project->id)->pluck('id'))
            ->get()
            ->keyBy(fn (DerivedValue $v) => $v->subject_id . ':' . $v->metric);

        $diverged = [];
        $checked = 0;

        Task::where('project_id', $project->id)
            ->real()
            ->whereDoesntHave('children')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$checked, &$diverged, $before) {
                foreach ($chunk as $task) {
                    foreach ($this->deriveTask($task) as $metric => $figure) {
                        $checked++;
                        $was = $before->get($task->id . ':' . $metric);

                        if (! $was || $was->is_overridden) {
                            continue;
                        }

                        // Compared at the stored scale, not with ===: two decimals of a numeric
                        // column round-trip as strings and would otherwise "differ" every run.
                        $old = $was->value_numeric === null ? null : round((float) $was->value_numeric, 4);
                        $new = $figure->value === null ? null : round($figure->value, 4);

                        if ($old !== $new || $was->method_version !== $figure->methodVersion) {
                            $diverged[] = [
                                'task'    => $task->code,
                                'metric'  => $metric,
                                'was'     => $old,
                                'now'     => $new,
                                'version' => [$was->method_version, $figure->methodVersion],
                            ];
                        }
                    }
                }
            });

        if ($diverged !== []) {
            $this->audit->recordAction($project, 'derivation_diverged', [
                'checked'  => $checked,
                'diverged' => count($diverged),
                // Enough to investigate with, not so much that the audit row becomes a data dump.
                'sample'   => array_slice($diverged, 0, 20),
            ]);
        }

        return ['checked' => $checked, 'diverged' => $diverged];
    }

    /**
     * State a different number, on the record.
     *
     * The derived value is not overwritten — it stays in the row beneath the override, so anyone
     * can see both what the evidence said and what a person decided instead.
     */
    public function override(DerivedValue $value, float $override, string $reason): DerivedValue
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'An override needs a reason. A number that disagrees with the evidence and does not say why is worse than the evidence.'
            );
        }

        $value->forceFill([
            'value_numeric'   => $override,
            'is_overridden'   => true,
            'override_by'     => Auth::id(),
            'override_reason' => $reason,
            'overridden_at'   => now(),
            // An override is a person's judgement, not a measurement.
            'confidence'      => 1.00,
        ])->save();

        $this->audit->recordAction($value, 'overridden', [
            'metric' => $value->metric,
            'value'  => $override,
            'reason' => $reason,
        ]);

        return $value;
    }

    /** Drop the override and let the evidence speak again. */
    public function clearOverride(DerivedValue $value): DerivedValue
    {
        $value->forceFill([
            'is_overridden'   => false,
            'override_by'     => null,
            'override_reason' => null,
            'overridden_at'   => null,
        ])->save();

        if ($value->subject_type === 'task' && $task = Task::find($value->subject_id)) {
            $this->deriveTask($task);
        }

        return $value->fresh();
    }

    /**
     * The events behind a figure, in order (PM-1300).
     *
     * Read by uuid from the stored list rather than recomputed: the drill-down must show what the
     * number was actually built from, even if the rules have changed since.
     *
     * @return \Illuminate\Support\Collection<int, EvidenceEvent>
     */
    public function evidenceBehind(DerivedValue $value): \Illuminate\Support\Collection
    {
        $uuids = $value->evidence_uuids ?: [];

        if ($uuids === []) {
            return collect();
        }

        return EvidenceEvent::whereIn('uuid', $uuids)
            ->orderBy('occurred_at')
            ->get();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return list<EvidenceRow> */
    private function evidenceFor(string $subjectType, int $subjectId, ?string $subjectUuid): array
    {
        return EvidenceEvent::where('subject_type', $subjectType)
            ->where(function ($q) use ($subjectId, $subjectUuid) {
                $q->where('subject_id', $subjectId);

                // Offline-created evidence may carry only the public id, because the device never
                // saw the internal one (PM-ADR-004).
                if ($subjectUuid) {
                    $q->orWhere('subject_uuid', $subjectUuid);
                }
            })
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (EvidenceEvent $e) => new EvidenceRow(
                uuid: $e->uuid,
                eventType: $e->event_type,
                occurredAt: CarbonImmutable::parse($e->occurred_at),
                // `quantity` and `amount` have dedicated, indexed columns as well as living in the
                // payload. Merged so a rule can name a field without caring which shape the
                // producer used; an explicit payload key wins, since that is what the taxonomy
                // validated and what the hash was taken over.
                payload: array_merge(
                    array_filter(
                        ['quantity' => $e->quantity, 'amount' => $e->amount],
                        fn ($v) => $v !== null,
                    ),
                    $e->payload ?? [],
                ),
                confidence: (float) ($e->confidence ?? 1.0),
                isSuperseded: $e->superseded_by !== null,
            ))
            ->all();
    }

    private function store(string $subjectType, int $subjectId, ?string $subjectUuid, DerivedFigure $figure): void
    {
        $existing = DerivedValue::where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('metric', $figure->metric)
            ->first();

        // An override is a decision someone made and has to withdraw deliberately. Recomputation
        // refreshes the evidence trail underneath it but must not quietly overwrite the number.
        if ($existing?->is_overridden) {
            $existing->forceFill([
                'evidence_uuids' => $figure->evidenceUuids,
                'evidence_count' => $figure->count(),
                'method_version' => $figure->methodVersion,
                'computed_at'    => now(),
            ])->save();

            return;
        }

        $attributes = [
            'subject_uuid'   => $subjectUuid,
            'value_numeric'  => $figure->value,
            'value_text'     => $figure->text,
            'confidence'     => $figure->confidence,
            'method_version' => $figure->methodVersion,
            'evidence_uuids' => $figure->evidenceUuids,
            'evidence_count' => $figure->count(),
            'computed_at'    => now(),
        ];

        $existing
            ? $existing->forceFill($attributes)->save()
            : DerivedValue::create([
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'metric'       => $figure->metric,
            ] + $attributes);
    }

    /**
     * Copy derived progress onto the task, which is what every list and board reads.
     *
     * `progress_source` records where the number came from, so a reader can tell a figure the
     * evidence produced from one a person typed.
     */
    private function mirrorProgress(Task $task, ?DerivedFigure $progress): void
    {
        if (! $progress || $progress->value === null) {
            return;
        }

        $overridden = DerivedValue::where('subject_type', 'task')
            ->where('subject_id', $task->id)
            ->where('metric', 'progress_pct')
            ->value('is_overridden');

        $task->forceFill([
            'progress_pct'    => $overridden ? $task->progress_pct : round($progress->value, 2),
            'progress_source' => $overridden ? 'manual' : 'derived',
        ])->save();
    }

    private function rollUpSummaries(Project $project): void
    {
        $tasks = app(TaskService::class);

        // Deepest first, so a grandparent is computed from parents that are already correct.
        Task::where('project_id', $project->id)
            ->real()
            ->whereHas('children')
            ->orderByDesc('id')
            ->get()
            ->each(fn (Task $parent) => $tasks->rollUp($parent));
    }

    /** @return list<string> */
    public function watchedEvents(): array
    {
        return DerivationRules::watchedEvents();
    }
}
