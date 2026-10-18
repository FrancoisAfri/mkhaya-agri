<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\DerivedValue;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\DerivationService;
use App\Modules\Operations\ProjectManagement\Services\EvidenceLedger;
use App\Modules\Operations\ProjectManagement\Services\TaskService;

/**
 * The drill-down over HTTP (PM-1300, PM-1301).
 *
 * The question this page exists to answer is "why does this say 25%?", and the answer has to be a
 * list of events a quantity surveyor can argue with — not a number and an assurance.
 */
class FiguresPageTest extends ProjectManagementTestCase
{
    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        $this->task = app(TaskService::class)->create($this->project, [
            'name' => 'Lay blockwork', 'planned_quantity' => 200,
        ]);
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

    private function figure(): DerivedValue
    {
        return DerivedValue::where('subject_id', $this->task->id)->where('metric', 'progress_pct')->firstOrFail();
    }

    public function test_the_page_lists_figures_with_their_confidence_and_the_rules_behind_them(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        app(DerivationService::class)->deriveTask($this->task);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.figures', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        $progress = collect($props['figures'])->firstWhere('metric', 'progress_pct');

        $this->assertSame(25.0, $progress['value']);
        $this->assertSame(0.95, $progress['confidence']);
        $this->assertSame(1, $progress['count']);
        // The rules travel with the page, so precedence can be read rather than taken on trust.
        $this->assertNotEmpty($props['rules']['progress_pct']);
        $this->assertSame('verified', $props['rules']['progress_pct'][0]['kind']);
    }

    public function test_the_drill_down_shows_the_events_behind_the_number(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        $this->record('photo.captured', ['payload' => ['caption' => 'east elevation']]);
        app(DerivationService::class)->deriveTask($this->task);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.figures.show', [$this->project->uuid, $this->figure()->uuid]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertCount(1, $props['evidence']);
        $this->assertSame('quantity.installed', $props['evidence'][0]['type']);
        $this->assertSame(50.0, $props['evidence'][0]['quantity']);
    }

    public function test_figures_can_be_recalculated_on_demand(): void
    {
        $this->record('quantity.installed', ['quantity' => 100, 'payload' => ['quantity' => 100]]);

        $this->actingAs($this->manager)
            ->post(route('project-management.figures.recalculate', $this->project->uuid))
            ->assertRedirect();

        $this->assertSame('50.0000', $this->figure()->value_numeric);
    }

    // ── Overriding ───────────────────────────────────────────────────────────

    public function test_an_override_demands_a_real_reason(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        app(DerivationService::class)->deriveTask($this->task);

        // "n/a" is not an explanation, and this field exists to make someone account for a number
        // the evidence does not support.
        $this->actingAs($this->manager)
            ->post(route('project-management.figures.override', [$this->project->uuid, $this->figure()->uuid]), [
                'value' => 80, 'reason' => 'n/a',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($this->figure()->is_overridden);
    }

    public function test_an_override_is_stored_with_who_and_why(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        app(DerivationService::class)->deriveTask($this->task);

        $this->actingAs($this->manager)
            ->post(route('project-management.figures.override', [$this->project->uuid, $this->figure()->uuid]), [
                'value' => 60, 'reason' => 'Client accepted the partial handover on 4 March.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $figure = $this->figure();

        $this->assertTrue($figure->is_overridden);
        $this->assertSame('60.0000', $figure->value_numeric);
        $this->assertSame($this->manager->id, (int) $figure->override_by);
    }

    public function test_an_override_can_be_withdrawn(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        app(DerivationService::class)->deriveTask($this->task);

        $this->actingAs($this->manager)
            ->post(route('project-management.figures.override', [$this->project->uuid, $this->figure()->uuid]), [
                'value' => 60, 'reason' => 'Client accepted the partial handover on 4 March.',
            ]);

        $this->actingAs($this->manager)
            ->delete(route('project-management.figures.override.clear', [$this->project->uuid, $this->figure()->uuid]))
            ->assertRedirect();

        $this->assertFalse($this->figure()->is_overridden);
        $this->assertSame('25.0000', $this->figure()->value_numeric);
    }

    public function test_only_someone_who_can_change_the_project_may_override(): void
    {
        $this->record('quantity.installed', ['quantity' => 50, 'payload' => ['quantity' => 50]]);
        app(DerivationService::class)->deriveTask($this->task);

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.figures', $this->project->uuid))
            ->assertOk();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.figures.override', [$this->project->uuid, $this->figure()->uuid]), [
                'value' => 100, 'reason' => 'Because I say it is finished.',
            ])
            ->assertForbidden();

        $this->assertFalse($this->figure()->is_overridden);
    }

    public function test_a_figure_from_another_project_is_not_reachable(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER']);
        $theirTask = app(TaskService::class)->create($other, ['name' => 'Their work', 'planned_quantity' => 10]);

        app(EvidenceLedger::class)->append([
            'project_id' => $other->id, 'event_type' => 'quantity.installed', 'source_module' => 'pm',
            'subject_type' => 'task', 'subject_id' => $theirTask->id, 'subject_uuid' => $theirTask->uuid,
            'occurred_at' => now()->subDay(), 'occurred_tz' => 'Africa/Johannesburg',
            'actor_type' => 'system', 'quantity' => 5, 'payload' => ['quantity' => 5],
        ]);

        app(DerivationService::class)->deriveTask($theirTask);

        $theirs = DerivedValue::where('subject_id', $theirTask->id)->firstOrFail();

        $this->actingAs($this->manager)
            ->get(route('project-management.figures.show', [$this->project->uuid, $theirs->uuid]))
            ->assertNotFound();
    }
}
