<?php

namespace App\Modules\ESign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use App\Modules\ESign\Services\EnvelopeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Modules\ESign\Models\EsignField;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything waiting for my signature, signed in one pass.
 *
 * A person joining a company signs an appointment letter, a code of conduct, a policy
 * acknowledgement and an IT usage agreement on the same morning. One at a time that is four
 * emails, four links, four codes and four signatures — and by the third nobody is reading, which
 * is the opposite of what a signature is for.
 *
 * Only for people with an account: the list is built from `signers.user_id`, so it can only ever
 * show documents addressed to the person logged in. An outside party has no account and no list;
 * their token opens the one document it was issued for, which is the whole point of a token.
 */
class MySignaturesController extends Controller
{
    public function __construct(
        private readonly ESignService $service,
        private readonly EnvelopeDocumentService $documents,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/ESign/MySignatures', [
            'pending' => $this->pending()->map(fn (Signer $signer) => [
                'signer_id'  => $signer->id,
                'uuid'       => $signer->envelope->uuid,
                'title'      => $signer->envelope->title,
                'type_label' => $signer->envelope->document_type->label(),
                'message'    => $signer->envelope->message,
                'sent_at'    => $signer->envelope->sent_at?->format('d M Y'),
                'expires_at' => $signer->envelope->expires_at?->format('d M Y'),
                'role_label' => $signer->role->label(),
            ])->values(),
            /*
             * The name printed under the mark, offered rather than assumed.
             *
             * There is no `users.name`; a person's name is on their HR record. Defaulted here and
             * editable on the page, because the name somebody signs under is not always the one on
             * the payroll — and the capacity they sign in is on no record at all.
             */
            'signerName' => $this->signerName(),
        ]);
    }

    /** The signer's own name, from their HR record — there is no `users.name`. */
    private function signerName(): string
    {
        $person = Auth::user()?->person;

        return $person
            ? trim(($person->first_name ?? '') . ' ' . ($person->surname ?? ''))
            : '';
    }

    /** One code for the whole batch — see ESignService::sendBatchOtp for why that is safe. */
    public function sendOtp(Request $request): JsonResponse
    {
        $signers = $this->chosen($request);

        $this->service->sendBatchOtp($signers, $request);

        return response()->json([
            'message' => __('esign.flash.code_on_its_way'),
        ]);
    }

    /**
     * Sign everything selected, with one code and one signature.
     *
     * Each document is signed on its own — its own OTP challenge, its own audit entry, its own
     * executed copy — so one that fails does not take the rest with it, and the reply says exactly
     * which ones went through. A batch that half-succeeded in silence would be worse than one that
     * failed outright: you would not know which of ten you still had to sign.
     */
    public function sign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp'            => 'required|string|digits:6',
            'signature_data' => 'required|string',
            'signature_type' => 'required|string|in:drawn,typed',
            'documents'      => 'nullable|array',
            'documents.*'    => 'nullable|string',

            /*
             * Where each signature was put, as fractions of the page.
             *
             * Fractions rather than points, because the signer chose against a preview rendered at
             * whatever width their screen allowed and the stamp is applied at the document's own
             * dimensions — A4 and Letter differ by enough to put a signature off the paper.
             *
             * Recorded rather than merely honoured: the stamped PDF shows where the signature is,
             * but only this says that is where the signer meant to put it.
             */
            'placements'          => 'nullable|array',
            'placements.*.page'   => 'required_with:placements|integer|min:1|max:2000',
            'placements.*.x'      => 'required_with:placements|numeric|between:0,1',
            'placements.*.y'      => 'required_with:placements|numeric|between:0,1',
            'placements.*.width'  => 'required_with:placements|numeric|between:0.01,1',
        ]);

        $signers = $this->chosen($request);
        $signed = [];
        $failed = [];

        foreach ($signers as $signer) {
            try {
                $stamped = $data['documents'][$signer->envelope->uuid] ?? null;

                if ($stamped) {
                    $this->documents->storeExecuted($signer->envelope, $this->decodePdf($stamped));
                }

                $this->service->sign(
                    signer:        $signer,
                    rawCode:       $data['otp'],
                    signatureData: $data['signature_data'],
                    signatureType: $data['signature_type'],
                    request:       $request,
                );

                $this->recordPlacement(
                    $signer,
                    $data['placements'][$signer->envelope->uuid] ?? null,
                );

                $signed[] = $signer->envelope->title;
            } catch (ValidationException $e) {
                // A wrong code fails every document identically, and repeating it ten times tells
                // nobody anything. Reported once, against the batch.
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Bulk signing failed for one envelope', [
                    'signer' => $signer->id, 'error' => $e->getMessage(),
                ]);
                $failed[] = $signer->envelope->title;
            }
        }

        return response()->json([
            'signed' => $signed,
            'failed' => $failed,
            'message' => $failed === []
                ? __('esign.flash.signed_count', ['count' => count($signed)])
                : __('esign.flash.signed_partial', [
                    'done'   => count($signed),
                    'failed' => count($failed),
                    'titles' => implode(', ', $failed),
                ]),
        ]);
    }

    /**
     * Write down where the signature went.
     *
     * `esign_fields` has modelled page, x, y, width and height since the module was built and had
     * never held a row, because nothing ever placed a signature — every bulk signature landed at a
     * fixed spot that nobody chose. The stamped PDF shows where the mark ended up; this is the only
     * thing that says it is where the signer put it.
     *
     * Written after the signature succeeds, never before: a field recorded against a signature that
     * was then refused would be a record of something that did not happen.
     *
     * @param  array{page: int, x: float, y: float, width: float}|null  $placement
     */
    private function recordPlacement($signer, ?array $placement): void
    {
        if ($placement === null) {
            return;
        }

        // The uuid and the clamping to the page are the model's own doing — see its booted().
        EsignField::create([
            'company_id' => $signer->envelope->company_id,
            'envelope_id' => $signer->envelope_id,
            'signer_id' => $signer->id,
            'type' => EsignField::SIGNATURE,
            'page' => (int) $placement['page'],
            'x' => $placement['x'],
            'y' => $placement['y'],
            'width' => $placement['width'],
            // Derived rather than asked for: the placer sizes the box by width and keeps the
            // signature's own proportions, so a height posted alongside would be a second answer
            // to a question that already has one.
            'height' => round($placement['width'] / 3, 4),
            'is_required' => true,
            'completed_at' => now(),
        ]);
    }

    /**
     * The signatures this person still owes.
     *
     * Scoped to the logged-in user throughout. A signer_id posted by a client is checked against
     * this list, never trusted on its own — otherwise anybody could sign anybody's document by
     * guessing an id.
     */
    private function pending()
    {
        return Signer::query()
            ->with('envelope')
            ->where('user_id', Auth::id())
            ->whereNull('signed_at')
            ->whereNull('declined_at')
            ->whereHas('envelope', fn ($q) => $q
                ->where('status', EnvelopeStatus::Sent->value)
                ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->get()
            ->filter(fn (Signer $s) => $s->envelope !== null);
    }

    /** @return \Illuminate\Support\Collection<int, Signer> */
    private function chosen(Request $request)
    {
        $ids = collect($request->input('signer_ids', []))->map(fn ($id) => (int) $id)->filter();

        $chosen = $this->pending()->whereIn('id', $ids->all())->values();

        if ($chosen->isEmpty()) {
            throw ValidationException::withMessages([
                'signer_ids' => __('esign.flash.choose_waiting'),
            ]);
        }

        return $chosen;
    }

    /** The same check the token portal makes: a browser is not a trusted source of PDFs. */
    private function decodePdf(string $dataUri): string
    {
        $encoded = str_contains($dataUri, ',') ? Str::after($dataUri, ',') : $dataUri;
        $bytes   = base64_decode($encoded, true);

        abort_if($bytes === false || ! str_starts_with($bytes, '%PDF-'), 422, __('esign.flash.not_a_pdf'));
        abort_if(strlen($bytes) > EnvelopeDocumentService::MAX_KILOBYTES * 1024, 422, __('esign.flash.too_large'));

        return $bytes;
    }
}
