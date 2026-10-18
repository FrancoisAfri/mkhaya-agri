<?php

namespace Tests\Feature\Recruitment\Actions;

use App\Modules\HR\Recruitment\Actions\ApproveOverrideAction;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\HR\Recruitment\Models\ShortlistItem;
use App\Modules\HR\Recruitment\Models\ShortlistOverride;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class ApproveOverrideActionTest extends RecruitmentTestCase
{
    private ApproveOverrideAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ApproveOverrideAction::class);
    }

    // ── "added" override ─────────────────────────────────────────────────────

    public function test_adds_candidate_to_shortlist_on_added_override_approval(): void
    {
        $shortlist = Shortlist::factory()->pending()->create();
        $candidate = Candidate::factory()->create();

        // A shortlist item is derived from the candidate's application to the job.
        Application::factory()->create([
            'candidate_id' => $candidate->id,
            'job_id'       => $shortlist->job_id,
        ]);

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'added',
            'overridden_by'   => $this->recruiter->id,
            'approver_id'     => null,
            'approved_at'     => null,
        ]);

        $this->action->execute($override, $this->hrManager->id);

        $this->assertDatabaseHas('candidate_shortlist_items', [
            'shortlist_id' => $shortlist->id,
            'candidate_id' => $candidate->id,
        ]);
    }

    // ── "removed" override ───────────────────────────────────────────────────

    public function test_removes_candidate_from_shortlist_on_removed_override_approval(): void
    {
        $shortlist = Shortlist::factory()->pending()->create();
        $candidate = Candidate::factory()->create();

        $application = Application::factory()->create([
            'candidate_id' => $candidate->id,
            'job_id'       => $shortlist->job_id,
        ]);

        ShortlistItem::create([
            'shortlist_id'   => $shortlist->id,
            'candidate_id'   => $candidate->id,
            'application_id' => $application->id,
            'ai_recommended' => false,
        ]);

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'removed',
            'overridden_by'   => $this->recruiter->id,
            'approver_id'     => null,
            'approved_at'     => null,
        ]);

        $this->action->execute($override, $this->hrManager->id);

        $this->assertDatabaseMissing('candidate_shortlist_items', [
            'shortlist_id' => $shortlist->id,
            'candidate_id' => $candidate->id,
        ]);
    }

    // ── Approval stamp ───────────────────────────────────────────────────────

    public function test_stamps_approver_and_approved_at_on_override(): void
    {
        $shortlist = Shortlist::factory()->pending()->create();
        $candidate = Candidate::factory()->create();

        Application::factory()->create([
            'candidate_id' => $candidate->id,
            'job_id'       => $shortlist->job_id,
        ]);

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'added',
            'overridden_by'   => $this->recruiter->id,
            'approver_id'     => null,
            'approved_at'     => null,
        ]);

        $result = $this->action->execute($override, $this->hrManager->id);

        $this->assertSame($this->hrManager->id, $result->approver_id);
        $this->assertNotNull($result->approved_at);
    }

    // ── Four-eyes guard ──────────────────────────────────────────────────────

    public function test_throws_when_approver_is_the_same_as_overrider(): void
    {
        $shortlist = Shortlist::factory()->pending()->create();
        $candidate = Candidate::factory()->create();

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'added',
            'overridden_by'   => $this->hrManager->id,
            'approver_id'     => null,
            'approved_at'     => null,
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute($override, $this->hrManager->id);
    }

    public function test_four_eyes_error_is_on_approver_id_field(): void
    {
        $shortlist = Shortlist::factory()->pending()->create();
        $candidate = Candidate::factory()->create();

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'added',
            'overridden_by'   => $this->hrManager->id,
            'approver_id'     => null,
            'approved_at'     => null,
        ]);

        try {
            $this->action->execute($override, $this->hrManager->id);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_id', $e->errors());
        }
    }

    // ── Already-approved guard ───────────────────────────────────────────────

    public function test_throws_when_override_is_already_approved(): void
    {
        $shortlist = Shortlist::factory()->pending()->create();
        $candidate = Candidate::factory()->create();

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'added',
            'overridden_by'   => $this->recruiter->id,
            'approver_id'     => $this->hrManager->id,
            'approved_at'     => now()->subMinute(),
        ]);

        $this->expectException(ValidationException::class);

        $this->action->execute($override, $this->admin->id);
    }
}
