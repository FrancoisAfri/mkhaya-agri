<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\DerivationService;
use App\Modules\Operations\ProjectManagement\Services\EvidenceLedger;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Support\Derivation\DerivationRules;
use InvalidArgumentException;

/**
 * Derivation against the real ledger (PM-1200…1204, PM-1300).
 *
 * The rules are proved in Tests\Unit\ProjectManagement\DerivationEngineTest. This covers what only
 * the database can show: that the figures survive a rebuild, that an override is not quietly
 * undone by the next recompute, and that the drill-down still points at the right events.
 */
class DerivationServiceTest extends ProjectManagementTestCase
{
    private Project $project;

    private Task $task;

    private DerivationService $derivation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        $this->task = app(TaskService::class)->create($this->project, [
            'name' => 'Lay blockwork', 'planned_quantity' => 200, 'quantity_unit' => 'm²',
        ]);
        $this->derivation = app(DerivationService::class);
    }

    private function record(string $type, array $attributes = []): void
    {
        app(EvidenceLedger::class)->append(array_merge([
            'project_id'    => $this->project->id,
            'event_type'    => $type,
            'source_module' => 'pm',
            'subject_type'  => 'task',
            'subject_id'    => $this->task->id,
            'subject_uuid'  => $this->task->uuid,
            'occurred_at'   => now()->subDay(),
            'occurred_tz'   => 'Africa/Johannesburg',
            'actor_type'    => 'system',
            'payload'       => [],
        ], $attributes));
    }

    private function stored(string $metric = 'progress_pct'): ?DerivedValue
    {
        return DerivedValue::where('subject_type', 'task')
            ->where('subject_id', $this->task->id)
            ->where('metric', $metric)
            ->first();
    }

    public function test_measured_quantity_drives_progress_and_the_task_mirrors_it(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'unit' => 'm²', 'payload' => ['quantity' => 50]]);

        $this->derivation->deriveTask($this->task);

        $this->assertSame('25.0000', $this->stored()->value_numeric);
        // The list, the board and the timeline all read the mirror rather than the ledger.
        $this->assertSame('25.00', $this->task->fresh()->progress_pct);
        $this->assertSame('derived', $this->task->fresh()->progress_source);
    }

    public function test_a_typed_percentage_loses_to_the_tape_measure(): void
    {
        $this->record('progress.reported', ['payload' => ['percent' => 90]]);
        $this->record('quantity.installed', ['quantity' => 40, 'payload' => ['quantity' => 40]]);

        $this->derivation->deriveTask($this->task);

        $this->assertSame('20.0000', $this->stored()->value_numeric);
    }

    /** The drill-down: the figure names the exact events it was built from (PM-1300). */
    public function test_the_stored_figure_points_at_the_events_behind_it(): void
    {
        $this->record('quantity.installed', ['quantity' => 30, 'payload' => ['quantity' => 30]]);
        $this->record('photo.captured', ['payload' => ['caption' => 'east elevation']]);

        $this->derivation->deriveTask($this->task);

        $value = $this->stored();
        $behind = $this->derivation->evidenceBehind($value);

        $this->assertSame(1, $value->evidence_count);
        $this->assertCount(1, $behind);
        $this->assertSame('quantity.installed', $behind->first()->event_type);
    }

    public function test_figures_can_be_thrown_away_and_rebuilt_from_the_ledger(): void
    {
        $this->record('quantity.installed', ['quantity' => 60, 'payload' => ['quantity' => 60]]);
        $this->derivation->deriveTask($this->task);

        $before = $this->stored()->value_numeric;

        // The cache is disposable; the ledger is the record.
        DerivedValue::query()->forceDelete();
        $this->derivation->deriveTask($this->task->fresh());

        $this->assertSame($before, $this->stored()->value_numeric);
    }

    public function test_a_correction_removes_the_original_from_the_figure(): void
    {
        $this->record('quantity.installed', ['quantity' => 180, 'payload' => ['quantity' => 180]]);
        $this->derivation->deriveTask($this->task);
        $this->assertSame('90.0000', $this->stored()->value_numeric);

        $original = \App\Modules\Operations\ProjectManagement\Models\EvidenceEvent::first();

        app(EvidenceLedger::class)->supersede($original, [
            'event_type'    => 'quantity.installed',
            'source_module' => 'pm',
            'subject_type'  => 'task',
            'subject_id'    => $this->task->id,
            'subject_uuid'  => $this->task->uuid,
            'occurred_at'   => now(),
            'occurred_tz'   => 'Africa/Johannesburg',
            'actor_type'    => 'system',
            'quantity'      => 18,
            'payload'       => ['quantity' => 18, 'reason' => 'Decimal point.'],
        ]);

        $this->derivation->deriveTask($this->task->fresh());

        $this->assertSame('9.0000', $this->stored()->value_numeric);
    }

    // ── Overrides ────────────────────────────────────────────────────────────

    public function test_an_override_needs_a_reason(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        $this->derivation->deriveTask($this->task);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->derivation->override($this->stored(), 60, '   ');
    }

    /**
     * The point of an override: it is a decision, and recomputation must not quietly undo it. Every
     * night at 02:00 is exactly when a silent reversal would go unnoticed.
     */
    public function test_recomputing_does_not_overwrite_an_override(): void
    {
        $this->actingAs($this->manager);
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        $this->derivation->deriveTask($this->task);

        $this->derivation->override($this->stored(), 40, 'Survey found defective work; 20 m² to be redone.');

        $this->record('quantity.installed', ['quantity' => 30, 'payload' => ['quantity' => 30]]);
        $this->derivation->deriveTask($this->task->fresh());

        $value = $this->stored();

        $this->assertSame('40.0000', $value->value_numeric);
        $this->assertTrue($value->is_overridden);
        // The evidence underneath keeps moving, so the drill-down still shows what really happened.
        $this->assertSame(2, $value->evidence_count);
    }

    public function test_clearing_an_override_lets_the_evidence_speak_again(): void
    {
        $this->actingAs($this->manager);
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        $this->derivation->deriveTask($this->task);
        $this->derivation->override($this->stored(), 40, 'Defective work.');

        $this->derivation->clearOverride($this->stored());

        $this->assertSame('25.0000', $this->stored()->value_numeric);
        $this->assertFalse($this->stored()->is_overridden);
    }

    public function test_an_override_is_recorded_against_the_person_who_made_it(): void
    {
        $this->actingAs($this->manager);
        $this->record('progress.reported', ['payload' => ['percent' => 10]]);
        $this->derivation->deriveTask($this->task);

        $this->derivation->override($this->stored(), 75, 'Client walked the site and accepted.');

        $value = $this->stored();

        $this->assertSame($this->manager->id, (int) $value->override_by);
        $this->assertSame('Client walked the site and accepted.', $value->override_reason);
        $this->assertNotNull($value->overridden_at);
    }

    // ── Whole projects and reconciliation ────────────────────────────────────

    public function test_a_project_derives_its_leaves_and_rolls_them_into_summaries(): void
    {
        $parent = app(TaskService::class)->create($this->project, ['name' => 'Superstructure']);
        $childA = app(TaskService::class)->create($this->project, [
            'name' => 'Level 1', 'duration_minutes' => 600, 'planned_quantity' => 100,
        ], $parent);
        $childB = app(TaskService::class)->create($this->project, [
            'name' => 'Level 2', 'duration_minutes' => 600, 'planned_quantity' => 100,
        ], $parent);

        foreach ([[$childA, 100], [$childB, 0]] as [$task, $quantity]) {
            if ($quantity > 0) {
                app(EvidenceLedger::class)->append([
                    'project_id' => $this->project->id,
                    'event_type' => 'quantity.installed', 'source_module' => 'pm',
                    'subject_type' => 'task', 'subject_id' => $task->id, 'subject_uuid' => $task->uuid,
                    'occurred_at' => now()->subDay(), 'occurred_tz' => 'Africa/Johannesburg',
                    'actor_type' => 'system', 'quantity' => $quantity,
                    'payload' => ['quantity' => $quantity],
                ]);
            }
        }

        $result = $this->derivation->deriveProject($this->project);

        $this->assertSame('100.00', $childA->fresh()->progress_pct);
        // Half the work done, weighted by duration — the summary is not derived directly.
        $this->assertSame('50.00', $parent->fresh()->progress_pct);
        $this->assertGreaterThan(0, $result['figures']);
    }

    public function test_reconciliation_reports_drift_rather_than_hiding_it(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        $this->derivation->deriveTask($this->task);

        // Something wrote a figure the evidence does not support — the case reconciliation exists
        // to catch.
        $this->stored()->forceFill(['value_numeric' => 90])->save();

        $result = $this->derivation->reconcile($this->project);

        $this->assertNotEmpty($result['diverged']);
        $this->assertSame(90.0, $result['diverged'][0]['was']);
        $this->assertSame(25.0, $result['diverged'][0]['now']);
        // And it puts the figure right.
        $this->assertSame('25.0000', $this->stored()->value_numeric);
    }

    public function test_reconciliation_is_quiet_when_nothing_has_drifted(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        $this->derivation->deriveTask($this->task);

        $this->assertSame([], $this->derivation->reconcile($this->project)['diverged']);
    }

    public function test_reconciliation_leaves_overrides_alone(): void
    {
        $this->actingAs($this->manager);
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        $this->derivation->deriveTask($this->task);
        $this->derivation->override($this->stored(), 40, 'Defective work.');

        $result = $this->derivation->reconcile($this->project);

        // An override is not drift — it is a decision, and reporting it as divergence every night
        // would train everyone to ignore the report.
        $this->assertSame([], $result['diverged']);
        $this->assertSame('40.0000', $this->stored()->value_numeric);
    }

    public function test_the_method_version_is_stored_with_every_figure(): void
    {
        $this->record('task.started');
        $this->derivation->deriveTask($this->task);

        $this->assertSame(DerivationRules::VERSION, $this->stored()->method_version);
    }
}
