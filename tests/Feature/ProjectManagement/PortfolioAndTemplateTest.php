<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Portfolio;
use App\Modules\Operations\ProjectManagement\Models\Programme;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectTemplate;
use App\Modules\Operations\ProjectManagement\Models\Risk;
use App\Modules\Operations\ProjectManagement\Models\StageGate;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\WbsNode;
use App\Modules\Operations\ProjectManagement\Services\TemplateService;

/**
 * Portfolios, programmes, templates and stage gates (§2, §3).
 *
 * The two things worth guarding here are both quiet failures: a rollup that double-counts (or
 * silently omits) a project, and a template that produces dates in the past because it stored
 * calendar dates instead of offsets.
 */
class PortfolioAndTemplateTest extends ProjectManagementTestCase
{
    private TemplateService $templates;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templates = app(TemplateService::class);

        // The manager fixture deliberately lacks `pm.settings.manage` — that is what
        // ProjectAuthorizationTest asserts. The template library lives behind it, so this suite
        // grants it rather than weakening the fixture other suites depend on.
        $this->manager->givePermissionTo(\App\Modules\Operations\ProjectManagement\Support\RoleCatalog::MANAGE_SETTINGS);
        $this->manager->forgetCachedPermissions();

        $this->actingAs($this->manager);
    }

    // ── Hierarchy ────────────────────────────────────────────────────────────

    public function test_a_project_in_a_programme_takes_that_programmes_portfolio(): void
    {
        $portfolio = Portfolio::create(['name' => 'Capital works']);
        $programme = Programme::create(['name' => 'Hospital', 'portfolio_id' => $portfolio->id]);
        $project = $this->makeProject();

        // Deliberately naming a *different* portfolio: the programme has to win, or the rollup has
        // two answers to "which portfolio" and stops adding up.
        $other = Portfolio::create(['name' => 'Maintenance']);

        $this->post(route('project-management.projects.assign', $project->uuid), [
            'portfolio_id' => $other->uuid,
            'programme_id' => $programme->uuid,
        ])->assertRedirect();

        $project->refresh();

        $this->assertSame($programme->id, $project->program_id);
        $this->assertSame($portfolio->id, $project->portfolio_id);
    }

    public function test_the_rollup_counts_each_project_once(): void
    {
        $portfolio = Portfolio::create(['name' => 'Capital works', 'budget_amount' => 10000000]);
        $programme = Programme::create(['name' => 'Hospital', 'portfolio_id' => $portfolio->id]);

        // One under the programme, one straight off the portfolio.
        $this->makeProject(['code' => 'PRJ-A', 'budget_amount' => 400000])
            ->update(['portfolio_id' => $portfolio->id, 'program_id' => $programme->id]);
        $this->makeProject(['code' => 'PRJ-B', 'budget_amount' => 600000])
            ->update(['portfolio_id' => $portfolio->id]);

        $props = $this->get(route('project-management.portfolio'))->assertOk()
            ->viewData('page')['props'];

        $pf = $props['portfolios'][0];

        // 400k under the programme plus 600k direct. Counting the programme's project at both
        // levels would read 1.4m and nobody would notice until the year end.
        $this->assertSame(1000000.0, $pf['total']);
        $this->assertCount(1, $pf['projects']);
        $this->assertCount(1, $pf['programmes'][0]['projects']);
    }

    public function test_projects_filed_nowhere_are_still_shown(): void
    {
        $this->makeProject(['code' => 'PRJ-LOOSE']);

        $props = $this->get(route('project-management.portfolio'))->assertOk()->viewData('page')['props'];

        // A rollup that silently omits eleven projects is a rollup nobody should trust.
        $this->assertNotEmpty($props['unassigned']);
    }

    // ── Templates ────────────────────────────────────────────────────────────

    public function test_a_template_produces_dates_relative_to_the_project_start(): void
    {
        $project = $this->makeProject(['planned_start' => now()->addMonths(2)->toDateString()]);

        $template = ProjectTemplate::create([
            'name'    => 'Test plan',
            'payload' => ['tasks' => [
                ['name' => 'Kick-off', 'start_offset_days' => 0, 'duration_days' => 1],
                ['name' => 'Build', 'start_offset_days' => 10, 'duration_days' => 20],
            ]],
        ]);

        $this->templates->apply($project, $template);

        $kickoff = Task::where('project_id', $project->id)->where('name', 'Kick-off')->firstOrFail();
        $build = Task::where('project_id', $project->id)->where('name', 'Build')->firstOrFail();

        // The whole reason offsets are stored instead of dates: a template written in January must
        // not produce January dates on a job starting in March.
        $this->assertTrue($kickoff->planned_start->isFuture());
        $this->assertSame($project->planned_start->toDateString(), $kickoff->planned_start->toDateString());
        $this->assertSame(
            $project->planned_start->copy()->addDays(29)->toDateString(),
            $build->planned_end->toDateString(),
        );
    }

    public function test_a_template_lays_out_the_whole_plan(): void
    {
        $project = $this->makeProject(['planned_start' => now()->toDateString()]);

        $template = ProjectTemplate::create([
            'name'    => 'Full plan',
            'payload' => [
                'wbs'   => [
                    ['key' => 'a', 'name' => 'Design', 'node_type' => 'phase'],
                    ['key' => 'b', 'name' => 'Detail', 'parent' => 'a'],
                ],
                'tasks' => [['name' => 'Drawings', 'wbs' => 'b', 'start_offset_days' => 0, 'duration_days' => 5]],
                'gates' => [['stage' => 'planning', 'name' => 'Design freeze', 'criteria' => 'Drawings issued']],
                'risks' => [['title' => 'Late approvals', 'category' => 'legal', 'probability' => 4, 'impact' => 4]],
            ],
        ]);

        $counts = $this->templates->apply($project, $template);

        $this->assertSame(['wbs' => 2, 'tasks' => 1, 'gates' => 1, 'risks' => 1], $counts);
        $this->assertSame(2, WbsNode::where('project_id', $project->id)->count());
        $this->assertSame(1, StageGate::where('project_id', $project->id)->count());
        $this->assertSame(1, Risk::where('project_id', $project->id)->count());

        // The child node really is under its parent, not a second root.
        $child = WbsNode::where('project_id', $project->id)->where('name', 'Detail')->firstOrFail();
        $this->assertNotNull($child->parent_id);

        // And usage is counted, so a library nobody uses is visible rather than assumed useful.
        $this->assertSame(1, $template->fresh()->times_used);
        $this->assertSame($template->id, $project->fresh()->template_id);
    }

    public function test_capturing_a_project_stores_offsets_not_dates(): void
    {
        $project = $this->makeProject(['planned_start' => now()->toDateString()]);

        app(\App\Modules\Operations\ProjectManagement\Services\TaskService::class)->create($project, [
            'name'          => 'Foundations',
            'planned_start' => now()->addDays(10)->toDateString(),
            'planned_end'   => now()->addDays(19)->toDateString(),
        ]);

        $template = $this->templates->captureFrom($project, ['name' => 'Captured', 'industry' => 'construction']);

        $task = $template->section('tasks')[0];

        $this->assertSame(10, $task['start_offset_days']);
        $this->assertSame(10, $task['duration_days']);
        // No calendar date anywhere in the payload, or the template is wrong the day after it is
        // written.
        $this->assertStringNotContainsString(now()->format('Y-m'), json_encode($template->payload));
    }

    public function test_the_shipped_library_is_hidden_rather_than_deleted(): void
    {
        $builtin = ProjectTemplate::create(['name' => 'Shipped', 'is_builtin' => true]);

        $this->delete(route('project-management.setup.templates.destroy', $builtin->uuid))->assertRedirect();

        // A delete would come back on the next upgrade, and the tenant would remove it twice.
        $this->assertDatabaseHas('pm_project_templates', ['id' => $builtin->id, 'is_active' => false, 'deleted_at' => null]);
    }

    // ── Stage gates ──────────────────────────────────────────────────────────

    public function test_a_waived_gate_has_to_say_why(): void
    {
        $project = $this->makeProject();
        $gate = StageGate::create(['project_id' => $project->id, 'stage' => 'planning', 'name' => 'Design freeze']);

        $this->post(route('project-management.gates.decide', [$project->uuid, $gate->uuid]), [
            'status' => 'waived',
        ])->assertSessionHasErrors('note');

        $this->assertSame('pending', $gate->fresh()->status);
    }

    public function test_a_gate_records_who_decided_it_and_when(): void
    {
        $project = $this->makeProject();
        $gate = StageGate::create(['project_id' => $project->id, 'stage' => 'planning', 'name' => 'Design freeze']);

        $this->post(route('project-management.gates.decide', [$project->uuid, $gate->uuid]), [
            'status' => 'passed', 'note' => 'Drawings issued for construction.',
        ])->assertRedirect();

        $gate->refresh();

        $this->assertSame('passed', $gate->status);
        $this->assertSame($this->manager->id, $gate->decided_by);
        $this->assertNotNull($gate->decided_at);
    }

    public function test_a_gate_on_another_project_does_not_resolve(): void
    {
        $mine = $this->makeProject();
        $theirs = $this->makeProject(['code' => 'PRJ-OTHER']);
        $gate = StageGate::create(['project_id' => $theirs->id, 'stage' => 'planning', 'name' => 'Theirs']);

        $this->post(route('project-management.gates.decide', [$mine->uuid, $gate->uuid]), [
            'status' => 'passed',
        ])->assertNotFound();
    }

    public function test_the_template_page_renders(): void
    {
        ProjectTemplate::create(['name' => 'Something', 'industry' => 'it']);

        $this->get(route('project-management.setup.templates'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/ProjectManagement/Setup/Templates')
                ->has('templates'));
    }
}
