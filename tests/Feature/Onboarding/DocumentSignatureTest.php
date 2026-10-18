<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\Esign\Models\Envelope;
use App\Modules\HR\Onboarding\Actions\SendDocumentForSignatureAction;
use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Onboarding documents that are signed rather than merely uploaded.
 *
 * Upload-and-approve is right for an ID copy and wrong for the things a hire is supposed to *agree*
 * to — the contract, the NDA, the code of conduct — where what matters is a signature with an audit
 * trail, which the esign module already provides.
 */
class DocumentSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function document(array $personAttributes = []): OnboardingDocument
    {
        $person = HrPerson::factory()->create(array_merge([
            'first_name' => 'Nomsa',
            'surname' => 'Dlamini',
            'email' => 'nomsa@example.test',
        ], $personAttributes));

        return OnboardingDocument::create([
            'hr_person_id' => $person->id,
            'document_type' => 'other',
            'label' => 'Employment contract',
            'is_required' => true,
            'status' => 'pending',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_sending_a_document_raises_an_envelope_against_it(): void
    {
        $sender = User::factory()->create();
        $document = $this->document();

        $envelope = app(SendDocumentForSignatureAction::class)->execute($document, $sender->id);

        $this->assertSame(OnboardingDocument::class, $envelope->related_type);
        $this->assertSame($document->id, $envelope->related_id);
        $this->assertSame('Employment contract', $envelope->title);
    }

    /** The signer is the hire, addressed by the name and email the person record carries. */
    public function test_the_new_hire_is_the_signer(): void
    {
        $document = $this->document();

        $envelope = app(SendDocumentForSignatureAction::class)
            ->execute($document, User::factory()->create()->id);

        $signer = $envelope->signers()->first();

        $this->assertSame('Nomsa Dlamini', $signer->name);
        $this->assertSame('nomsa@example.test', $signer->email);
    }

    /**
     * Sent, not drafted.
     *
     * Creating an envelope without sending it leaves it in draft with no signing token — which
     * looks identical to "sent" from the onboarding screen and is not.
     */
    public function test_the_envelope_is_actually_sent(): void
    {
        $document = $this->document();

        $envelope = app(SendDocumentForSignatureAction::class)
            ->execute($document, User::factory()->create()->id);

        $this->assertNotSame('draft', $envelope->status?->value ?? $envelope->status);
        $this->assertNotNull($envelope->signers()->first()->token, 'Without a token there is nothing to sign.');
    }

    /** A signature is worthless if it cannot be delivered to the person it is meant to bind. */
    public function test_a_person_with_no_email_is_refused(): void
    {
        // Genuinely without an address: the factory attaches a candidate, and emailAddress()
        // correctly falls back to theirs, so clearing the person's own field is not enough.
        $document = $this->document(['email' => null, 'candidate_id' => null]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(SendDocumentForSignatureAction::class)->execute($document, User::factory()->create()->id);
    }

    public function test_the_envelope_can_be_found_again_from_the_document(): void
    {
        $document = $this->document();
        $action = app(SendDocumentForSignatureAction::class);

        $this->assertNull($action->envelopeFor($document));

        $sent = $action->execute($document, User::factory()->create()->id);

        $this->assertSame($sent->id, $action->envelopeFor($document)?->id);
    }
}
