<?php

namespace Tests\Feature\Recruitment\Controllers;

use App\Modules\HR\Recruitment\Jobs\DetectDuplicateCandidate;
use App\Modules\HR\Recruitment\Jobs\ParseCandidateCv;
use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Recruitment\RecruitmentTestCase;

class CandidateControllerTest extends RecruitmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        Queue::fake();
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_candidates_excluding_duplicates(): void
    {
        $original = Candidate::factory()->count(3)->create();
        Candidate::factory()->create(['is_duplicate_of' => $original->first()->id]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.candidates.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Candidates/Index')
                ->has('candidates.data', 3));
    }

    public function test_index_supports_keyset_pagination_via_cursor(): void
    {
        $candidates = Candidate::factory()->count(5)->create();
        $cursor     = $candidates->get(1)->id;

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.candidates.index', ['cursor' => $cursor]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Candidates/Index')
                ->where('candidates.data', fn ($items) => collect($items)->every(fn ($c) => $c['id'] > $cursor)));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_candidate_and_dispatches_duplicate_check(): void
    {
        $payload = [
            'first_name'  => 'Sipho',
            'last_name'   => 'Dlamini',
            'email'       => 'sipho.dlamini@test.com',
            'phone'       => '0821234567',
            'nationality' => 'South African',
            'gender'      => 'male',
        ];

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.candidates.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseHas('recruitment_candidates', [
            'email' => 'sipho.dlamini@test.com',
        ]);

        Queue::assertPushedOn('default', DetectDuplicateCandidate::class);
    }

    public function test_store_requires_email(): void
    {
        $this->actingAs($this->recruiter)
            ->from(route('recruitment.candidates.index'))
            ->post(route('recruitment.candidates.store'), [
                'first_name' => 'Sipho',
                'last_name'  => 'Dlamini',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['email']);
    }

    // ── show ─────────────────────────────────────────────────────────────────

    public function test_show_returns_candidate_with_loaded_relations(): void
    {
        $candidate = Candidate::factory()->create();

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.candidates.show', $candidate))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Candidates/Show')
                ->where('candidate.id', $candidate->id)
                ->has('candidate.uuid')
                ->has('candidate.first_name')
                ->has('candidate.last_name')
                ->has('candidate.email'));
    }

    public function test_show_does_not_expose_cv_path_or_id_number(): void
    {
        $candidate = Candidate::factory()->create([
            'id_number' => '8801015009087',
        ]);

        $this->actingAs($this->recruiter)
            ->get(route('recruitment.candidates.show', $candidate))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Modules/Recruitment/Candidates/Show')
                ->missing('candidate.cv_path')
                ->missing('candidate.id_number')
                ->missing('candidate.ai_consent_ip'));
    }

    // ── CV upload ─────────────────────────────────────────────────────────────

    public function test_upload_cv_stores_file_and_dispatches_parse_job(): void
    {
        $candidate = Candidate::factory()->create();
        $file      = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.candidates.cv.upload', $candidate), ['cv' => $file])
            ->assertRedirect();

        Queue::assertPushedOn('ai-heavy', ParseCandidateCv::class);
        $this->assertDatabaseHas('candidate_documents', [
            'candidate_id' => $candidate->id,
            'type'         => 'cv',
        ]);
    }

    public function test_upload_cv_rejects_non_pdf_docx_files(): void
    {
        $candidate = Candidate::factory()->create();
        $file      = UploadedFile::fake()->create('malware.exe', 50, 'application/octet-stream');

        $this->actingAs($this->recruiter)
            ->from(route('recruitment.candidates.show', $candidate))
            ->post(route('recruitment.candidates.cv.upload', $candidate), ['cv' => $file])
            ->assertRedirect()
            ->assertSessionHasErrors(['cv']);
    }

    // ── consent ───────────────────────────────────────────────────────────────

    public function test_consent_sets_ai_consent_at_and_ip(): void
    {
        $candidate = Candidate::factory()->create(['ai_consent_at' => null]);

        $this->actingAs($this->recruiter)
            ->post(route('recruitment.candidates.consent', $candidate))
            ->assertRedirect();

        $candidate->refresh();
        $this->assertNotNull($candidate->ai_consent_at);
        $this->assertNotNull($candidate->ai_consent_ip);
    }
}
