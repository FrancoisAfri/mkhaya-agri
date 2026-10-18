<?php

namespace Tests\Feature\Recruitment\Controllers;

use App\Modules\HR\Recruitment\Enums\ShortlistStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\FitScore;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Models\Shortlist;
use App\Modules\HR\Recruitment\Models\ShortlistOverride;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class ShortlistControllerTest extends RecruitmentTestCase
{
    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_shortlist_with_items(): void
    {
        $job       = Job::factory()->create();
        $shortlist = Shortlist::factory()->pending()->create([
            'job_id'       => $job->id,
            'generated_by' => $this->recruiter->id,
        ]);

        $this->actingAs($this->hrManager)
            ->get(route('recruitment.jobs.shortlists.show', [$job, $shortlist]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Shortlists/Show')
                ->where('shortlist.id', $shortlist->id));
    }

    public function test_show_returns_404_when_shortlist_belongs_to_different_job(): void
    {
        $job      = Job::factory()->create();
        $otherJob = Job::factory()->create();
        $shortlist = Shortlist::factory()->pending()->create(['job_id' => $otherJob->id]);

        $this->actingAs($this->hrManager)
            ->get(route('recruitment.jobs.shortlists.show', [$job, $shortlist]))
            ->assertNotFound();
    }

    // ── generate ─────────────────────────────────────────────────────────────

    public function test_generate_creates_shortlist_from_scored_applications(): void
    {
        $job       = Job::factory()->create();
        $candidate = Candidate::factory()->create();
        $app       = Application::factory()->create([
            'job_id'       => $job->id,
            'candidate_id' => $candidate->id,
        ]);

        FitScore::create([
            'application_id' => $app->id,
            'job_id'         => $job->id,
            'candidate_id'   => $candidate->id,
            'overall_score'  => 85.0,
        ]);

        $this->actingAs($this->hrManager)
            ->post(route('recruitment.jobs.shortlists.generate', $job))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_shortlists', [
            'job_id'       => $job->id,
            'generated_by' => $this->hrManager->id,
        ]);
    }

    public function test_generate_flashes_error_when_no_scored_applications_exist(): void
    {
        $job = Job::factory()->create();

        $this->actingAs($this->hrManager)
            ->from(route('recruitment.jobs.shortlists.index', $job))
            ->post(route('recruitment.jobs.shortlists.generate', $job))
            ->assertRedirect()
            ->assertSessionHas('error', 'No scored applications to shortlist yet.');
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function test_approve_approves_pending_shortlist(): void
    {
        $job       = Job::factory()->create();
        $shortlist = Shortlist::factory()->pending()->create([
            'job_id'       => $job->id,
            'generated_by' => $this->recruiter->id,
        ]);

        $this->actingAs($this->hrManager)
            ->post(route('recruitment.jobs.shortlists.approve', [$job, $shortlist]))
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_shortlists', [
            'id'     => $shortlist->id,
            'status' => ShortlistStatus::Approved->value,
        ]);
    }

    public function test_approve_rejects_self_approval(): void
    {
        $job       = Job::factory()->create();
        $shortlist = Shortlist::factory()->pending()->create([
            'job_id'       => $job->id,
            'generated_by' => $this->hrManager->id,
        ]);

        $this->actingAs($this->hrManager)
            ->from(route('recruitment.jobs.shortlists.show', [$job, $shortlist]))
            ->post(route('recruitment.jobs.shortlists.approve', [$job, $shortlist]))
            ->assertRedirect()
            ->assertSessionHasErrors('approver_id');

        $this->assertDatabaseHas('recruitment_shortlists', [
            'id'     => $shortlist->id,
            'status' => ShortlistStatus::Pending->value,
        ]);
    }

    // ── override ─────────────────────────────────────────────────────────────

    public function test_override_creates_pending_override_request(): void
    {
        $job       = Job::factory()->create();
        $shortlist = Shortlist::factory()->pending()->create([
            'job_id'       => $job->id,
            'generated_by' => $this->recruiter->id,
        ]);
        $candidate = Candidate::factory()->create();

        $this->actingAs($this->hrManager)
            ->post(route('recruitment.jobs.shortlists.overrides.store', [$job, $shortlist]), [
                'candidate_id'    => $candidate->id,
                'action'          => 'added',
                'override_reason' => 'Strong technical background worth a second look.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('candidate_shortlist_overrides', [
            'shortlist_id'  => $shortlist->id,
            'candidate_id'  => $candidate->id,
            'action'        => 'added',
            'overridden_by' => $this->hrManager->id,
            'approved_at'   => null,
        ]);
    }

    // ── approveOverride ───────────────────────────────────────────────────────

    public function test_approve_override_applies_add_action(): void
    {
        $job       = Job::factory()->create();
        $shortlist = Shortlist::factory()->pending()->create(['job_id' => $job->id]);
        $candidate = Candidate::factory()->create();

        // A shortlist item is derived from the candidate's application to the job.
        Application::factory()->create([
            'candidate_id' => $candidate->id,
            'job_id'       => $job->id,
        ]);

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'added',
            'overridden_by'   => $this->recruiter->id,
            'approver_id'     => null,
            'approved_at'     => null,
        ]);

        $this->actingAs($this->hrManager)
            ->post(route('recruitment.jobs.shortlists.overrides.approve', [$job, $shortlist, $override]))
            ->assertRedirect();

        $this->assertDatabaseHas('candidate_shortlist_items', [
            'shortlist_id' => $shortlist->id,
            'candidate_id' => $candidate->id,
        ]);
    }

    public function test_approve_override_rejects_four_eyes_violation(): void
    {
        $job       = Job::factory()->create();
        $shortlist = Shortlist::factory()->pending()->create(['job_id' => $job->id]);
        $candidate = Candidate::factory()->create();

        $override = ShortlistOverride::factory()->create([
            'shortlist_id'    => $shortlist->id,
            'candidate_id'    => $candidate->id,
            'action'          => 'added',
            'overridden_by'   => $this->hrManager->id,
            'approver_id'     => null,
            'approved_at'     => null,
        ]);

        $this->actingAs($this->hrManager)
            ->from(route('recruitment.jobs.shortlists.show', [$job, $shortlist]))
            ->post(route('recruitment.jobs.shortlists.overrides.approve', [$job, $shortlist, $override]))
            ->assertRedirect()
            ->assertSessionHasErrors('approver_id');
    }
}
