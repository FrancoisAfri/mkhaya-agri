<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The approval-workflow designer, rebuilt in the shape of the Project Management one.
 *
 * The screen it replaced could only *create*. There was no way to load an existing chain back into
 * the form, so correcting a step meant retyping the whole thing, and no way to take a chain out of
 * use at all — which is how a company ends up with four workflows for permits and no idea which one
 * fires.
 *
 * What is guarded here is the promise that makes editing safe: saving never mutates a live chain,
 * it retires the current version and writes the next one, so a request halfway through its
 * approvals keeps the steps it opened under. Retiring works the same way, which is why it is not a
 * delete.
 */
class ApprovalWorkflowSetupTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');

        $id = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'flows-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_module_access')->insert([
            'module_id' => $module->id, 'user_id' => $id, 'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Permission::findOrCreate('sheq.configure', 'web');
        $user = User::withoutGlobalScopes()->findOrFail($id);
        $user->syncPermissions(['sheq.configure']);

        $this->actingAs($user->fresh());
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'subject_type' => 'PERMIT',
            'name' => 'Hot work permit',
            'sla_hours' => 8,
            'steps' => [
                ['name' => 'Supervisor', 'approver_type' => 'LINE_MANAGER', 'quorum' => 1],
            ],
        ], $overrides);
    }

    private function active(string $subject = 'PERMIT'): ?object
    {
        return DB::table('sheq_approval_workflows')
            ->where('company_id', $this->companyId)->where('subject_type', $subject)
            ->where('is_active', true)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'name', 'version']);
    }

    public function test_saving_over_an_existing_chain_versions_it_rather_than_editing_in_place(): void
    {
        /*
         * The property the whole designer rests on. Editing a live chain under somebody's feet is
         * how an approval acquires a step nobody remembers agreeing to; instead the old version is
         * retired and a new one written, and in-flight requests keep pointing at the old one.
         */
        $this->post('/sheq/approvals/workflows', $this->payload())->assertRedirect();
        $first = $this->active();

        $this->post('/sheq/approvals/workflows', $this->payload([
            'name' => 'Hot work permit (revised)',
            'steps' => [
                ['name' => 'Supervisor', 'approver_type' => 'LINE_MANAGER', 'quorum' => 1],
                ['name' => 'SHEQ manager', 'approver_type' => 'ANY_APPROVER', 'quorum' => 1],
            ],
        ]))->assertRedirect();

        $second = $this->active();

        $this->assertNotSame($first->id, $second->id, 'The chain was edited in place.');
        $this->assertSame($first->version + 1, $second->version);
        $this->assertSame('Hot work permit (revised)', $second->name);

        // The old version survives, so anything already pointing at it still resolves.
        $old = DB::table('sheq_approval_workflows')->where('id', $first->id)->first(['is_active']);
        $this->assertFalse((bool) $old->is_active);
        $this->assertNull(DB::table('sheq_approval_workflows')->where('id', $first->id)->value('deleted_at'));
    }

    public function test_the_new_version_carries_the_edited_steps(): void
    {
        $this->post('/sheq/approvals/workflows', $this->payload())->assertRedirect();
        $this->post('/sheq/approvals/workflows', $this->payload([
            'steps' => [
                ['name' => 'Supervisor', 'approver_type' => 'LINE_MANAGER', 'quorum' => 1],
                ['name' => 'Safety officer', 'approver_type' => 'ANY_APPROVER', 'quorum' => 2],
            ],
        ]))->assertRedirect();

        $steps = DB::table('sheq_approval_steps')
            ->where('workflow_id', $this->active()->id)->orderBy('position')
            ->get(['name', 'quorum']);

        $this->assertCount(2, $steps);
        $this->assertSame('Safety officer', $steps[1]->name);
        $this->assertSame(2, (int) $steps[1]->quorum);
    }

    public function test_retiring_takes_a_chain_out_of_use_without_deleting_it(): void
    {
        // Deleting would orphan any request already inside the chain — an approval nobody can
        // finish and nobody can explain.
        $this->post('/sheq/approvals/workflows', $this->payload())->assertRedirect();
        $workflow = $this->active();

        $this->delete("/sheq/approvals/workflows/{$workflow->uuid}")->assertRedirect();

        $row = DB::table('sheq_approval_workflows')->where('id', $workflow->id)
            ->first(['is_active', 'deleted_at']);

        $this->assertFalse((bool) $row->is_active);
        $this->assertNull($row->deleted_at, 'The workflow was deleted rather than retired.');
        $this->assertNull($this->active(), 'A retired workflow is still being offered.');
    }

    public function test_retiring_needs_the_configure_entitlement(): void
    {
        $this->post('/sheq/approvals/workflows', $this->payload())->assertRedirect();
        $workflow = $this->active();

        $viewer = User::withoutGlobalScopes()->findOrFail(
            DB::table('users')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'email' => 'viewer-'.Str::random(8).'@example.test',
                'password' => bcrypt('secret'), 'company_id' => $this->companyId,
                'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ])
        );

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');
        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $viewer->id, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($viewer);

        $this->delete("/sheq/approvals/workflows/{$workflow->uuid}")->assertForbidden();
    }

    public function test_another_companys_workflow_cannot_be_retired(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Flow Fixture Co',
            'slug' => 'flow-'.Str::random(6), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) Str::uuid();
        DB::table('sheq_approval_workflows')->insert([
            'uuid' => $uuid, 'company_id' => $other, 'subject_type' => 'PERMIT',
            'name' => 'Theirs', 'version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->delete("/sheq/approvals/workflows/{$uuid}")->assertNotFound();

        $this->assertTrue((bool) DB::table('sheq_approval_workflows')->where('uuid', $uuid)->value('is_active'));
    }

    public function test_the_page_sends_every_subject_type_so_each_gets_a_card(): void
    {
        // The screen renders one card per subject type, including the empty ones — a gap you can
        // see is a gap somebody fills.
        $this->get('/sheq/approvals/workflows')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Sheq/Approvals/Workflows')
                ->has('subjectTypes', 10)
                ->has('workflows')
                ->has('users')
                ->where('canManage', true));
    }
}
