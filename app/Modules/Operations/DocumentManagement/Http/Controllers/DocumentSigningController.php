<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Services\ESignService;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Services\SignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Sending a document for signature, and withdrawing the request. */
class DocumentSigningController extends Controller
{
    public function __construct(
        private readonly SignatureService $signatures,
        private readonly ESignService $esign,
    ) {
    }

    public function send(Request $request, Document $document): RedirectResponse
    {
        // Sending changes what the document is for, so it takes the same right as editing it.
        $this->authorize('update', $document);

        $data = $request->validate([
            'signatories' => ['required', 'array', 'min:1'],
            'signatories.*.name' => ['required', 'string', 'max:150'],
            'signatories.*.email' => ['required', 'email', 'max:255'],
            'signatories.*.order' => ['nullable', 'integer', 'min:1'],
            'fields' => ['nullable', 'array'],
            'fields.*.type' => ['required', Rule::in(\App\Modules\ESign\Models\EsignField::TYPES)],
            'fields.*.page' => ['nullable', 'integer', 'min:1'],
            'fields.*.signer' => ['nullable', 'integer', 'min:0'],
            'message' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $result = $this->signatures->send($document, $data['signatories'], $data['fields'] ?? [], [
            'message' => $data['message'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return $result['envelope']
            ? back()->with('success', __('document_management.signing.sent'))
            : back()->with('error', $result['error']);
    }

    /**
     * Withdraws the request.
     *
     * Voided through the signing module rather than by clearing the document's field, so the
     * envelope's own audit trail records that it was cancelled and by whom — a signing request that
     * simply disappeared would leave the people who received the link with no explanation.
     */
    public function cancel(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $envelope = $document->signature_envelope_id ? Envelope::find($document->signature_envelope_id) : null;

        if ($envelope && ! in_array($envelope->status, [EnvelopeStatus::Completed, EnvelopeStatus::Voided], true)) {
            $this->esign->void(
                $envelope,
                Auth::id() ?? 0,
                $request->input('reason') ?: __('document_management.signing.cancelled_reason'),
            );
        }

        $this->signatures->sync($document->fresh());

        return back()->with('success', __('document_management.signing.cancelled'));
    }
}
