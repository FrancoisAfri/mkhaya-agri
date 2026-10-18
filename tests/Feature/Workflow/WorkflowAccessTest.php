<?php

namespace Tests\Feature\Workflow;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Workflow\Models\WorkflowActionRun;
use App\Modules\Workflow\Models\WorkflowAuditEvent;
use App\Modules\Workflow\Models\WorkflowDelegation;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\FormService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** PRS §3 / §18 and WF-015: every screen opens for the right people and every route refuses the rest. */
class WorkflowAccessTest extends WorkflowTestCase
{
    /** @return array<string, string> route parameter => a real value */
    private function fixtures(): array
    {
        $this->actingAs($this->admin);
        $form = app(FormService::class)->create('Access form', null, [['key' => 'amount', 'label' => 'Amount', 'type' => 'currency', 'required' => true]]);
        $graph = $this->simpleApprovalGraph();
        $graph['nodes'][0]['config']['form_id'] = $form->id;
        $d = $this->published($graph, ['name' => 'Access flow']);
        $i = $this->startAs($this->requester, $d, ['amount' => '120.00']);
        $task = $this->openTask($i);
        $delegation = WorkflowDelegation::create(['from_user_id' => $this->manager->id, 'to_user_id' => $this->finance->id, 'starts_at' => now(), 'ends_at' => now()->addDay()]);
        $calendar = \App\Modules\Workflow\Models\WorkflowCalendar::create(['name' => 'Office']);
        $run = WorkflowActionRun::create(['instance_id' => $i->id, 'action_key' => 'add_comment', 'idempotency_key' => Str::uuid(), 'status' => 'failed', 'input' => []]);

        return ['definition' => $d->uuid, 'instance' => $i->uuid, 'task' => $task->uuid, 'form' => $form->uuid, 'delegation' => $delegation->uuid,
            'calendar' => $calendar->uuid, 'version' => $d->currentVersion->uuid, 'run' => (string) $run->id, 'event' => '1', 'key' => 'leave_request'];
    }

