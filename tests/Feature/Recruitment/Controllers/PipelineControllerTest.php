<?php

namespace Tests\Feature\Recruitment\Controllers;

use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class PipelineControllerTest extends RecruitmentTestCase
{
    /**
     * The board is rendered with a `columns` array, one entry per active status,
     * in this fixed order.
     */
    private const COLUMN_ORDER = [
        'applied', 'screening', 'assessment', 'interview', 'shortlisted', 'offer_sent',
    ];

    // ── structure ─────────────────────────────────────────────────────────────

    public function test_pipeline_returns_grouped_columns_for_all_active_statuses(): void
    {
        $job = Job::factory()->create();

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.pipeline', $job))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('Modules/Recruitment/Pipeline/Board')
                    ->has('columns', count(self::COLUMN_ORDER));

                foreach (self::COLUMN_ORDER as $index => $status) {
                    $page->where("columns.{$index}.status", $status)
                        ->has("columns.{$index}.label")
                        ->has("columns.{$index}.count")
                        ->has("columns.{$index}.has_more")
                        ->has("columns.{$index}.items");
                }
            });
    }

    public function test_pipeline_counts_match_database(): void
    {
        $job = Job::factory()->create();
        Application::factory()->count(3)->inStatus(ApplicationStatus::Applied)->create(['job_id' => $job->id]);
        Application::factory()->count(2)->inStatus(ApplicationStatus::Screening)->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.pipeline', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.status', 'applied')->where('columns.0.count', 3)
                ->where('columns.1.status', 'screening')->where('columns.1.count', 2)
                ->where('columns.3.status', 'interview')->where('columns.3.count', 0));
    }

    // ── keyset cursor ────────────────────────────────────────────────────────

    public function test_cursor_filters_by_id_for_column(): void
    {
        $job          = Job::factory()->create();
        $applications = Application::factory()
            ->count(5)
            ->inStatus(ApplicationStatus::Applied)
            ->create(['job_id' => $job->id]);

        $cursorId = $applications->get(1)->id;

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.pipeline', [$job, 'cursor_applied' => $cursorId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.status', 'applied')
                ->where('columns.0.items', fn ($items) => collect($items)->every(fn ($i) => $i['id'] > $cursorId)));
    }

    // ── scoping ───────────────────────────────────────────────────────────────

    public function test_pipeline_only_shows_applications_for_the_given_job(): void
    {
        $job      = Job::factory()->create();
        $otherJob = Job::factory()->create();

        Application::factory()->count(2)->inStatus(ApplicationStatus::Applied)->create(['job_id' => $job->id]);
        Application::factory()->count(5)->inStatus(ApplicationStatus::Applied)->create(['job_id' => $otherJob->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.pipeline', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.status', 'applied')
                ->where('columns.0.count', 2));
    }

    // ── has_more flag ────────────────────────────────────────────────────────

    public function test_has_more_is_true_when_column_exceeds_page_limit(): void
    {
        $job = Job::factory()->create();
        Application::factory()->count(11)->inStatus(ApplicationStatus::Applied)->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.pipeline', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.status', 'applied')
                ->where('columns.0.has_more', true)
                ->has('columns.0.items', 10));
    }

    public function test_has_more_is_false_when_column_fits_on_one_page(): void
    {
        $job = Job::factory()->create();
        Application::factory()->count(3)->inStatus(ApplicationStatus::Applied)->create(['job_id' => $job->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.pipeline', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('columns.0.status', 'applied')
                ->where('columns.0.has_more', false));
    }
}
