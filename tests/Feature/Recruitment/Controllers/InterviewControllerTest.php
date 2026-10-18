<?php

namespace Tests\Feature\Recruitment\Controllers;

use App\Modules\HR\Recruitment\Jobs\ScoreInterviewResponses;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Interview;
use App\Modules\HR\Recruitment\Models\Job;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class InterviewControllerTest extends RecruitmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    // ── show ────────────────────────────────────────────────────────────────
    // NOTE: there is no interviews-index endpoint in the current app; interviews
    // are read individually via the scoped show route.

    public function test_show_returns_interview_scoped_to_application(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);
        $interview   = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.applications.interviews.show', [$job, $application, $interview]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Interviews/Show')
                ->where('interview.id', $interview->id));
    }

    public function test_show_returns_404_for_interview_on_wrong_application(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);
        $otherApp    = Application::factory()->create(['job_id' => $job->id]);
        $interview   = Interview::factory()->create(['application_id' => $otherApp->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.applications.interviews.show', [$job, $application, $interview]))
            ->assertNotFound();
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_schedules_interview(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.jobs.applications.interviews.store', [$job, $application]), [
                'interviewer_id'   => $this->hrManager->id,
                'type'             => 'technical',
                'scheduled_at'     => now()->addDays(3)->format('Y-m-d H:i:s'),
                'duration_minutes' => 60,
                'location'         => 'Conference Room A',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_interviews', [
            'application_id' => $application->id,
            'status'         => 'scheduled',
        ]);
    }

    public function test_store_requires_interviewer_and_scheduled_at(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->from(route('recruitment.jobs.applications.show', [$job, $application]))
            ->post(route('recruitment.jobs.applications.interviews.store', [$job, $application]), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['interviewer_id', 'type', 'scheduled_at']);
    }

    // ── score ─────────────────────────────────────────────────────────────────

    public function test_score_creates_or_updates_interview_score(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);
        $interview   = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($this->hrManager)
            ->post(
                route('recruitment.jobs.applications.interviews.score', [$job, $application, $interview]),
                [
                    'overall_score'     => 85,
                    'technical_score'   => 90,
                    'culture_fit_score' => 75,
                    'recommendation'    => 'yes',
                    'comments'          => 'Strong candidate.',
                ]
            )
            ->assertRedirect();

        $this->assertDatabaseHas('interview_scores', [
            'interview_id' => $interview->id,
            'scored_by'    => $this->hrManager->id,
        ]);
    }

    public function test_score_returns_404_for_interview_on_wrong_application(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);
        $otherApp    = Application::factory()->create(['job_id' => $job->id]);
        $interview   = Interview::factory()->create(['application_id' => $otherApp->id]);

        $this->actingAs($this->hrManager)
            ->post(
                route('recruitment.jobs.applications.interviews.score', [$job, $application, $interview]),
                ['overall_score' => 80, 'recommendation' => 'yes']
            )
            ->assertNotFound();
    }

    // ── complete ─────────────────────────────────────────────────────────────

    public function test_complete_marks_interview_as_completed_and_queues_ai_scoring(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);
        $interview   = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($this->hrManager)
            ->post(route('recruitment.jobs.applications.interviews.complete', [$job, $application, $interview]))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_interviews', [
            'id'     => $interview->id,
            'status' => 'completed',
        ]);

        Queue::assertPushedOn('ai-fast', ScoreInterviewResponses::class);
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_marks_interview_as_cancelled(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);
        $interview   = Interview::factory()->create(['application_id' => $application->id]);

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.jobs.applications.interviews.cancel', [$job, $application, $interview]))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_interviews', [
            'id'     => $interview->id,
            'status' => 'cancelled',
        ]);
    }
}
