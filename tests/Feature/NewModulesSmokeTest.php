<?php

namespace Tests\Feature;

use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\CandidateScreening;
use App\Modules\HR\Recruitment\Services\CandidateScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewModulesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_screening_runs_end_to_end(): void
    {
        $candidate = Candidate::factory()->create(['ai_consent_at' => now()]);

        $screening = app(CandidateScreeningService::class)->screen($candidate);

        $this->assertInstanceOf(CandidateScreening::class, $screening);
        $this->assertDatabaseHas('candidate_screenings', ['candidate_id' => $candidate->id]);
    }

    public function test_screening_is_skipped_without_consent(): void
    {
        $candidate = Candidate::factory()->create(['ai_consent_at' => null]);

        $screening = app(CandidateScreeningService::class)->screen($candidate);

        $this->assertSame('skipped_no_consent', $screening->status->value);
    }
}
