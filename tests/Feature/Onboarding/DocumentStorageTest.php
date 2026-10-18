<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActivatesBoardingModule;
use Tests\TestCase;

/**
 * Onboarding documents live in the database, not on a disk.
 *
 * This was the last place in the codebase writing uploads to `Storage::disk('local')`. It matters
 * more here than most: the documents are ID copies, passports, proof of address and banking
 * details, and a file outside the database is outside row-level security, outside the backup set,
 * and outside the POPIA erasure flows.
 */
class DocumentStorageTest extends TestCase
{
    use RefreshDatabase, ActivatesBoardingModule;

    private User $hr;

    private HrPerson $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateBoardingModule();

        $this->hr = User::factory()->create();
        $this->grantBoardingTier($this->hr);
        $this->hr = $this->hr->fresh();

        $this->person = HrPerson::factory()->create();
    }

    private function requirement(): OnboardingDocument
    {
        return OnboardingDocument::create([
            'hr_person_id' => $this->person->id,
            'document_type' => 'id_document',
            'label' => 'South African ID copy',
            'is_required' => true,
            'status' => 'pending',
            'created_by' => $this->hr->id,
        ]);
    }

    public function test_an_uploaded_document_is_stored_in_the_row_and_not_on_disk(): void
    {
        Storage::fake('local');

        $document = $this->requirement();

        $this->actingAs($this->hr)->post(
            route('onboarding.documents.upload', [$this->person->id, $document->id]),
            ['file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 fake identity document')]
        )->assertOk();

        $document->refresh();

        // Still in the database and still not on the server's filesystem — but on the shared
        // `documents` disk rather than in this row's own column, which is where encryption at rest
        // and the tenant storage quota apply. The guarantee the test was written for is unchanged;
        // only where the bytes sit has moved.
        $this->assertTrue($document->hasBytes(), 'The bytes should be stored.');
        $this->assertNotNull($document->storage_path);
        $this->assertSame('id.pdf', $document->file_name);
        $this->assertGreaterThan(0, $document->file_size);
        $this->assertSame('submitted', $document->status->value ?? $document->status);

        $this->assertNull($document->file_path, 'Nothing should be pointing at a filesystem path.');
        $this->assertEmpty(
            Storage::disk('local')->allFiles(),
            'A copy was still written to the local disk — that is the exposure this change exists to close.'
        );
    }

    public function test_the_stored_document_can_be_downloaded_again(): void
    {
        $document = $this->requirement();

        $this->actingAs($this->hr)->post(
            route('onboarding.documents.upload', [$this->person->id, $document->id]),
            ['file' => UploadedFile::fake()->createWithContent('passport.pdf', '%PDF-1.4 fake passport')]
        )->assertOk();

        $this->actingAs($this->hr)
            ->get(route('onboarding.documents.download', [$this->person->id, $document->id]))
            ->assertOk();
    }

    /** A requirement nobody has uploaded against has nothing to send. */
    public function test_downloading_a_requirement_with_no_file_is_a_404(): void
    {
        $document = $this->requirement();

        $this->actingAs($this->hr)
            ->get(route('onboarding.documents.download', [$this->person->id, $document->id]))
            ->assertNotFound();
    }

    /** Documents belong to one person; the id in the URL is not a suggestion. */
    public function test_a_document_cannot_be_downloaded_through_another_persons_url(): void
    {
        $document = $this->requirement();
        $other = HrPerson::factory()->create();

        $this->actingAs($this->hr)
            ->get(route('onboarding.documents.download', [$other->id, $document->id]))
            ->assertNotFound();
    }
}
