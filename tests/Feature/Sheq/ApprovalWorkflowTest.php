<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Company-designed approval workflows.
 *
 * The tests that matter here are the refusals. An approval engine that approves things is easy; one
 * that reliably refuses to let the requester sign their own permit, refuses to run when nobody has
 * configured who may authorise, and refuses to let a lapsed appointment confer authority is the
 * thing worth having.
 */
class ApprovalWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $requesterId;

    private int $approverId;

    private string $roleName;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->requesterId = $this->user();
        $this->approverId = $this->user();

        $this->roleName = 'sheq-approver-'.Str::random(6);
        Role::create(['name' => $this->roleName, 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function user(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'appr-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function giveRole(int $userId, ?string $role = null): void
    {
        User::withoutGlobalScopes()->findOrFail($userId)
            ->assignRole($role ?? $this->roleName);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<array<string,mixed>>  $steps
     */
    private function workflow(array $steps, array $overrides = []): int
    {
        $workflowId = DB::table('sheq_approval_workflows')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'subject_type' => 'PERMIT', 'subject_subtype' => null, 'site_id' => null,
            'name' => 'Permit approval', 'version' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        foreach ($steps as $index => $step) {
            DB::table('sheq_approval_steps')->insert(array_merge([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'workflow_id' => $workflowId,
                'position' => $index + 1,
                'name' => 'Step '.($index + 1),
                'approver_type' => 'ROLE', 'approver_ref' => $this->roleName,
                'quorum' => 1, 'is_optional' => false, 'requires_signature' => false,
                'created_at' => now(), 'updated_at' => now(),
            ], $step));
        }

        return $workflowId;
    }

    private function open(array $overrides = []): array
    {
        return app(ApprovalService::class)->open($this->companyId, array_merge([
            'subject_type' => 'PERMIT', 'subject_id' => 4242,
            'subject_label' => 'PTW-2026-0031', 'requested_by' => $this->requesterId,
        ], $overrides));
    }

    // ── The refusals ──────────────────────────────────────────────────────────────────────────

    public function test_the_requester_can_never_approve_their_own_request(): void
    {
        $this->workflow([[]]);
        $this->giveRole($this->requesterId);

        $request = $this->open();

        // Deliberately given the approving role. Holding it must still not be enough, because the
        // point of an approval is that a second person looked.
        $this->expectExceptionMessageMatches('/cannot approve it/');

        app(ApprovalService::class)->decide($this->companyId, $request['uuid'],
            $this->requesterId, ['decision' => 'APPROVED']);
    }

    public function test_an_unconfigured_approval_blocks_rather_than_passes(): void
    {
        // No workflow at all. Treating this as "anyone may approve" is how an unapproved hot-work
        // permit gets issued.
        $this->expectExceptionMessageMatches('/No approval workflow is configured/');

        $this->open(['subject_type' => 'NOTHING_CONFIGURED']);
    }

    public function test_a_workflow_with_no_steps_is_refused_at_open_time(): void
    {
        $this->workflow([]);

        // Better to fail when somebody tries to use it than to create a request nothing can ever
        // approve, which would sit in a queue forever looking like a slow approver.
        $this->expectExceptionMessageMatches('/has no steps/');

        $this->open();
    }

    public function test_someone_without_the_step_role_cannot_approve(): void
    {
        $this->workflow([[]]);
        $request = $this->open();

        $this->expectExceptionMessageMatches('/which you are not/');

        app(ApprovalService::class)->decide($this->companyId, $request['uuid'],
            $this->approverId, ['decision' => 'APPROVED']);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $this->workflow([[]]);
        $this->giveRole($this->approverId);

        $request = $this->open();
        $service = app(ApprovalService::class);

        $service->decide($this->companyId, $request['uuid'], $this->approverId,
            ['decision' => 'APPROVED']);

        $this->expectExceptionMessageMatches('/already approved/i');

        $service->decide($this->companyId, $request['uuid'], $this->approverId,
            ['decision' => 'REJECTED']);
    }

    // ── The chain ─────────────────────────────────────────────────────────────────────────────

    public function test_a_single_step_approval_completes(): void
    {
        $this->workflow([[]]);
        $this->giveRole($this->approverId);

        $request = $this->open();

        $result = app(ApprovalService::class)->decide($this->companyId, $request['uuid'],
            $this->approverId, ['decision' => 'APPROVED', 'note' => 'Checked the isolation.']);

        $this->assertSame('APPROVED', $result['status']);
        $this->assertTrue(app(ApprovalService::class)
            ->isApproved($this->companyId, 'PERMIT', 4242));
    }

    public function test_a_multi_step_approval_advances_one_step_at_a_time(): void
    {
        $secondRole = 'sheq-second-'.Str::random(6);
        Role::create(['name' => $secondRole, 'guard_name' => 'web']);

        $this->workflow([
            ['name' => 'Supervisor'],
            ['name' => 'Safety officer', 'approver_ref' => $secondRole],
        ]);

        $second = $this->user();
        $this->giveRole($this->approverId);
        $this->giveRole($second, $secondRole);

        $service = app(ApprovalService::class);
        $request = $this->open();

        $afterFirst = $service->decide($this->companyId, $request['uuid'], $this->approverId,
            ['decision' => 'APPROVED']);

        $this->assertSame('PENDING', $afterFirst['status']);
        $this->assertSame('Safety officer', $afterFirst['awaiting']['name']);
        $this->assertFalse($service->isApproved($this->companyId, 'PERMIT', 4242));

        $afterSecond = $service->decide($this->companyId, $request['uuid'], $second,
            ['decision' => 'APPROVED']);

        $this->assertSame('APPROVED', $afterSecond['status']);
    }

    public function test_one_rejection_ends_the_whole_request(): void
    {
        $this->workflow([['name' => 'First'], ['name' => 'Second']]);
        $this->giveRole($this->approverId);

        $request = $this->open();

        $result = app(ApprovalService::class)->decide($this->companyId, $request['uuid'],
            $this->approverId, ['decision' => 'REJECTED', 'note' => 'Isolation not proven.']);

        // No "majority rejects" arithmetic: the question is whether everyone required is content,
        // and one refusal answers it.
        $this->assertSame('REJECTED', $result['status']);
        $this->assertFalse(app(ApprovalService::class)
            ->isApproved($this->companyId, 'PERMIT', 4242));
    }

    public function test_a_quorum_step_waits_for_enough_approvals(): void
    {
        $this->workflow([['name' => 'Two of the team', 'quorum' => 2]]);

        $first = $this->user();
        $second = $this->user();
        $this->giveRole($first);
        $this->giveRole($second);

        $service = app(ApprovalService::class);
        $request = $this->open();

        $this->assertSame('PENDING', $service->decide($this->companyId, $request['uuid'],
            $first, ['decision' => 'APPROVED'])['status']);

        $this->assertSame('APPROVED', $service->decide($this->companyId, $request['uuid'],
            $second, ['decision' => 'APPROVED'])['status']);
    }

    public function test_the_same_person_cannot_fill_a_quorum_alone(): void
    {
        $this->workflow([['name' => 'Two of the team', 'quorum' => 2]]);
        $this->giveRole($this->approverId);

        $service = app(ApprovalService::class);
        $request = $this->open();

        $service->decide($this->companyId, $request['uuid'], $this->approverId,
            ['decision' => 'APPROVED']);

        // A quorum of two means two people. The unique constraint is what enforces it, and this
        // is the test that proves the constraint is actually on the right columns.
        $this->expectException(UniqueConstraintViolationException::class);

        $service->decide($this->companyId, $request['uuid'], $this->approverId,
            ['decision' => 'APPROVED']);
    }

    public function test_a_step_needing_a_signature_refuses_a_bare_click(): void
    {
        $this->workflow([['name' => 'Sign off', 'requires_signature' => true]]);
        $this->giveRole($this->approverId);

        $request = $this->open();

        $this->expectExceptionMessageMatches('/requires a signature/');

        app(ApprovalService::class)->decide($this->companyId, $request['uuid'],
            $this->approverId, ['decision' => 'APPROVED']);
    }

    // ── Versioning ────────────────────────────────────────────────────────────────────────────

    public function test_editing_a_workflow_does_not_change_requests_already_in_flight(): void
    {
        $workflowId = $this->workflow([['name' => 'Only step']]);
        $this->giveRole($this->approverId);

        $request = $this->open();

        // Somebody adds a step while this request is half-way through.
        DB::table('sheq_approval_steps')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'workflow_id' => $workflowId, 'position' => 2, 'name' => 'Newly added',
            'approver_type' => 'ROLE', 'approver_ref' => $this->roleName, 'quorum' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(ApprovalService::class)->decide($this->companyId, $request['uuid'],
            $this->approverId, ['decision' => 'APPROVED']);

        // The approver was shown a one-step workflow and agreed to that. Silently making their
        // approval "step 1 of 2" would change what they signed up to.
        $this->assertSame('APPROVED', $result['status']);
    }

    // ── Specificity ───────────────────────────────────────────────────────────────────────────

    public function test_a_subtype_workflow_beats_the_company_default(): void
    {
        $this->workflow([['name' => 'General']], ['name' => 'Any permit']);
        $this->workflow([['name' => 'Confined space board']],
            ['name' => 'Confined space', 'subject_subtype' => 'CONFINED_SPACE']);

        $service = app(ApprovalService::class);

        $general = $service->workflowFor($this->companyId, 'PERMIT');
        $specific = $service->workflowFor($this->companyId, 'PERMIT', 'CONFINED_SPACE');

        $this->assertSame('Any permit', $general->name);
        $this->assertSame('Confined space', $specific->name,
            'Confined-space entry needing a different chain from a general permit is the normal case.');
    }

    // ── The queue ─────────────────────────────────────────────────────────────────────────────

    public function test_a_pending_request_appears_for_someone_who_can_act(): void
    {
        $this->workflow([[]]);
        $this->giveRole($this->approverId);

        $this->open();

        $awaiting = app(ApprovalService::class)->awaiting($this->companyId, $this->approverId);

        $this->assertCount(1, $awaiting);
        $this->assertSame('PTW-2026-0031', $awaiting[0]['subject_label']);
    }

    public function test_your_own_request_never_appears_in_your_queue(): void
    {
        $this->workflow([[]]);
        $this->giveRole($this->requesterId);

        $this->open();

        // Showing it would invite the click that the self-approval rule then refuses — an
        // action offered and then denied is worse than one never offered.
        $this->assertSame([], app(ApprovalService::class)
            ->awaiting($this->companyId, $this->requesterId));
    }

    public function test_someone_who_already_voted_drops_out_of_the_queue(): void
    {
        $this->workflow([['quorum' => 2]]);

        $first = $this->user();
        $this->giveRole($first);
        $this->giveRole($this->approverId);

        $request = $this->open();

        app(ApprovalService::class)->decide($this->companyId, $request['uuid'], $first,
            ['decision' => 'APPROVED']);

        $service = app(ApprovalService::class);

        $this->assertSame([], $service->awaiting($this->companyId, $first),
            'They have voted; they are waiting on someone else, not on themselves.');
        $this->assertCount(1, $service->awaiting($this->companyId, $this->approverId));
    }

    // ── Withdrawal ────────────────────────────────────────────────────────────────────────────

    public function test_only_the_requester_may_withdraw(): void
    {
        $this->workflow([[]]);
        $request = $this->open();

        $this->expectExceptionMessageMatches('/Only the person who raised/');

        app(ApprovalService::class)->cancel($this->companyId, $request['uuid'],
            $this->approverId, 'Changed my mind');
    }

    public function test_the_status_view_shows_the_chain_and_who_decided(): void
    {
        $this->workflow([['name' => 'Supervisor'], ['name' => 'Safety officer']]);
        $this->giveRole($this->approverId);

        $request = $this->open();

        app(ApprovalService::class)->decide($this->companyId, $request['uuid'],
            $this->approverId, ['decision' => 'APPROVED', 'note' => 'Looks right.']);

        $status = app(ApprovalService::class)->statusFor($this->companyId, 'PERMIT', 4242);

        $this->assertSame('PENDING', $status['status']);
        $this->assertCount(2, $status['steps']);
        $this->assertCount(1, $status['decisions']);
        $this->assertSame('Looks right.', $status['decisions'][0]['note']);
        $this->assertStringContainsString($this->roleName, $status['decisions'][0]['acted_as']);
    }
}
