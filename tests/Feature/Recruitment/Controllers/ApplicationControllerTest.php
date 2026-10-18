<?php

namespace Tests\Feature\Recruitment\Controllers;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class ApplicationControllerTest extends RecruitmentTestCase
{
    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_applications_for_a_job(): void
    {
        $job   = Job::factory()->create();
        Application::factory()->count(3)->create(['job_id' => $job->id]);
        Application::factory()->count(2)->create(); // different jobs

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.applications.index', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Applications/Index')
                ->has('applications.data', 3));
    }

    public function test_index_filters_by_status(): void
    {
        $job = Job::factory()->create();
        Application::factory()->count(2)->inStatus(ApplicationStatus::Screening)->create(['job_id' => $job->id]);
        Application::factory()->count(1)->inStatus(ApplicationStatus::Interview)->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.applications.index', [$job, 'status' => ApplicationStatus::Screening->value]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Applications/Index')
                ->has('applications.data', 2));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_application_scoped_to_job(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.applications.show', [$job, $application]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Applications/Show')
                ->where('application.id', $application->id));
    }

    public function test_show_returns_404_when_application_belongs_to_different_job(): void
    {
        $job         = Job::factory()->create();
        $otherJob    = Job::factory()->create();
        $application = Application::factory()->create(['job_id' => $otherJob->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.applications.show', [$job, $application]))
            ->assertNotFound();
    }

    // ── move ──────────────────────────────────────────────────────────────────

    public function test_move_transitions_application_status(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Applied)
            ->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->patch(
                route('recruitment.jobs.applications.move', [$job, $application]),
                ['status' => ApplicationStatus::Screening->value]
            )
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_applications', [
            'id'     => $application->id,
            'status' => ApplicationStatus::Screening->value,
        ]);
    }

    public function test_move_returns_422_for_invalid_transition(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Hired)
            ->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->from(route('recruitment.jobs.applications.show', [$job, $application]))
            ->patch(
                route('recruitment.jobs.applications.move', [$job, $application]),
                ['status' => ApplicationStatus::Screening->value]
            )
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        // Terminal status must remain unchanged.
        $this->assertDatabaseHas('recruitment_applications', [
            'id'     => $application->id,
            'status' => ApplicationStatus::Hired->value,
        ]);
    }

    public function test_move_returns_404_for_wrong_job_scope(): void
    {
        $job         = Job::factory()->create();
        $otherJob    = Job::factory()->create();
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Applied)
            ->create(['job_id' => $otherJob->id]);

        $this->actingAs($this->recruiter)
            ->patch(
                route('recruitment.jobs.applications.move', [$job, $application]),
                ['status' => ApplicationStatus::Screening->value]
            )
            ->assertNotFound();
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function test_reject_sets_status_to_rejected(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Screening)
            ->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->post(
                route('recruitment.jobs.applications.reject', [$job, $application]),
                ['reason' => 'Not suitable for the role.']
            )
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_applications', [
            'id'     => $application->id,
            'status' => ApplicationStatus::Rejected->value,
        ]);
    }

    public function test_reject_requires_reason(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Screening)
            ->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->from(route('recruitment.jobs.applications.show', [$job, $application]))
            ->post(route('recruitment.jobs.applications.reject', [$job, $application]), [])
            ->assertRedirect()
            ->assertSessionHasErrors('reason');
    }

    // ── withdraw ─────────────────────────────────────────────────────────────

    public function test_withdraw_sets_status_to_withdrawn(): void
    {
        $job         = Job::factory()->create();
        $application = Application::factory()
            ->inStatus(ApplicationStatus::Applied)
            ->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.jobs.applications.withdraw', [$job, $application]))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_applications', [
            'id'     => $application->id,
            'status' => ApplicationStatus::Withdrawn->value,
        ]);
    }
}
