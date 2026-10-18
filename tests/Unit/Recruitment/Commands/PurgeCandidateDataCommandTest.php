<?php

namespace Tests\Unit\Recruitment\Commands;

use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeCandidateDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function makeOldCandidate(array $extra = []): Candidate
    {
        $candidate = Candidate::factory()->create($extra);
        DB::table('recruitment_candidates')
            ->where('id', $candidate->id)
            ->update(['updated_at' => now()->subMonths(25)]);
        return $candidate->fresh();
    }

    public function test_anonymises_eligible_candidate(): void
    {
        $candidate = $this->makeOldCandidate();

        Application::factory()->create([
            'candidate_id' => $candidate->id,
            'status'       => 'rejected',
        ]);

        $this->artisan('recruitment:purge-candidate-data --months=24')
            ->assertExitCode(0);

        $fresh = Candidate::withTrashed()->find($candidate->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertNull($fresh->phone);
        $this->assertStringContainsString('@redacted.invalid', $fresh->email);
    }

    public function test_skips_candidate_with_active_application(): void
    {
        $candidate = $this->makeOldCandidate();

        Application::factory()->create([
            'candidate_id' => $candidate->id,
            'status'       => 'shortlisted',
        ]);

        $this->artisan('recruitment:purge-candidate-data --months=24');

        $this->assertNull(Candidate::withTrashed()->find($candidate->id)->deleted_at);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $candidate = $this->makeOldCandidate();

        $this->artisan('recruitment:purge-candidate-data --months=24 --dry-run')
            ->assertExitCode(0);

        $this->assertNull(Candidate::withTrashed()->find($candidate->id)->deleted_at);
    }

    public function test_deletes_cv_from_s3_when_purging(): void
    {
        Storage::disk('s3')->put('cvs/test.pdf', 'data');

        $candidate = $this->makeOldCandidate(['cv_path' => 'cvs/test.pdf']);
        Application::factory()->create(['candidate_id' => $candidate->id, 'status' => 'rejected']);

        $this->artisan('recruitment:purge-candidate-data --months=24');

        Storage::disk('s3')->assertMissing('cvs/test.pdf');
    }

    public function test_recent_candidates_are_not_purged(): void
    {
        $candidate = Candidate::factory()->create();

        $this->artisan('recruitment:purge-candidate-data --months=24');

        $this->assertNull(Candidate::withTrashed()->find($candidate->id)->deleted_at);
    }
}
