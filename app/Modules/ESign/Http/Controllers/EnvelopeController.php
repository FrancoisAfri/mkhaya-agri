<?php

namespace App\Modules\ESign\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ESign\Http\Resources\EnvelopeResource;
use App\Modules\ESign\Enums\EnvelopeStatus;
use Illuminate\Support\Facades\Auth;
use App\Modules\ESign\Concerns\SelectsSigners;
use Illuminate\Validation\ValidationException;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Services\EnvelopeDocumentService;
use App\Modules\ESign\Services\ESignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EnvelopeController extends Controller
{
    use SelectsSigners;

    public function __construct(
        private readonly ESignService $service,
        private readonly EnvelopeDocumentService $documents,
    ) {}

    public function index(): Response
    {
        $envelopes = Envelope::with('signers')
            ->where('created_by', auth()->id())
            ->orWhereHas('signers', fn($q) => $q->where('user_id', auth()->id()))
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('Modules/ESign/Index', [
            'envelopes' => EnvelopeResource::collection($envelopes),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Modules/ESign/Create', [
            // Colleagues are picked, not typed. Their name and address are already known, and a
            // hand-typed address is the one thing that quietly sends a contract to nobody.
            'people' => $this->selectableSigners($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // The document is the envelope's whole reason for existing. Optional only so a draft
            // can be started before the PDF is to hand; send() refuses without one.
            'document'      => 'nullable|file|mimetypes:application/pdf|max:' . EnvelopeDocumentService::MAX_KILOBYTES,
            'title'         => 'required|string|max:255',
            'document_type' => 'required|string|in:appointment_letter,disciplinary,contract,offboarding,other',
            'message'       => 'nullable|string|max:2000',
            'expires_at'    => 'nullable|date|after:today',
            'signers'                       => 'required|array|min:1',
            // Internal signers are colleagues, identified by who they are; external ones are
            // strangers to the system, identified by what you type. The two need different fields,
            // so which kind it is has to be part of the payload rather than inferred.
            'signers.*.kind'                => 'nullable|in:internal,external',
            'signers.*.user_id'             => 'nullable|integer|exists:users,id',
            'signers.*.name'                => 'required_unless:signers.*.kind,internal|nullable|string|max:150',
            'signers.*.email'               => 'required_unless:signers.*.kind,internal|nullable|email|max:255',
            'signers.*.phone'               => 'nullable|string|max:30',
            'signers.*.role'                => 'nullable|string|in:employee,manager,hr,witness,authorized_signatory,signer',
            'signers.*.signing_order'       => 'nullable|integer|min:1',
        ]);

        $data['signers'] = $this->resolveSigners($request, $data['signers']);

        $envelope = $this->service->create(
            envelopeData: collect($data)->except(['signers', 'document'])->toArray(),
            signers:      $data['signers'],
            createdBy:    $request->user()->id,
        );

        if ($request->hasFile('document')) {
            $this->documents->store($envelope, $request->file('document'));
        }

        return redirect()->route('esign.envelopes.show', $envelope->uuid)
            ->with('success', __('esign.flash.created'));
    }

    /**
     * Fill in an internal signer from the person they picked.
     *
     * The name and address come from the user record, never from the request: a client that can
     * post a user_id alongside somebody else's email would otherwise send the document to the
     * address of its choosing while the audit trail named a colleague.
     *
     * The id is checked against the people this screen is allowed to offer, not merely against
     * `users`, so a user_id from another tenant is refused rather than silently accepted.
     *
     * @param  array<int, array<string, mixed>>  $signers
     * @return array<int, array<string, mixed>>
     */
    private function resolveSigners(Request $request, array $signers): array
    {
        $allowed = collect($this->selectableSigners($request))->keyBy('id');

        foreach ($signers as $i => $signer) {
            if (($signer['kind'] ?? 'external') !== 'internal') {
                $signers[$i]['user_id'] = null;
                continue;
            }

            $person = $allowed->get((int) ($signer['user_id'] ?? 0));

            if (! $person) {
                throw ValidationException::withMessages([
                    "signers.{$i}.user_id" => __('esign.flash.pick_colleague'),
                ]);
            }

            $signers[$i]['name']  = $person['name'];
            $signers[$i]['email'] = $person['email'];
        }

        return array_map(fn ($s) => collect($s)->except('kind')->all(), $signers);
    }

    public function show(string $uuid): Response
    {
        $envelope = Envelope::where('uuid', $uuid)
            ->with(['signers', 'auditEntries', 'createdBy'])
            ->firstOrFail();

        return Inertia::render('Modules/ESign/Show', [
            'envelope' => (new EnvelopeResource($envelope))->resolve(),
            /*
             * Whether the person reading this is one of the people waiting to sign it.
             *
             * This is the sender's view, and on a small team the sender is usually a signer too. So
             * somebody asked to sign follows the notification, lands here, finds Send, View and
             * Void, and concludes the system will not let them place a signature — it will, on
             * another page, and nothing on this one said so.
             *
             * The same conditions the signing page itself applies, so the button never appears
             * where pressing it would show an empty list.
             */
            'awaitingMySignature' => $envelope->status === EnvelopeStatus::Sent
                && $envelope->signers
                    ->where('user_id', Auth::id())
                    ->whereNull('signed_at')
                    ->whereNull('declined_at')
                    ->isNotEmpty(),
        ]);
    }

    public function send(string $uuid, Request $request): RedirectResponse
    {
        $envelope = Envelope::where('uuid', $uuid)->with('signers')->firstOrFail();

        // Refused rather than sent empty. A signer who opens the link and finds nothing to read
        // will not sign, and will not tell you why — they will simply not come back.
        if (! $this->documents->hasDocument($envelope)) {
            return back()->with('error', __('esign.flash.needs_document'));
        }

        $this->service->send($envelope, $request->user()->id, $request);

        return back()->with('success', __('esign.flash.sent'));
    }

    /**
     * Delete a draft or a voided envelope.
     *
     * Redirects to the list rather than back: the page the request came from is very often the
     * envelope's own, which no longer exists.
     */
    public function destroy(string $uuid, Request $request): RedirectResponse
    {
        $envelope = Envelope::where('uuid', $uuid)->firstOrFail();

        $this->service->delete($envelope, $request->user()->id, $request);

        return redirect()->route('esign.envelopes.index')->with('success', __('esign.flash.deleted'));
    }

    public function void(string $uuid, Request $request): RedirectResponse
    {
        $data     = $request->validate(['reason' => 'required|string|max:500']);
        $envelope = Envelope::where('uuid', $uuid)->firstOrFail();

        $this->service->void($envelope, $request->user()->id, $data['reason'], $request);

        return back()->with('success', __('esign.flash.voided'));
    }

    /**
     * Attach or replace the document on a draft.
     *
     * The upload on the create form is not enough on its own: an envelope is often started before
     * the PDF is signed off internally, and a draft that has one had no way to get it. The screen
     * said "upload a document before sending" and offered nowhere to do it.
     *
     * Drafts only. Once the envelope has gone out, people have been asked to sign a particular
     * document and swapping it underneath them is not an edit, it is a forgery.
     */
    public function uploadDocument(string $uuid, Request $request): RedirectResponse
    {
        $envelope = Envelope::where('uuid', $uuid)->firstOrFail();

        abort_unless(
            $envelope->status === EnvelopeStatus::Draft,
            422,
            __('esign.flash.draft_only'),
        );

        $request->validate([
            'document' => 'required|file|mimetypes:application/pdf|max:' . EnvelopeDocumentService::MAX_KILOBYTES,
        ]);

        $this->documents->store($envelope, $request->file('document'));

        return back()->with('success', __('esign.flash.uploaded'));
    }

    /**
     * The document itself, streamed inline so the browser renders it rather than downloading it.
     *
     * Whoever may see the envelope may see its document — the tenant scope has already decided
     * that by the time the envelope resolves.
     */
    public function document(string $uuid): StreamedResponse
    {
        $envelope = Envelope::where('uuid', $uuid)->firstOrFail();
        $bytes    = $this->documents->currentBytes($envelope);

        abort_unless($bytes !== null, 404, __('esign.flash.no_document'));

        return response()->streamDownload(
            fn () => print($bytes),
            Str::slug($envelope->title ?: 'document') . '.pdf',
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
    }

    public function downloadCertificate(string $uuid): StreamedResponse
    {
        $envelope = Envelope::where('uuid', $uuid)->firstOrFail();

        abort_unless($envelope->signed_document_path, 404, __('esign.flash.certificate_pending'));

        $disk = $envelope->disk ?? 'local';

        abort_unless(Storage::disk($disk)->exists($envelope->signed_document_path), 404, __('esign.flash.certificate_missing'));

        $filename = 'esign-certificate-' . substr($envelope->uuid, 0, 8) . '.pdf';

        return Storage::disk($disk)->download($envelope->signed_document_path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
