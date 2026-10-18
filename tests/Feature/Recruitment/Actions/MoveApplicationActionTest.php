<?php

namespace Tests\Feature\Recruitment\Actions;

use App\Modules\HR\Recruitment\Actions\MoveApplicationAction;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\PipelineStage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class MoveApplicationActionTest extends RecruitmentTestCase
{
    private MoveApplicationAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(MoveApplicationAction::class);
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_moves_application_to_allowed_next_status(): void
    {
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Applied)
            ->create();

        $result = $this->action->execute(
            application: $application,
            newStatus:   ApplicationStatus::Screening,
            userId:      $this->recruiter->id,
        );

        $this->assertSame(ApplicationStatus::Screening, $result->status);
        $this->assertNotNull($result->stage_moved_at);
        $this->assertDatabaseHas('recruitment_applications', [
            'id'     => $application->id,
            'status' => ApplicationStatus::Screening->value,
        ]);
    }

    public function test_sets_rejected_at_and_reason_on_rejection(): void
    {
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Screening)
            ->create();

        $result = $this->action->execute(
            application: $application,
            newStatus:   ApplicationStatus::Rejected,
            userId:      $this->recruiter->id,
            reason:      'Insufficient experience.',
        );

        $this->assertSame(ApplicationStatus::Rejected, $result->status);
        $this->assertNotNull($result->rejected_at);
        $this->assertSame('Insufficient experience.', $result->getRawOriginal('rejected_reason'));
    }

    public function test_sets_withdrawn_at_on_withdrawal(): void
    {
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Applied)
            ->create();

        $result = $this->action->execute(
            application: $application,
            newStatus:   ApplicationStatus::Withdrawn,
            userId:      $this->recruiter->id,
        );

        $this->assertSame(ApplicationStatus::Withdrawn, $result->status);
        $this->assertNotNull($result->withdrawn_at);
    }

    public function test_assigns_pipeline_stage_when_provided(): void
    {
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Applied)
            ->create();

        $stage = PipelineStage::factory()->create(['job_id' => $application->job_id]);

        $result = $this->action->execute(
            application:     $application,
            newStatus:       ApplicationStatus::Screening,
            userId:          $this->recruiter->id,
            pipelineStageId: $stage->id,
        );

        $this->assertSame($stage->id, $result->pipeline_stage_id);
    }

    // ── Transition guards ────────────────────────────────────────────────────

    public function test_throws_when_transition_is_not_allowed(): void
    {
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Hired)
            ->create();

        $this->expectException(ValidationException::class);

        $this->action->execute(
            application: $application,
            newStatus:   ApplicationStatus::Screening,
            userId:      $this->recruiter->id,
        );
    }

    public function test_throws_when_pipeline_stage_belongs_to_different_job(): void
    {
        $application  = Application::factory()->inStatus(ApplicationStatus::Applied)->create();
        $foreignStage = PipelineStage::factory()->create(); // different job

        $this->expectException(ValidationException::class);

        $this->action->execute(
            application:     $application,
            newStatus:       ApplicationStatus::Screening,
            userId:          $this->recruiter->id,
            pipelineStageId: $foreignStage->id,
        );
    }

    public function test_cannot_re_enter_terminal_status(): void
    {
        foreach ([ApplicationStatus::Hired, ApplicationStatus::Rejected, ApplicationStatus::Withdrawn] as $terminal) {
            $application = Application::factory()->inStatus($terminal)->create();

            try {
                $this->action->execute(
                    application: $application,
                    newStatus:   ApplicationStatus::Screening,
                    userId:      $this->recruiter->id,
                );
                $this->fail("Expected ValidationException for terminal status {$terminal->value}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }
        }
    }
}
