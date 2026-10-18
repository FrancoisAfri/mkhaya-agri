<?php

namespace Tests\Feature\Recruitment\Controllers;

use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Job;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class JobControllerTest extends RecruitmentTestCase
{
    // ── index ────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_active_jobs(): void
    {
        Job::factory()->count(3)->create();
        Job::factory()->inactive()->count(2)->create();

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.index', ['status' => 'active']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Jobs/Index')
                ->has('jobs.data', 3)
                ->has('jobs.links')
                ->has('jobs.meta'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson(route('recruitment.jobs.index'))
            ->assertUnauthorized();
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_returns_job_with_pipeline_stages(): void
    {
        $job = Job::factory()->create();

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.show', $job))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Jobs/Show')
                ->where('job.id', $job->id)
                ->has('job.title')
                ->has('job.reference_number')
                ->has('job.pipeline_stages'));
    }

    public function test_show_returns_404_for_missing_job(): void
    {
        $this->actingAs($this->recruiter)
            ->get(route('recruitment.jobs.show', 999999))
            ->assertNotFound();
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_job_and_redirects(): void
    {
        $payload = [
            'title'            => 'Senior PHP Developer',
            'reference_number' => 'JOB-2026-001',
            'location'         => 'Cape Town',
            'employment_type'  => 'full_time',
            'description'      => 'We are looking for...',
            'requirements'     => '5+ years PHP experience',
            'responsibilities' => 'Build scalable systems',
            'salary_min'       => 50000,
            'salary_max'       => 80000,
            'currency'         => 'ZAR',
            'posted_at'        => now()->toDateTimeString(),
        ];

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.jobs.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_jobs', [
            'title'      => 'Senior PHP Developer',
            'created_by' => $this->recruiter->id,
        ]);
    }

    public function test_store_requires_title(): void
    {
        $this->actingAs($this->recruiter)
            ->from(route('recruitment.jobs.create'))
            ->post(route('recruitment.jobs.store'), [])
            ->assertRedirect()
            ->assertSessionHasErrors(['title']);
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_update_changes_job_fields(): void
    {
        $job = Job::factory()->create();

        $this->actingAs($this->hrManager)
            ->patch(route('recruitment.jobs.update', $job), [
                'title'    => 'Updated Title',
                'is_active'=> false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_jobs', [
            'id'         => $job->id,
            'title'      => 'Updated Title',
            'updated_by' => $this->hrManager->id,
        ]);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_job_with_no_active_applications(): void
    {
        $job = Job::factory()->create();

        $this->actingAs($this->hrManager)
            ->delete(route('recruitment.jobs.destroy', $job))
            ->assertRedirect();

        $this->assertSoftDeleted('recruitment_jobs', ['id' => $job->id]);
    }

    public function test_destroy_is_forbidden_when_job_has_active_applications(): void
    {
        $job         = Job::factory()->create();
        Application::factory()->create(['job_id' => $job->id]);

        $this->actingAs($this->hrManager)
            ->delete(route('recruitment.jobs.destroy', $job))
            ->assertForbidden();
    }
}
