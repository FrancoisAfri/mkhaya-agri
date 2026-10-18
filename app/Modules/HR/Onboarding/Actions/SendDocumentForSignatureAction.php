<?php

namespace App\Modules\HR\Onboarding\Actions;

use App\Modules\Esign\Models\Envelope;
use App\Modules\Esign\Services\ESignService;
use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use Illuminate\Http\Request;

/**
 * Send an onboarding document to the new hire for signature.
 *
 * Onboarding documents were upload-and-approve only: the hire sent a scan and somebody in HR looked
 * at it. That is right for an ID copy and wrong for the things they are supposed to *agree* to —
 * the contract, the NDA, the code of conduct — where what matters is a signature with an audit
 * trail behind it, which the esign module already provides.
 *
 * Related to the document by `related_type`/`related_id`, which is the hook ESignService already
 * offers. Neither module needs to know the other's internals, and the envelope can be found again
 * from the document without a new column on either side.
 */
class SendDocumentForSignatureAction
{
    public function __construct(private readonly ESignService $esign)
    {
    }

    public function execute(OnboardingDocument $document, int $sentBy, ?Request $request = null): Envelope
    {
        $person = $document->hrPerson;

        abort_if($person === null, 422, 'This document is not attached to a person.');

        $email = $person->emailAddress();

        // The signature is worthless if it cannot be delivered to the person it is supposed to bind.
        abort_if(
            $email === null || $email === '',
            422,
            'This person has no email address, so a document cannot be sent to them for signature.'
        );

        $envelope = $this->esign->create(
            [
                'title' => $document->label,
                'document_type' => 'onboarding',
                'related_type' => OnboardingDocument::class,
                'related_id' => $document->id,
            ],
            [[
                'name' => $person->fullName(),
                'email' => $email,
                'role' => 'signer',
                'signing_order' => 1,
                // Null for a hire with no login yet, which is the usual case before day one.
                'user_id' => $person->user_id,
            ]],
            $sentBy
        );

        // send() is what issues the signing token; creating an envelope alone leaves it sitting in
        // draft, which looks identical to "sent" from the onboarding screen and is not.
        return $this->esign->send($envelope, $sentBy, $request);
    }

    /** The envelope raised for a document, if one has been. */
    public function envelopeFor(OnboardingDocument $document): ?Envelope
    {
        return Envelope::query()
            ->where('related_type', OnboardingDocument::class)
            ->where('related_id', $document->id)
            ->latest()
            ->first();
    }
}
