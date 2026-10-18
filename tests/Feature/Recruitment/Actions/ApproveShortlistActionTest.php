<?php

namespace Tests\Feature\Recruitment\Actions;

use App\Modules\HR\Recruitment\Actions\ApproveShortlistAction;
use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use App\Modules\HR\Recruitment\Models\Shortlist;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class ApproveShortlistActionTest extends RecruitmentTestCase
{
    private ApproveShortlistAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ApproveShortlistAction::class);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_approves_pending_shortlist(): void
    {
        $shortlist = Shortlist::factory()
            ->pending()
            ->create(['generated_by' => $this->recruiter->id]);

        $result = $this->action->execute($shortlist, $this->hrManager->id);

        $this->assertSame(ShortlistStatus::Approved, $result->status);
        $this->assertSame($this->hrManager->id, $result->approved_by);
        $this->assertNotNull($result->approved_at);
    }

    public function test_persists_approval_to_database(): void
    {
        $shortlist = Shortlist::factory()
            ->pending()
            ->create(['generated_by' => $this->recruiter->id]);

        $this->action->execute($shortlist, $this->hrManager->id);

        $this->assertDatabaseHas('recruitment_shortlists', [
            'id'          => $shortlist->id,
            'status'      => ShortlistStatus::Approved->value,
            'approved_by' => $this->hrManager->id,
        ]);
    }

    // ── Self-approval guard ──────────────────────────────────────────────────

    public function test_throws_when_approver_generated_the_shortlist(): void
    {
        $shortlist = Shortlist::factory()
            ->pending()
            ->create(['generated_by' => $this->hrManager->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($shortlist, $this->hrManager->id);
    }

    public function test_self_approval_error_is_on_approver_id_field(): void
    {
        $shortlist = Shortlist::factory()
            ->pending()
            ->create(['generated_by' => $this->hrManager->id]);

        try {
            $this->action->execute($shortlist, $this->hrManager->id);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_id', $e->errors());
        }
    }

    // ── Status guard ─────────────────────────────────────────────────────────

    public function test_throws_when_shortlist_is_not_pending(): void
    {
        $shortlist = Shortlist::factory()
            ->approved($this->hrManager->id)
            ->create(['generated_by' => $this->recruiter->id]);

        $this->expectException(ValidationException::class);

        $this->action->execute($shortlist, $this->admin->id);
    }

    public function test_throws_for_draft_shortlist(): void
    {
        $shortlist = Shortlist::factory()->create([
            'generated_by' => $this->recruiter->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute($shortlist, $this->hrManager->id);
    }
}
