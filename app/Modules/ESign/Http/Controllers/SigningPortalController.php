<?php

namespace App\Modules\ESign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Http\JsonResponse;
use App\Modules\ESign\Services\EnvelopeDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class SigningPortalController extends Controller
{
    public function __construct(
        private readonly ESignService $service,
        private readonly EnvelopeDocumentService $documents,
    ) {}

    public function view(string $token, Request $request)
    {
        $signer   = $this->openFor($token);
        $envelope = $signer->envelope;

        /*
         * A colleague signs as themselves.
         *
         * An internal signer has an account, so the link should put them behind it rather than
         * treat them as a stranger who happens to hold a token. It also means the audit trail can
         * say the signature came from a logged-in session, which is the difference between "this
         * token was used" and "this person signed".
         *
         * External signers are left alone: they have no account, and sending them to a login page
         * is how a signing request dies.
         */
        if ($signer->user_id && ! Auth::check()) {
            return redirect()->guest(route('login'));
        }

        $this->service->recordView($signer, $request);

        return Inertia::render('Modules/ESign/Portal/Sign', [
            'envelope' => [
                'uuid'           => $envelope->uuid,
                'title'          => $envelope->title,
                'document_type'  => $envelope->document_type->value,
                'type_label'     => $envelope->document_type->label(),
                'message'        => $envelope->message,
                'status'         => $envelope->status->value,
                'expires_at'     => $envelope->expires_at?->format('d M Y, H:i'),
                // Whether there is anything to read. Without it the page cannot tell "still
                // loading" from "this envelope was sent empty", and drew an endless spinner.
                'has_document'   => $this->documents->hasDocument($envelope),
            ],
            'signer' => [
                'uuid'           => $signer->uuid,
                'name'           => $signer->name,
                'role'           => $signer->role->value,
                'role_label'     => $signer->role->label(),
                'status'         => $signer->status->value,
                'consent_given'  => $signer->consent_given,
                'is_signed'      => $signer->isSigned(),
                'is_declined'    => $signer->isDeclined(),
            ],
            'token' => $token,
            // Whose paper this is. An external signer is looking at a page from a company they may
            // only half recognise, so it carries the name and mark of the one asking them to sign.
            'company'    => $this->branding(),
            'isInternal' => (bool) $signer->user_id,
        ]);
    }

    /**
     * The name and logo of the company asking for the signature.
     *
     * @return array{name: string, logo: string|null}
     */
    private function branding(): array
    {
        $settings = \App\Modules\Admin\Setup\Models\CompanyIdentity::systemSettings();

        return [
            'name' => $settings['full_company_name'] ?: ($settings['company_name'] ?? config('app.name')),
            'logo' => \App\Modules\Admin\Setup\Models\CompanyIdentity::first()?->company_logo_url ?: null,
        ];
    }

    /**
     * The document, streamed to the signer through their token.
     *
     * The token is the credential — the same one that got them to the page — so the document is
     * reachable by exactly the person the envelope was sent to, and by nobody who has not been
     * given the link.
     */
    public function document(string $token): StreamedResponse
    {
        // Lenient on purpose: having signed something is the strongest reason to want to read it
        // again, and their copy is the executed one.
        $signer = $this->openFor($token);
        $bytes  = $this->documents->currentBytes($signer->envelope);

        abort_unless($bytes !== null, 404, __('esign.flash.no_document'));

        return response()->streamDownload(
            fn () => print($bytes),
            Str::slug($signer->envelope->title ?: 'document') . '.pdf',
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
    }

    public function consent(string $token, Request $request): JsonResponse
    {
        $signer = $this->findSignerOrAbort($token);

        $this->service->recordConsent($signer, $request);

        return response()->json(['message' => __('esign.flash.consent_recorded')]);
    }

    public function requestOtp(string $token, Request $request): JsonResponse
    {
        $signer = $this->findSignerOrAbort($token);

        $this->service->sendOtp($signer, $request);

        return response()->json(['message' => __('esign.flash.otp_sent')]);
    }

    public function sign(string $token, Request $request): JsonResponse
    {
        $signer = $this->findSignerOrAbort($token);

        $data = $request->validate([
            'otp'            => 'required|string|digits:6',
            'signature_data' => 'required|string',
            'signature_type' => 'required|string|in:drawn,typed',
            // The document with this signature burnt into it, as a PDF data URI. Sent by the
            // browser that rendered it, so what is stored is the page the signer was looking at
            // rather than a server's reconstruction of it.
            'document'       => 'nullable|string',
        ]);

        // Stored before the signature is recorded: if the upload fails the signer is still
        // unsigned and can try again, whereas the reverse leaves a signature with no document to
        // account for it.
        if (! empty($data['document'])) {
            $this->documents->storeExecuted($signer->envelope, $this->decodePdf($data['document']));
        }

        $this->service->sign(
            signer:        $signer,
            rawCode:       $data['otp'],
            signatureData: $data['signature_data'],
            signatureType: $data['signature_type'],
            request:       $request,
        );

        return response()->json(['message' => __('esign.flash.signed_thanks')]);
    }

    /**
     * The PDF the browser produced, out of its data URI.
     *
     * Checked rather than trusted: a signer's browser is not a trusted client, and writing
     * whatever arrives into the place the executed document lives would let anybody with a signing
     * link replace the contract with a file of their choosing. %PDF- is the format's own magic
     * number; anything else is refused before it reaches storage.
     */
    private function decodePdf(string $dataUri): string
    {
        $encoded = str_contains($dataUri, ',') ? Str::after($dataUri, ',') : $dataUri;
        $bytes   = base64_decode($encoded, true);

        abort_if($bytes === false || ! str_starts_with($bytes, '%PDF-'), 422, __('esign.flash.not_a_pdf'));
        abort_if(
            strlen($bytes) > EnvelopeDocumentService::MAX_KILOBYTES * 1024,
            422,
            __('esign.flash.too_large'),
        );

        return $bytes;
    }

    public function decline(string $token, Request $request): JsonResponse
    {
        $signer = $this->findSignerOrAbort($token);

        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $signer->status          = \App\Modules\ESign\Enums\SignerStatus::Declined->value;
        $signer->declined_reason = $data['reason'] ?? null;
        $signer->declined_at     = now();
        $signer->save();

        return response()->json(['message' => __('esign.flash.declined')]);
    }

    /**
     * The signer this token belongs to, for *doing* something: consenting, signing, declining.
     *
     * Refuses a token that has already been used, which is right for an action and wrong for
     * simply looking — see openFor() below.
     */
    private function findSignerOrAbort(string $token): Signer
    {
        $signer = $this->openFor($token);

        abort_if(
            $signer->isSigned() || $signer->isDeclined(),
            403,
            __('esign.flash.link_used'),
        );

        abort_if(
            $signer->envelope->expires_at?->isPast(),
            410,
            __('esign.flash.link_expired'),
        );

        return $signer;
    }

    /**
     * The signer this token belongs to, for looking at the page.
     *
     * Someone who has just signed still has the link — in their email, in their history, in the
     * tab they are already on if they refresh. Refusing them a 403 tells a person who did exactly
     * what was asked that their link is "invalid, expired, or already-used", which reads as though
     * the signature failed. They then sign again, or ring somebody.
     *
     * The page has always known how to say "you have signed this"; the guard aborted before it
     * could. So viewing is allowed once the token matches, and the refusals stay on the actions.
     */
    private function openFor(string $token): Signer
    {
        $signer = Signer::where('token', $token)->with('envelope')->firstOrFail();

        // hasValidToken still governs: it checks the token matches, in constant time, and that it
        // has not expired. Only the already-used refusal is dropped here — a link has a life, and
        // having signed does not extend it.
        abort_unless(
            $signer->hasValidToken($token),
            403,
            __('esign.flash.link_invalid'),
        );

        return $signer;
    }
}
