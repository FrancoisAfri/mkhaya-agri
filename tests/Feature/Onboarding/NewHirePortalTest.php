<?php

namespace Tests\Feature\Onboarding;

use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * The new hire's own page, reached with a token instead of a login.
 *
 * The token is the credential, so these tests are mostly about the token: that an expired or
 * revoked one stops working, and that holding one person's token gets you exactly one person's
 * data. There is no id in any of these routes precisely so there is nothing to tamper with, and
 * the upload test checks that the id in the *body* cannot be pointed at somebody else either.
 */
class NewHirePortalTest extends TestCase
{
    use RefreshDatabase, MakesCompanies;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('RESET app.company_id');

        // These routes are deliberately throttled — they are unauthenticated and one of them takes
        // a 10MB upload. The rate limiter keeps its counters in the cache, which outlives a single
        // test, so by the eighth request the suite is testing the throttle rather than the portal.
        \Illuminate\Support\Facades\RateLimiter::clear('');
        \Illuminate\Support\Facades\Cache::flush();

        $this->companyId = $this->makeCompany('Portal Tenant');
    }

    private function person(array $attributes = []): HrPerson
    {
        return HrPerson::factory()->create(array_merge([
            'company_id' => $this->companyId,
        ], $attributes));
    }

    private function requirement(HrPerson $person, array $attributes = []): OnboardingDocument
    {
        return OnboardingDocument::create(array_merge([
            'hr_person_id' => $person->id,
            'document_type' => 'id_document',
            'label' => 'South African ID copy',
            'is_required' => true,
            'status' => 'pending',
            // A real foreign key: the row needs an author even though the hire uploading against
            // it has no account of their own.
            'created_by' => \App\Core\User\Models\User::factory()->create(['company_id' => $this->companyId])->id,
            'company_id' => $this->companyId,
        ], $attributes));
    }

    public function test_a_valid_token_opens_the_new_hires_page(): void
    {
        $person = $this->person();
        $token = $person->issuePortalToken();

        $this->get(route('onboarding.portal.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Modules/Onboarding/NewHirePortal'));
    }

    public function test_no_login_is_needed(): void
    {
        $person = $this->person();
        $token = $person->issuePortalToken();

        // Explicitly nobody: the whole point is that the hire has no account yet.
        $this->assertGuest();
        $this->get(route('onboarding.portal.show', $token))->assertOk();
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get(route('onboarding.portal.show', 'not-a-real-token'))->assertNotFound();
    }

    /** An onboarding link that still works two years later is a standing route to an ID document. */
    public function test_an_expired_token_stops_working(): void
    {
        $person = $this->person();
        $token = $person->issuePortalToken();

        $person->forceFill(['portal_token_expires_at' => now()->subDay()])->save();

        $this->get(route('onboarding.portal.show', $token))->assertNotFound();
    }

    /** Re-issuing is how a link forwarded to the wrong person gets revoked. */
    public function test_reissuing_invalidates_the_previous_link(): void
    {
        $person = $this->person();
        $first = $person->issuePortalToken();
        $second = $person->fresh()->issuePortalToken();

        $this->assertNotSame($first, $second);
        $this->get(route('onboarding.portal.show', $first))->assertNotFound();
        $this->get(route('onboarding.portal.show', $second))->assertOk();
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $person = $this->person();
        $token = $person->issuePortalToken();

        $person->revokePortalToken();

        $this->get(route('onboarding.portal.show', $token))->assertNotFound();
    }

    public function test_the_hire_can_upload_against_their_own_requirement(): void
    {
        $person = $this->person();
        $token = $person->issuePortalToken();
        $document = $this->requirement($person);

        $this->post(route('onboarding.portal.documents.upload', $token), [
            'document_id' => $document->id,
            'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 identity'),
        ])->assertRedirect();

        $document->refresh();

        // Stored on the shared disk now rather than in the row; hasBytes() is the question the
        // application asks, so it is the one worth asserting.
        $this->assertTrue($document->hasBytes(), 'The document should have been stored.');
        $this->assertSame('submitted', $document->status?->value ?? $document->status);
    }

    /**
     * The document id in the body is a number the sender chose.
     *
     * Looking it up globally rather than through the person's own relation would let anyone holding
     * any valid token upload against anyone else's requirement.
     */
    public function test_a_hire_cannot_upload_against_someone_elses_requirement(): void
    {
        $mine = $this->person();
        $token = $mine->issuePortalToken();

        $theirs = $this->person();
        $theirDocument = $this->requirement($theirs);

        $this->post(route('onboarding.portal.documents.upload', $token), [
            'document_id' => $theirDocument->id,
            'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 identity'),
        ])->assertNotFound();

        $this->assertNull($theirDocument->fresh()->file_data);
    }

    /** A resubmission answers the last rejection, so the old reason must not linger beside it. */
    public function test_uploading_again_clears_a_previous_rejection_reason(): void
    {
        $person = $this->person();
        $token = $person->issuePortalToken();
        $document = $this->requirement($person, [
            'status' => 'rejected',
            'rejection_reason' => 'The photograph is unreadable.',
        ]);

        $this->post(route('onboarding.portal.documents.upload', $token), [
            'document_id' => $document->id,
            'file' => UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 clearer'),
        ])->assertRedirect();

        $this->assertNull($document->fresh()->rejection_reason);
    }

    /** Visiting records that they did, which is how HR knows whether to chase. */
    public function test_visiting_records_that_the_hire_has_seen_it(): void
    {
        $person = $this->person();
        $token = $person->issuePortalToken();

        $this->assertNull($person->portal_last_seen_at);

        $this->get(route('onboarding.portal.show', $token))->assertOk();

        $this->assertNotNull($person->fresh()->portal_last_seen_at);
    }
}
