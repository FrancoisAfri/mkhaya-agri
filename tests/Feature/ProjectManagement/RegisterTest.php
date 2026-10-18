<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\ChangeRequest;
use App\Modules\Operations\ProjectManagement\Models\Issue;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Risk;
use App\Modules\Operations\ProjectManagement\Services\RegisterService;

/**
 * The risk, issue and change registers (§11, §12, §13).
 *
 * What these tests are really guarding is the *evidence trail*. A register that loses a risk when
 * it comes true, renumbers a reference somebody has already quoted in a letter, or lets a change
 * take effect without a decision is worse than no register — it looks like governance and provides
 * none.
 */
class RegisterTest extends ProjectManagementTestCase
{
    private Project $project;

    private RegisterService $registers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        $this->registers = app(RegisterService::class);
        $this->actingAs($this->manager);
    }

    // ── Numbering ────────────────────────────────────────────────────────────

    public function test_references_run_in_sequence_per_project_and_per_register(): void
    {
        $a = $this->registers->createRisk($this->project, ['title' => 'Ground conditions']);
        $b = $this->registers->createRisk($this->project, ['title' => 'Late permits']);
        $issue = $this->registers->createIssue($this->project, ['title' => 'Pump failure']);

        $this->assertSame('R-001', $a->code);
        $this->assertSame('R-002', $b->code);
        // Its own sequence: an issue numbered R-003 would read as a risk in every document
        // somebody pastes it into.
        $this->assertSame('I-001', $issue->code);
    }

    public function test_a_deleted_record_does_not_release_its_reference(): void
    {
        $first = $this->registers->createRisk($this->project, ['title' => 'Ground conditions']);
        $first->delete();

        $second = $this->registers->createRisk($this->project, ['title' => 'Something else']);

        // Reusing R-001 would put two different risks under one reference in the same email thread.
        $this->assertSame('R-002', $second->code);
    }

    public function test_numbering_restarts_on_a_different_project(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER', 'name' => 'Another job']);

        $this->registers->createRisk($this->project, ['title' => 'A']);
        $second = $this->registers->createRisk($other, ['title' => 'B']);

        $this->assertSame('R-001', $second->code);
    }

    // ── Risk scoring ─────────────────────────────────────────────────────────

    public function test_the_score_is_kept_in_step_with_probability_and_impact(): void
    {
        $risk = $this->registers->createRisk($this->project, [
            'title' => 'Ground conditions', 'probability' => 4, 'impact' => 5,
        ]);

        $this->assertSame(20, $risk->score);
        $this->assertSame('high', $risk->band());

        // A score that disagrees with the two columns beside it is worse than no score, because
        // people read the number and not the inputs.
        $this->registers->updateRisk($risk, ['probability' => 1, 'impact' => 2]);

        $this->assertSame(2, $risk->fresh()->score);
        $this->assertSame('low', $risk->fresh()->band());
    }

    public function test_a_high_risk_reaches_the_evidence_ledger(): void
    {
        $risk = $this->registers->createRisk($this->project, [
            'title' => 'Ground conditions', 'probability' => 5, 'impact' => 4,
        ]);

        $this->assertDatabaseHas('pm_evidence_events', [
            'project_id'   => $this->project->id,
            'event_type'   => 'issue.raised',
            'subject_type' => 'risk',
            'subject_uuid' => $risk->uuid,
        ]);
    }

    public function test_a_risk_cannot_be_closed_without_saying_why(): void
    {
        $risk = $this->registers->createRisk($this->project, ['title' => 'Ground conditions']);

        $this->expectException(\RuntimeException::class);

        $this->registers->closeRisk($risk, '   ');
    }

    // ── A risk coming true ───────────────────────────────────────────────────

    public function test_a_realised_risk_stays_on_the_register_and_is_linked_to_its_issue(): void
    {
        $risk = $this->registers->createRisk($this->project, [
            'title' => 'Ground conditions', 'probability' => 4, 'impact' => 5,
            'cost_impact' => 250000, 'currency' => 'ZAR',
        ]);

        $issue = $this->registers->promoteRisk($risk);

        // The whole point: the register can still prove somebody saw this coming.
        $this->assertSame('realised', $risk->fresh()->status);
        $this->assertNotNull($risk->fresh()->closed_at);
        $this->assertSame($risk->id, $issue->risk_id);
        $this->assertSame('250000.00', (string) $issue->cost_impact);
        $this->assertSame('critical', $issue->severity);
    }

    // ── Issue closure ────────────────────────────────────────────────────────

    public function test_an_incident_cannot_be_resolved_without_a_root_cause(): void
    {
        $issue = $this->registers->createIssue($this->project, [
            'title' => 'Scaffold collapse', 'kind' => 'incident',
        ]);

        $this->expectException(\RuntimeException::class);

        $this->registers->resolveIssue($issue, ['resolution' => 'Rebuilt it']);
    }

    public function test_an_ordinary_issue_resolves_without_one(): void
    {
        $issue = $this->registers->createIssue($this->project, [
            'title' => 'Wrong paint delivered', 'kind' => 'issue', 'severity' => 'low',
        ]);

        $resolved = $this->registers->resolveIssue($issue, ['resolution' => 'Supplier swapped it']);

        // Demanding a root cause on every typo is how the field becomes "n/a" everywhere and stops
        // meaning anything on the record where it mattered.
        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
    }

    // ── Change requests ──────────────────────────────────────────────────────

    /**
     * A one-rung ladder for change requests.
     *
     * Without a designed flow the service correctly records the change as approved and says so —
     * a real branch, and tested below. But the branch that matters is the routed one, so the
     * tenant gets a flow rather than the suite skipping the case.
     */
    private function designChangeFlow(): \App\Modules\Operations\ProjectManagement\Models\ApprovalFlow
    {
        $flow = \App\Modules\Operations\ProjectManagement\Models\ApprovalFlow::create([
            'name'         => 'Changes',
            'subject_type' => 'change_request',
            'is_active'    => true,
        ]);

        \App\Modules\Operations\ProjectManagement\Models\ApprovalStep::create([
            'flow_id'       => $flow->id,
            'position'      => 1,
            'name'          => 'Project manager',
            'approver_type' => 'role',
            'approver_ref'  => 'pm manager',
            'min_approvals' => 1,
        ]);

        return $flow;
    }

    public function test_a_change_request_goes_through_the_approval_engine(): void
    {
        $this->designChangeFlow();

        $change = $this->registers->createChange($this->project, [
            'title' => 'Add a second lift', 'kind' => 'scope', 'impact_cost' => 400000, 'impact_days' => 30,
        ]);

        $this->registers->submitChange($change);

        $change->refresh();

        $this->assertSame('submitted', $change->status);
        $this->assertNotNull($change->approval_uuid);
        $this->assertDatabaseHas('pm_approval_requests', [
            'uuid'         => $change->approval_uuid,
            'subject_type' => 'change_request',
            'status'       => 'pending',
        ]);
    }

    /**
     * With nothing designed to route it, the change is recorded as approved *and says so*.
     *
     * The alternative — leaving it "submitted" — is a request sitting in a queue nobody is watching,
     * which reads as governance and is the absence of it.
     */
    public function test_with_no_flow_designed_the_change_says_so_rather_than_waiting_forever(): void
    {
        $change = $this->registers->createChange($this->project, ['title' => 'Add a lift']);

        $this->registers->submitChange($change);

        $this->assertSame('approved', $change->fresh()->status);
        $this->assertNotNull($change->fresh()->decision_note);
    }

    public function test_a_submitted_change_cannot_be_resubmitted(): void
    {
        $this->designChangeFlow();

        $change = $this->registers->createChange($this->project, ['title' => 'Add a lift']);
        $this->registers->submitChange($change);

        $this->expectException(\RuntimeException::class);

        // Editing or resubmitting underneath an approver means the thing approved is not the thing
        // that was read.
        $this->registers->submitChange($change->fresh());
    }

    public function test_a_change_cannot_be_applied_before_it_is_approved(): void
    {
        $change = $this->registers->createChange($this->project, ['title' => 'Add a lift']);

        $this->expectException(\RuntimeException::class);

        // A plan that moves without a decision has no variance to report, which is the whole
        // failure this register exists to prevent.
        $this->registers->implementChange($change);
    }

    public function test_applying_a_change_takes_a_baseline(): void
    {
        $change = $this->registers->createChange($this->project, ['title' => 'Add a lift']);
        $change->update(['status' => 'approved', 'decided_at' => now()]);

        $this->registers->implementChange(
            $change->fresh(),
            app(\App\Modules\Operations\ProjectManagement\Services\BaselineService::class),
        );

        $change->refresh();

        $this->assertSame('implemented', $change->status);
        $this->assertNotNull($change->implemented_at);
        // Without the baseline, "what did this change do to the schedule" is a matter of memory.
        $this->assertNotNull($change->baseline_id);
    }

    public function test_a_decision_on_the_approval_reaches_the_change_request(): void
    {
        $this->designChangeFlow();

        $change = $this->registers->createChange($this->project, ['title' => 'Add a lift']);
        $this->registers->submitChange($change);

        ApprovalRequest::where('uuid', $change->fresh()->approval_uuid)
            ->update(['status' => 'approved', 'decided_at' => now(), 'decision_reason' => 'Client agreed']);

        $this->registers->reconcileChanges($this->project);

        $this->assertSame('approved', $change->fresh()->status);
        $this->assertSame('Client agreed', $change->fresh()->decision_note);
    }

    // ── The page ─────────────────────────────────────────────────────────────

    public function test_the_register_page_renders(): void
    {
        $this->registers->createRisk($this->project, ['title' => 'Ground conditions']);
        $this->registers->createIssue($this->project, ['title' => 'Pump failure']);
        $this->registers->createChange($this->project, ['title' => 'Add a lift']);

        $this->get(route('project-management.registers', $this->project->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/ProjectManagement/Projects/Registers')
                ->has('risks', 1)
                ->has('issues', 1)
                ->has('changes', 1));
    }

    public function test_cost_exposure_is_hidden_from_somebody_who_may_not_see_money(): void
    {
        $this->registers->createRisk($this->project, ['title' => 'Ground conditions', 'cost_impact' => 500000]);

        // A risk register is not a way around the cost redaction that applies everywhere else.
        $this->actingAs($this->clientUser($this->project))
            ->get(route('project-management.registers', $this->project->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('risks.0.cost_impact', null));
    }

    /**
     * A project member holding the client role.
     *
     * Made here rather than reaching into another test class: a fixture shared by inheritance
     * between unrelated suites is one that changes for reasons that have nothing to do with this
     * one.
     */
    private function clientUser(Project $project): \App\Core\User\Models\User
    {
        $user = $this->makeUser('pm-client-' . uniqid() . '@example.test', null);

        \App\Modules\Operations\ProjectManagement\Models\ProjectMember::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'role_key'   => 'client',
        ]);

        app(\App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator::class)->flush();

        return $user;
    }

    public function test_a_risk_from_another_project_does_not_resolve(): void
    {
        $other = $this->makeProject(['code' => 'PRJ-OTHER', 'name' => 'Another job']);
        $risk = $this->registers->createRisk($other, ['title' => 'Theirs']);

        $this->putJson(
            route('project-management.risks.update', [$this->project->uuid, $risk->uuid]),
            ['title' => 'Mine now', 'category' => 'technical', 'probability' => 1, 'impact' => 1, 'response_strategy' => 'accept'],
        )->assertNotFound();
    }
}
