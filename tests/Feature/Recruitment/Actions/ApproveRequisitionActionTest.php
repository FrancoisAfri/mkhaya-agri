<?php

namespace Tests\Feature\Recruitment\Actions;

use App\Modules\HR\Recruitment\Actions\ApproveRequisitionAction;
use App\Modules\HR\Recruitment\Enums\RequisitionStatus;
use App\Modules\HR\Recruitment\Models\Requisition;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class ApproveRequisitionActionTest extends RecruitmentTestCase
{
    private ApproveRequisitionAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ApproveRequisitionAction::class);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_approves_pending_requisition(): void
    {
        $requisition = Requisition::factory()
            ->pending()
            ->create(['requested_by' => $this->recruiter->id]);

        $result = $this->action->execute($requisition, $this->hrManager->id);

        $this->assertSame(RequisitionStatus::Approved, $result->status);
        $this->assertSame($this->hrManager->id, $result->approved_by);
        $this->assertNotNull($result->approved_at);
    }

    public function test_sets_approved_by_and_approved_at(): void
    {
        $requisition = Requisition::factory()
            ->pending()
            ->create(['requested_by' => $this->recruiter->id]);

        $before = now()->subSecond();
        $result = $this->action->execute($requisition, $this->hrManager->id);
        $after  = now()->addSecond();

        $this->assertTrue($result->approved_at->between($before, $after));
        $this->assertDatabaseHas('recruitment_requisitions', [
            'id'          => $requisition->id,
            'status'      => RequisitionStatus::Approved->value,
            'approved_by' => $this->hrManager->id,
        ]);
    }

    // ── Self-approval guard ──────────────────────────────────────────────────

    public function test_throws_when_approver_is_the_same_as_requester(): void
    {
        $requisition = Requisition::factory()
            ->pending()
            ->create(['requested_by' => $this->hrManager->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($requisition, $this->hrManager->id);
    }

    public function test_self_approval_error_is_on_approver_id_field(): void
    {
        $requisition = Requisition::factory()
            ->pending()
            ->create(['requested_by' => $this->hrManager->id]);

        try {
            $this->action->execute($requisition, $this->hrManager->id);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_id', $e->errors());
        }
    }

    // ── Status guard ─────────────────────────────────────────────────────────

    public function test_throws_when_requisition_is_not_pending(): void
    {
        $requisition = Requisition::factory()
            ->approved($this->hrManager->id)
            ->create(['requested_by' => $this->recruiter->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($requisition, $this->admin->id);
    }

    public function test_throws_for_draft_requisition(): void
    {
        $requisition = Requisition::factory()->create([
            'requested_by' => $this->recruiter->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute($requisition, $this->hrManager->id);
    }
}
