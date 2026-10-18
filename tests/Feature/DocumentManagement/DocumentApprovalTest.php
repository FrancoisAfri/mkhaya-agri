<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalStep;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Services\ApprovalService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Documents going through approval.
 *
 * The four arrangements the requirement asks for are one mechanism: steps sharing a position run at
 * once, consecutive positions run in order. These tests are mostly about the boundaries between
 * them — that a later step cannot answer before an earlier one, that "any" closes the others, and
 * that a rejection stops everything rather than letting the next step contradict it.
 */
class DocumentApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        Notification::fake();
    }

    private function approvals(): ApprovalService
    {
        return app(ApprovalService::class);
    }

    private function user(?string $role = null): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ap+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    private function document(): Document
    {
        return Document::create([
            'title' => 'Employment contract', 'status' => Document::STATUS_ACTIVE,
            'version_count' => 1, 'approval_status' => Document::APPROVAL_DRAFT,
        ]);
    }

    /**
     * @param  list<array{name:string, position:int, role:string, quorum?:string, deadline?:int}>  $steps
     */
    private function workflow(array $steps): DocumentApprovalWorkflow
    {
        $workflow = DocumentApprovalWorkflow::create(['name' => 'Contract approval ' . Str::random(4)]);

        foreach ($steps as $step) {
            DocumentApprovalStep::create([
                'workflow_id' => $workflow->id,
                'name' => $step['name'],
                'position' => $step['position'],
                'quorum' => $step['quorum'] ?? DocumentApprovalStep::QUORUM_ALL,
                'approver_type' => DocumentApprovalStep::APPROVER_ROLE,
                'approver_name' => $step['role'],
                'deadline_days' => $step['deadline'] ?? null,
            ]);
        }

        return $workflow->fresh();
    }

    private function assignmentFor(DocumentApprovalRequest $request, User $user): DocumentApprovalAssignment
    {
        return $request->assignments()->where('assigned_to', $user->id)->firstOrFail();
    }

    // ── Single level ─────────────────────────────────────────────────────────

    public function test_a_single_approval_publishes_nothing_by_itself(): void
    {
        $manager = $this->user('Manager');
        $document = $this->document();
        $workflow = $this->workflow([['name' => 'Manager review', 'position' => 1, 'role' => 'Manager']]);

        $request = $this->approvals()->submit($document, $workflow)['request'];
        $this->approvals()->decide($this->assignmentFor($request, $manager), 'approved', $manager);

        // Approved says the content is right. Published says it is the one in force, and an
        // organisation decides when that happens.
        $this->assertSame(Document::APPROVAL_APPROVED, $document->fresh()->approval_status);
        $this->assertNull($document->fresh()->published_at);

        $this->approvals()->publish($document->fresh());
        $this->assertSame(Document::APPROVAL_PUBLISHED, $document->fresh()->approval_status);
    }

    public function test_publishing_requires_approval_first(): void
    {
        $result = $this->approvals()->publish($this->document());

        $this->assertNull($result['document']);
    }

    // ── Sequential ───────────────────────────────────────────────────────────

    public function test_steps_run_in_order(): void
    {
        $manager = $this->user('Manager');
        $finance = $this->user('Finance');
        $workflow = $this->workflow([
            ['name' => 'Manager review', 'position' => 1, 'role' => 'Manager'],
            ['name' => 'Finance review', 'position' => 2, 'role' => 'Finance'],
        ]);

        $request = $this->approvals()->submit($this->document(), $workflow)['request'];

        // Finance cannot answer before the manager has: that is the whole point of sequential, and
        // without the guard a later approver could wave something through unseen.
        $early = $this->approvals()->decide($this->assignmentFor($request, $finance), 'approved', $finance);
        $this->assertNotNull($early['error']);

        $this->approvals()->decide($this->assignmentFor($request, $manager), 'approved', $manager);
        $this->assertSame(2, $request->fresh()->current_position);

        $this->approvals()->decide($this->assignmentFor($request->fresh(), $finance), 'approved', $finance);
        $this->assertSame(DocumentApprovalRequest::APPROVED, $request->fresh()->status);
    }

    // ── Parallel ─────────────────────────────────────────────────────────────

    public function test_steps_sharing_a_position_run_at_the_same_time(): void
    {
        $legal = $this->user('Legal');
        $finance = $this->user('Finance');
        $workflow = $this->workflow([
            ['name' => 'Legal review', 'position' => 1, 'role' => 'Legal'],
            ['name' => 'Finance review', 'position' => 1, 'role' => 'Finance'],
        ]);

        $request = $this->approvals()->submit($this->document(), $workflow)['request'];

        // Either may go first, and one approving does not finish the position.
        $this->approvals()->decide($this->assignmentFor($request, $finance), 'approved', $finance);
        $this->assertSame(DocumentApprovalRequest::UNDER_REVIEW, $request->fresh()->status);

        $this->approvals()->decide($this->assignmentFor($request->fresh(), $legal), 'approved', $legal);
        $this->assertSame(DocumentApprovalRequest::APPROVED, $request->fresh()->status);
    }

    public function test_a_quorum_of_any_is_satisfied_by_the_first_approval(): void
    {
        Role::findOrCreate('Duty Manager', 'web');
        $first = $this->user('Duty Manager');
        $second = $this->user('Duty Manager');

        $workflow = $this->workflow([[
            'name' => 'Whoever is on duty', 'position' => 1, 'role' => 'Duty Manager',
            'quorum' => DocumentApprovalStep::QUORUM_ANY,
        ]]);

        $request = $this->approvals()->submit($this->document(), $workflow)['request'];
        $this->approvals()->decide($this->assignmentFor($request, $first), 'approved', $first);

        $this->assertSame(DocumentApprovalRequest::APPROVED, $request->fresh()->status);

        // The other person's task is closed rather than left pending in their queue forever.
        $this->assertSame(
            DocumentApprovalAssignment::SKIPPED,
            $this->assignmentFor($request->fresh(), $second)->status,
        );
    }

    // ── Refusing ─────────────────────────────────────────────────────────────

    public function test_a_rejection_stops_the_whole_thing(): void
    {
        $manager = $this->user('Manager');
        $this->user('Finance');
        $workflow = $this->workflow([
            ['name' => 'Manager review', 'position' => 1, 'role' => 'Manager'],
            ['name' => 'Finance review', 'position' => 2, 'role' => 'Finance'],
        ]);

        $document = $this->document();
        $request = $this->approvals()->submit($document, $workflow)['request'];
        $this->approvals()->decide($this->assignmentFor($request, $manager), 'rejected', $manager, 'Wrong salary');

        // Carrying on would produce a document approved by finance and rejected by the manager,
        // with nothing to say which stands.
        $this->assertSame(DocumentApprovalRequest::REJECTED, $request->fresh()->status);
        $this->assertSame(Document::APPROVAL_REJECTED, $document->fresh()->approval_status);
        $this->assertSame(1, $request->fresh()->current_position);
    }

    public function test_requesting_changes_sends_it_back_rather_than_refusing_it(): void
    {
        $manager = $this->user('Manager');
        $workflow = $this->workflow([['name' => 'Manager review', 'position' => 1, 'role' => 'Manager']]);

        $document = $this->document();
        $request = $this->approvals()->submit($document, $workflow)['request'];
        $this->approvals()->decide($this->assignmentFor($request, $manager), 'changes_requested', $manager, 'Add the annexure');

        $this->assertSame(Document::APPROVAL_CHANGES_REQUESTED, $document->fresh()->approval_status);
    }

    // ── Delegation ───────────────────────────────────────────────────────────

    public function test_an_approval_can_be_handed_to_somebody_else(): void
    {
        $manager = $this->user('Manager');
        $deputy = $this->user();
        $workflow = $this->workflow([['name' => 'Manager review', 'position' => 1, 'role' => 'Manager']]);

        $request = $this->approvals()->submit($this->document(), $workflow)['request'];
        $assignment = $this->assignmentFor($request, $manager);

        $this->approvals()->delegate($assignment, $deputy, $manager, 'On leave');

        // Both ends are kept: the request went to the manager, the deputy answered it.
        $this->assertSame($deputy->id, $assignment->fresh()->delegated_to);
        $this->assertSame($manager->id, $assignment->fresh()->assigned_to);

        $decided = $this->approvals()->decide($assignment->fresh(), 'approved', $deputy);
        $this->assertNull($decided['error']);
    }

    public function test_the_original_approver_cannot_answer_once_they_have_delegated(): void
    {
        $manager = $this->user('Manager');
        $deputy = $this->user();
        $workflow = $this->workflow([['name' => 'Manager review', 'position' => 1, 'role' => 'Manager']]);

        $request = $this->approvals()->submit($this->document(), $workflow)['request'];
        $assignment = $this->assignmentFor($request, $manager);
        $this->approvals()->delegate($assignment, $deputy, $manager);

        // Otherwise the record could show two people deciding the same thing.
        $result = $this->approvals()->decide($assignment->fresh(), 'approved', $manager);
        $this->assertNotNull($result['error']);
    }

    public function test_delegating_to_yourself_is_refused(): void
    {
        $manager = $this->user('Manager');
        $workflow = $this->workflow([['name' => 'Manager review', 'position' => 1, 'role' => 'Manager']]);
        $request = $this->approvals()->submit($this->document(), $workflow)['request'];

        $result = $this->approvals()->delegate($this->assignmentFor($request, $manager), $manager, $manager);

        $this->assertNotNull($result['error']);
    }

    // ── Deadlines, and the record ────────────────────────────────────────────

    public function test_a_step_with_a_deadline_gives_its_approvers_a_due_date(): void
    {
        $manager = $this->user('Manager');
        $workflow = $this->workflow([
            ['name' => 'Manager review', 'position' => 1, 'role' => 'Manager', 'deadline' => 5],
        ]);

        $request = $this->approvals()->submit($this->document(), $workflow)['request'];

        $this->assertNotNull($this->assignmentFor($request, $manager)->due_at);
        $this->assertFalse($this->assignmentFor($request, $manager)->isOverdue());
    }

    public function test_a_step_naming_an_empty_role_is_not_silently_skipped(): void
    {
        Role::findOrCreate('Nobody Holds This', 'web');
        $workflow = $this->workflow([['name' => 'Ghost review', 'position' => 1, 'role' => 'Nobody Holds This']]);

        $request = $this->approvals()->submit($this->document(), $workflow)['request'];

        // A step that vanished because its role is empty would be a document approved by nobody.
        $this->assertSame(1, $request->assignments()->count());
        $this->assertSame(DocumentApprovalRequest::SUBMITTED, $request->fresh()->status);
    }

    public function test_the_decision_lands_in_the_documents_own_history(): void
    {
        $manager = $this->user('Manager');
        $workflow = $this->workflow([['name' => 'Manager review', 'position' => 1, 'role' => 'Manager']]);

        $document = $this->document();
        $request = $this->approvals()->submit($document, $workflow)['request'];
        $this->approvals()->decide($this->assignmentFor($request, $manager), 'approved', $manager, 'Fine');

        // Answered from the same history as uploads and downloads, rather than from a workflow
        // table nobody thinks to open.
        $actions = $document->fresh()->events()->pluck('action');

        $this->assertTrue($actions->contains(DocumentEvent::APPROVAL_SUBMITTED));
        $this->assertTrue($actions->contains(DocumentEvent::APPROVED));
    }

    public function test_a_document_cannot_be_submitted_twice_at_once(): void
    {
        $this->user('Manager');
        $workflow = $this->workflow([['name' => 'Manager review', 'position' => 1, 'role' => 'Manager']]);
        $document = $this->document();

        $this->approvals()->submit($document, $workflow);
        $second = $this->approvals()->submit($document->fresh(), $workflow);

        $this->assertNotNull($second['error']);
    }
}
