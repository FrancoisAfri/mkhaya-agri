<?php

namespace Tests\Feature\Workflow;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Models\WorkflowInstance;
use App\Modules\Workflow\Models\WorkflowTask;
use App\Modules\Workflow\Services\DefinitionService;
use App\Modules\Workflow\Services\TaskService;
use App\Modules\Workflow\Services\WorkflowRunner;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fixtures for the workflow acceptance matrix (PRS §24): a tenant with an administrator/designer,
 * a publisher, a requester with a line manager, a second approver and an outsider.
 */
abstract class WorkflowTestCase extends TestCase
{
    use DatabaseTransactions;

    protected Company $company;

    protected User $admin;

    protected User $publisher;

    protected User $requester;

    protected User $manager;

    protected User $finance;

    protected User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        foreach (WorkflowPermissions::all() as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->company = Company::create(['name' => 'Workflow Test ' . Str::random(5)]);
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => 'workflow'],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('module_active_workflow');
        $this->enterTenant();

        $this->admin = $this->user('admin', [WorkflowPermissions::VIEW, WorkflowPermissions::START, WorkflowPermissions::DESIGN, WorkflowPermissions::MANAGE,
            WorkflowPermissions::REPORTS, WorkflowPermissions::AUDIT, WorkflowPermissions::ADMIN]);
        $this->publisher = $this->user('publisher', [WorkflowPermissions::VIEW, WorkflowPermissions::PUBLISH]);
        $this->manager = $this->user('manager', [WorkflowPermissions::VIEW, WorkflowPermissions::START]);
        $this->finance = $this->user('finance', [WorkflowPermissions::VIEW, WorkflowPermissions::START]);
        $this->requester = $this->user('requester', [WorkflowPermissions::VIEW, WorkflowPermissions::START], managerOf: null);
        $this->outsider = $this->user('outsider', []);
        $this->setManager($this->requester, $this->manager);
        app(WorkflowSettings::class)->forget();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    protected function enterTenant(?Company $company = null): void
    {
        $company ??= $this->company;
        app(CurrentCompany::class)->set($company->id);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $company->id]);
    }

    protected function user(string $name, array $permissions, mixed $managerOf = null): User
    {
        $user = User::factory()->create(['email' => "{$name}-" . Str::random(6) . '@wf.test', 'status' => 1, 'company_id' => $this->company->id]);
        $user->givePermissionTo($permissions);
        DB::table('hr_people')->insert(['user_id' => $user->id, 'first_name' => ucfirst($name), 'surname' => 'Tester', 'email' => $user->email,
            'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $moduleId = DB::table('security_modules')->where('code_name', 'workflow')->value('id');
        DB::table('security_module_access')->updateOrInsert(['module_id' => $moduleId, 'user_id' => $user->id],
            ['active' => 1, 'access_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('hub_module_id:workflow');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function setManager(User $employee, User $manager): void
    {
        $managerPerson = DB::table('hr_people')->where('user_id', $manager->id)->value('id');
        DB::table('hr_people')->where('user_id', $employee->id)->update(['manager_id' => $managerPerson]);
    }

    protected function settings(array $values): void
    {
        $this->actingAs($this->admin);
        app(WorkflowSettings::class)->update($values, 'Test fixture');
        app(WorkflowSettings::class)->forget();
    }

    /** Build a graph: nodes as [key, type, name, config], edges as [from, to, extra]. */
    protected function graph(array $nodes, array $edges): array
    {
        $i = 0;

        return [
            'nodes' => array_map(fn ($n) => ['key' => $n[0], 'type' => $n[1], 'name' => $n[2] ?? $n[0], 'x' => 100 * $i++, 'y' => 100, 'config' => $n[3] ?? []], $nodes),
            'edges' => array_map(function ($e) use (&$i) {
                return array_merge(['key' => 'e' . ($i++), 'from' => $e[0], 'to' => $e[1]], $e[2] ?? []);
            }, $edges),
        ];
    }

    /** Manager approval: approve → end (completed), reject → end (rejected). */
    protected function simpleApprovalGraph(array $approval = []): array
    {
        return $this->graph([
            ['start', 'start', 'Start'],
            ['mgr', 'approval', 'Manager approval', array_merge(['assignee' => ['type' => 'line_manager'], 'mode' => 'any'], $approval)],
            ['ok', 'end', 'Approved', ['outcome' => 'completed']],
            ['no', 'end', 'Rejected', ['outcome' => 'rejected']],
        ], [['start', 'mgr'], ['mgr', 'ok', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']]]);
    }

    /** Create and publish a workflow (no review step unless the test turns it on). */
    protected function published(array $graph, array $attrs = []): WorkflowDefinition
    {
        $this->settings(['publication_requires_review' => false]);
        $this->actingAs($this->admin);
        $defs = app(DefinitionService::class);
        $d = $defs->create(array_merge(['name' => 'Test flow ' . Str::random(4), 'category' => 'Test', 'process_owner_id' => $this->admin->id], $attrs), $graph);
        $defs->submit($d, $this->admin, 'Initial version');

        return $d->refresh();
    }

    protected function startAs(User $user, WorkflowDefinition $d, array $data = [], ?string $dedupe = null): WorkflowInstance
    {
        $this->actingAs($user);

        return app(WorkflowRunner::class)->start($d, $data, $user->id, [], $dedupe);
    }

    protected function openTask(WorkflowInstance $i, ?string $node = null, ?User $assignee = null): WorkflowTask
    {
        return WorkflowTask::query()->where('instance_id', $i->id)->where('status', 'open')
            ->when($node, fn ($q) => $q->where('node_key', $node))->when($assignee, fn ($q) => $q->where('assignee_id', $assignee->id))
            ->orderBy('id')->firstOrFail();
    }

    protected function decide(User $user, WorkflowTask $task, string $decision, ?string $comment = null, array $values = []): WorkflowTask
    {
        $this->actingAs($user);

        return app(TaskService::class)->decide($task, $user, $decision, $comment, $values);
    }
}
