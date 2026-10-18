<?php

/*
 * E-Sign messages the server sends back.
 *
 * The signing portal's half of these is read by people outside the business — a contractor, a
 * client's director, somebody's next of kin — who have no account here and no reason to read
 * English. Leaving them hardcoded meant the one screen aimed squarely at outsiders was the one
 * screen that never spoke their language.
 */
return [
    'flash' => [
        'created' => 'Envelope created.',
        'sent' => 'Envelope sent to all signers.',
        'voided' => 'Envelope voided.',
        'uploaded' => 'Document uploaded.',
        'needs_document' => 'Upload the document before sending this envelope.',
        'draft_only' => 'The document can only be changed while the envelope is a draft.',
        'no_document' => 'This envelope has no document.',
        'certificate_pending' => 'Certificate not yet generated.',
        'certificate_missing' => 'Certificate file not found.',
        'pick_colleague' => 'Choose somebody from your organisation.',
        'choose_waiting' => 'Choose at least one document that is waiting for you.',
        'code_on_its_way' => 'A code is on its way to your email. It covers every document you selected.',
        'signed_count' => 'Signed :count document(s).',
        'signed_partial' => 'Signed :done, but :failed could not be completed: :titles',
        'not_a_pdf' => 'That is not a PDF.',
        'too_large' => 'The signed document is too large.',
        'consent_recorded' => 'Consent recorded.',
        'otp_sent' => 'OTP sent to your registered email.',
        'signed_thanks' => 'Document signed successfully. Thank you.',
        'declined' => 'Signing declined.',
        'link_used' => 'This signing link has already been used.',
        'link_expired' => 'This signing link has expired.',
        'link_invalid' => 'Invalid or expired signing link.',
        'send_draft_only' => 'Only draft envelopes can be sent.',
        'already_signed' => 'Document already signed.',
        'choose_one' => 'Choose at least one document to sign.',
            'some_invites_failed' => 'The envelope was sent, but the invitation could not be emailed to :names. They have no signing link yet.',
            'deleted' => 'Envelope deleted.',
        'delete_draft_or_voided_only' => 'Only a draft or a voided envelope can be deleted. Void this one first — that tells the signers it is cancelled and records why.',
    ],
];
