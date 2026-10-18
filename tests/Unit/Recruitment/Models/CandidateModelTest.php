<?php

namespace Tests\Unit\Recruitment\Models;

use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateModelTest extends TestCase
{
    use RefreshDatabase;

    // ── UUID auto-generation ─────────────────────────────────────────────────

    public function test_uuid_is_auto_generated_on_creation(): void
    {
        $candidate = Candidate::factory()->create();

        $this->assertNotNull($candidate->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $candidate->uuid
        );
    }

    public function test_each_candidate_gets_a_unique_uuid(): void
    {
        $a = Candidate::factory()->create();
        $b = Candidate::factory()->create();

        $this->assertNotSame($a->uuid, $b->uuid);
    }

    // ── AI consent ───────────────────────────────────────────────────────────

    public function test_has_ai_consent_returns_false_when_consent_is_null(): void
    {
        $candidate = Candidate::factory()->create(['ai_consent_at' => null]);

        $this->assertFalse($candidate->hasAiConsent());
    }

    public function test_has_ai_consent_returns_true_when_consent_is_set(): void
    {
        $candidate = Candidate::factory()->withAiConsent()->create();

        $this->assertTrue($candidate->hasAiConsent());
        $this->assertNotNull($candidate->ai_consent_at);
    }

    // ── fullName accessor ────────────────────────────────────────────────────

    public function test_full_name_concatenates_first_and_last_name(): void
    {
        $candidate = Candidate::factory()->make([
            'first_name' => 'Thabo',
            'last_name'  => 'Nkosi',
        ]);

        $this->assertSame('Thabo Nkosi', $candidate->full_name);
    }

    // ── Hidden attributes ────────────────────────────────────────────────────

    public function test_sensitive_fields_are_hidden_from_serialization(): void
    {
        $candidate = Candidate::factory()->create([
            'id_number'     => '8801015009087',
            'date_of_birth' => '1988-01-01',
        ]);

        $array = $candidate->toArray();

        $this->assertArrayNotHasKey('id_number', $array);
        $this->assertArrayNotHasKey('date_of_birth', $array);
        $this->assertArrayNotHasKey('ai_consent_ip', $array);
        $this->assertArrayNotHasKey('cv_path', $array);
    }

    // ── Duplicate detection flag ─────────────────────────────────────────────

    public function test_duplicate_candidate_has_is_duplicate_of_set(): void
    {
        $original  = Candidate::factory()->create();
        $duplicate = Candidate::factory()->duplicate($original)->create();

        $this->assertSame($original->id, $duplicate->is_duplicate_of);
    }
}