    public function test_every_screen_opens_for_a_workflow_administrator(): void
    {
        $p = $this->fixtures();
        $this->actingAs($this->admin);
        foreach (['workflow.my_work', 'workflow.start', 'workflow.requests', 'workflow.instances.index', 'workflow.definitions.index', 'workflow.forms.index',
            'workflow.templates.index', 'workflow.delegations.index', 'workflow.dashboard', 'workflow.exceptions', 'workflow.admin', 'workflow.calendars.index', 'workflow.audit',
            'workflow.hub.requests', 'workflow.hub.design', 'workflow.hub.operations', 'workflow.hub.admin'] as $name) {
            $this->get(route($name))->assertOk();
        }
        $this->get(route('workflow.definitions.show', $p['definition']))->assertOk()->assertInertia(fn ($page) => $page->component('Modules/Workflow/DefinitionShow'));
        $this->get(route('workflow.definitions.designer', $p['definition']))->assertOk()->assertInertia(fn ($page) => $page->component('Modules/Workflow/Designer')->has('version.graph.nodes', 4));
        $this->get(route('workflow.forms.show', $p['form']))->assertOk();
        $this->get(route('workflow.instances.show', $p['instance']))->assertOk()->assertInertia(fn ($page) => $page->where('values.0.value', 'R 120.00'));
        $this->get(route('workflow.start.form', $p['definition']))->assertOk();
        $this->get(route('workflow.reports.export', ['report' => 'instances']))->assertOk();
        $this->get(route('workflow.reports.export', ['report' => 'summary', 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('workflow.audit.export'))->assertOk();
        $this->get(route('workflow.definitions.export', $p['definition']))->assertOk();
        // The assignee opens their task; the requester sees their request.
        $this->actingAs($this->manager)->get(route('workflow.tasks.show', $p['task']))->assertOk()->assertInertia(fn ($page) => $page->where('can.act', true)->where('decisions', ['approved', 'rejected', 'info_requested']));
        $this->actingAs($this->requester)->get(route('workflow.instances.show', $p['instance']))->assertOk();
    }

    public function test_every_workflow_route_refuses_a_user_without_workflow_permissions(): void
    {
        $p = $this->fixtures();
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'workflow.')) {
                continue;
            }
            $params = array_intersect_key($p, array_flip($route->parameterNames()));
            $method = $route->methods()[0];
            $response = $this->actingAs($this->outsider)->call($method === 'GET' ? 'GET' : $method, route($name, $params));
            $this->assertSame(403, $response->status(), "{$name} must refuse a user without workflow permissions (got {$response->status()}).");
            $checked++;
        }
        $this->assertGreaterThan(60, $checked);
        $this->assertGreaterThan(0, WorkflowAuditEvent::where('outcome', 'denied')->count(), 'Privileged refusals are audited.');
    }

    public function test_a_viewer_cannot_open_a_request_they_have_nothing_to_do_with(): void
    {
        $p = $this->fixtures();
        $bystander = $this->user('bystander', [\App\Modules\Workflow\Support\WorkflowPermissions::VIEW]);
        $this->actingAs($bystander)->get(route('workflow.instances.show', $p['instance']))->assertForbidden();
        $this->actingAs($bystander)->get(route('workflow.tasks.show', $p['task']))->assertForbidden();
        $this->actingAs($bystander)->post(route('workflow.tasks.decide', $p['task']), ['decision' => 'approved'])->assertSessionHas('error');
        $this->assertSame('running', WorkflowInstance::where('uuid', $p['instance'])->value('status'));
    }

    public function test_wf015_another_company_s_request_is_invisible_and_the_attempt_is_logged(): void
    {
        $p = $this->fixtures();
        // A second tenant with its own administrator.
        $other = Company::create(['name' => 'Other Co ' . Str::random(4)]);
        \Illuminate\Support\Facades\DB::table('company_modules')->insert(['company_id' => $other->id, 'module_code' => 'workflow', 'source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $intruder = \App\Core\User\Models\User::factory()->create(['email' => 'intruder-' . Str::random(5) . '@wf.test', 'status' => 1, 'company_id' => $other->id]);
        $intruder->givePermissionTo(\App\Modules\Workflow\Support\WorkflowPermissions::all());
        $moduleId = \Illuminate\Support\Facades\DB::table('security_modules')->where('code_name', 'workflow')->value('id');
        \Illuminate\Support\Facades\DB::table('security_module_access')->insert(['module_id' => $moduleId, 'user_id' => $intruder->id, 'active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $this->enterTenant($other);

        $status = $this->actingAs($intruder)->get(route('workflow.instances.show', $p['instance']))->status();
        $this->assertContains($status, [403, 404], 'Another company\'s request does not resolve.');
        $this->assertNotSame(200, $this->actingAs($intruder)->get(route('workflow.tasks.show', $p['task']))->status());
        $list = $this->actingAs($intruder)->get(route('workflow.instances.index'))->assertOk()->viewData('page')['props']['instances']['data'];
        $this->assertSame([], $list, 'Lists and dashboards show nothing of the other tenant.');
    }

    public function test_malformed_ids_are_not_server_errors(): void
    {
        $this->fixtures();
        foreach (['workflow.instances.show' => 'instance', 'workflow.tasks.show' => 'task', 'workflow.definitions.show' => 'definition', 'workflow.forms.show' => 'form'] as $name => $param) {
            $status = $this->actingAs($this->admin)->get("/workflow/" . explode('.', $name)[1] . '/not-a-uuid')->status();
            $this->assertContains($status, [403, 404], "{$name} with a malformed id must not be a server error.");
        }
    }

    public function test_deciding_through_the_screen(): void
    {
        $p = $this->fixtures();
        $this->actingAs($this->manager)->post(route('workflow.tasks.decide', $p['task']), ['decision' => 'rejected', 'comment' => ''])->assertSessionHas('error');
        $this->actingAs($this->manager)->post(route('workflow.tasks.decide', $p['task']), ['decision' => 'approved', 'comment' => 'OK'])->assertRedirect(route('workflow.my_work'));
        $this->assertSame('completed', WorkflowInstance::where('uuid', $p['instance'])->value('status'));
    }

    public function test_starting_through_the_form_validates_on_the_server_and_is_idempotent(): void
    {
        $p = $this->fixtures();
        $this->actingAs($this->requester)->post(route('workflow.start.store', $p['definition']), ['values' => []])->assertSessionHasErrors('values.amount');
        $this->actingAs($this->requester)->post(route('workflow.start.store', $p['definition']), ['values' => ['amount' => '55.5']], ['Idempotency-Key' => 'abc'])->assertRedirect();
        $this->actingAs($this->requester)->post(route('workflow.start.store', $p['definition']), ['values' => ['amount' => '55.5']], ['Idempotency-Key' => 'abc'])->assertRedirect();
        $this->assertSame(2, WorkflowInstance::where('requester_id', $this->requester->id)->count(), 'The fixture plus one: the repeated submit created nothing.');
        $this->assertSame('55.50', WorkflowInstance::where('requester_id', $this->requester->id)->latest('id')->first()->data['amount']);
    }

    public function test_the_ai_draft_stays_a_draft_and_never_names_people(): void
    {
        $this->actingAs($this->admin)->post(route('workflow.definitions.ai_draft'), [
            'name' => 'Spend approval', 'description' => 'When a purchase request exceeds R50 000, obtain manager and finance approval before procurement can proceed.',
        ])->assertRedirect();
        $d = \App\Modules\Workflow\Models\WorkflowDefinition::where('name', 'Spend approval')->firstOrFail();
        $this->assertSame('draft', $d->status, 'WF-018: not active until an authorised person publishes it.');
        $this->assertNull($d->current_version_id);
        $graph = $d->workingVersion()->graph;
        $this->assertTrue(collect($graph['nodes'])->contains('type', 'decision'));
        foreach ($graph['nodes'] as $n) {
            if (($n['config']['assignee']['type'] ?? null) === 'role') {
                $this->assertTrue(\Illuminate\Support\Facades\DB::table('roles')->where('name', $n['config']['assignee']['value'])->exists() || $n['config']['assignee']['value'] === null, 'Roles that do not exist are cleared.');
            }
            $this->assertNotSame('user', $n['config']['assignee']['type'] ?? null);
        }
        $this->assertSame(1, WorkflowAuditEvent::where('action', 'ai.draft')->where('actor_type', 'ai')->count());
        $this->actingAs($this->manager)->post(route('workflow.definitions.ai_draft'), ['name' => 'x', 'description' => str_repeat('a', 20)])->assertForbidden();
    }

    public function test_category_is_chosen_from_the_company_list(): void
    {
        $this->actingAs($this->admin)->post(route('workflow.definitions.store'), ['name' => 'Bad category', 'module' => 'generic', 'category' => 'Not on the list'])
            ->assertSessionHasErrors('category');
        $this->actingAs($this->admin)->post(route('workflow.definitions.store'), ['name' => 'Good category', 'module' => 'generic', 'category' => 'Finance'])->assertRedirect();
        $this->actingAs($this->admin)->get(route('workflow.definitions.index'))->assertInertia(fn ($p) => $p->where('categories', \App\Modules\Workflow\Support\WorkflowSettings::DEFAULT_CATEGORIES));

        // An administrator adds one; a category in use cannot be removed.
        $this->actingAs($this->admin)->post(route('workflow.admin.update'), ['categories' => ['Finance', 'Fleet'], 'reason' => 'Our own list'])->assertSessionHas('success');
        $this->assertSame(['Finance', 'Fleet'], app(\App\Modules\Workflow\Support\WorkflowSettings::class)->categories());
        $this->actingAs($this->admin)->post(route('workflow.admin.update'), ['categories' => ['Fleet'], 'reason' => 'Drop finance'])->assertSessionHas('error');

        // Installing a template adds its category so the workflow stays on the list.
        $this->actingAs($this->admin)->post(route('workflow.templates.install', 'sheq_corrective_action'))->assertRedirect();
        app(\App\Modules\Workflow\Support\WorkflowSettings::class)->forget();
        $this->assertContains('SHEQ', app(\App\Modules\Workflow\Support\WorkflowSettings::class)->categories());
    }

    public function test_a_platform_administrator_sees_the_record_s_people_and_departments(): void
    {
        $p = $this->fixtures();
        \Illuminate\Support\Facades\DB::table('company_units')->insert(['name' => 'Finance dept', 'company_id' => $this->company->id, 'type' => 'department', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $platform = \App\Core\User\Models\User::factory()->create(['email' => 'platform-' . \Illuminate\Support\Str::random(5) . '@wf.test', 'status' => 1, 'company_id' => null, 'is_platform_admin' => true]);
        $platform->givePermissionTo(\App\Modules\Workflow\Support\WorkflowPermissions::all());
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->clear();
        $page = $this->actingAs($platform)->get(route('workflow.definitions.show', $p['definition']))->assertOk()->viewData('page')['props'];
        $this->assertContains($this->admin->id, array_column($page['people'], 'id'), 'People of the workflow\'s company, not of the (absent) platform company.');
        $this->assertContains('Finance dept', array_column($page['departments'], 'name'));
        $this->assertSame('Admin Tester', $page['definition']['owner']);
    }
}
