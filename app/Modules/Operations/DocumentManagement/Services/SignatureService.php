<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Enums\SignerStatus;
use App\Modules\ESign\Models\Envelope;
use App\Modules\ESign\Models\EsignField;
use App\Modules\ESign\Models\Signer;
use App\Modules\ESign\Services\ESignService;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sending a document for signature, and taking the signed copy back.
 *
 * The signing itself is the ESign module's — envelopes, signing order, one-time passcodes, the
 * audit trail, the completion certificate. Rebuilding any of that here would give the platform two
 * signing engines with two audit trails, and the question "is this signature valid" would have two
 * answers.
 *
 * What lives here is the join: putting the document's current bytes into an envelope, keeping the
 * document's own status in step, and writing the executed copy back as a new version so it is
 * versioned, permissioned and searchable like everything else.
 */
class SignatureService
{
    /**
     * The statuses the requirement asks for, mapped from the envelope's own.
     *
     * Partially signed is not a state the envelope stores — it is "in progress with at least one
     * person done", which is what somebody watching actually wants to know.
     */
    public const AWAITING = 'awaiting_signature';
    public const PARTIAL = 'partially_signed';
    public const SIGNED = 'fully_signed';
    public const DECLINED = 'declined';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    public function __construct(private readonly ESignService $esign)
    {
    }

    /**
     * Sends the document's current version for signature.
     *
     * @param  list<array{name:string, email:string, order?:int}>  $signatories
     * @param  list<array{type:string, page?:int, x?:float, y?:float, width?:float, height?:float, signer?:int, label?:string}>  $fields
     * @return array{envelope: ?Envelope, error: ?string}
     */
    public function send(Document $document, array $signatories, array $fields = [], array $options = []): array
    {
        if ($signatories === []) {
            return ['envelope' => null, 'error' => __('document_management.signing.no_signatories')];
        }

        $version = $document->currentVersion;

        if (! $version) {
            return ['envelope' => null, 'error' => __('document_management.signing.no_file')];
        }

        // One at a time. Two open envelopes for the same document would each produce a signed copy,
        // and nothing would say which is the executed one.
        if ($this->isOpen($document)) {
            return ['envelope' => null, 'error' => __('document_management.signing.already_sent')];
        }

        if ($version->mime !== 'application/pdf') {
            // A signing view draws fields onto a rendered page. Anything else would have to be
            // converted first, and converting a document somebody is about to sign changes what
            // they are signing.
            return ['envelope' => null, 'error' => __('document_management.signing.pdf_only')];
        }

        return DB::transaction(function () use ($document, $version, $signatories, $fields, $options) {
            /*
             * The bytes are copied into the envelope's own path.
             *
             * The document can be replaced while signatures are being collected, and what people
             * signed must not change underneath them — the envelope holds the copy that was sent.
             */
            $path = 'esign/documents/' . Str::uuid() . '.pdf';
            $bytes = Storage::disk('documents')->get($version->path);
            Storage::disk('documents')->put($path, $bytes);

            $envelope = $this->esign->create([
                'title' => $document->title,
                'document_type' => \App\Modules\ESign\Enums\DocumentType::Document->value,
                'message' => $options['message'] ?? null,
                'expires_at' => filled($options['expires_at'] ?? null) ? $options['expires_at'] : null,
                'related_type' => Document::class,
                'related_id' => $document->id,
            ], array_map(fn (array $s, int $i) => [
                'name' => $s['name'],
                'email' => $s['email'],
                'phone' => $s['phone'] ?? null,
                'role' => $s['role'] ?? \App\Modules\ESign\Enums\SignerRole::Signer->value,
                'signing_order' => $s['order'] ?? ($i + 1),
            ], $signatories, array_keys($signatories)), Auth::id() ?? 0);

            /*
             * The file and its hash are set directly.
             *
             * Envelope guards these deliberately — they are lifecycle, not something a request may
             * supply — so passing them to create() sets nothing at all and leaves an envelope with
             * no document to sign. Silent, and only visible when somebody opens the signing link.
             */
            $envelope->forceFill([
                'document_path' => $path,
                'disk' => 'documents',
                // What was signed, provable afterwards without keeping a second copy to compare.
                'document_hash' => hash('sha256', (string) $bytes),
            ])->save();

            $this->placeFields($envelope->fresh('signers'), $fields);

            $this->esign->send($envelope->fresh(), Auth::id() ?? 0);

            $document->forceFill([
                'signature_envelope_id' => $envelope->id,
                'signature_status' => self::AWAITING,
            ])->save();

            app(DocumentService::class)->record($document, DocumentEvent::SIGNATURE_REQUESTED, $version, [
                'signatories' => count($signatories),
            ]);

            return ['envelope' => $envelope->fresh(), 'error' => null];
        });
    }

    /**
     * Records where each field sits.
     *
     * A signing request with no fields is still valid — the signer signs the document as a whole,
     * which is how the module worked before placement existed and remains the simplest case.
     *
     * @param  list<array<string, mixed>>  $fields
     */
    private function placeFields(Envelope $envelope, array $fields): void
    {
        $signers = $envelope->signers->values();

        foreach ($fields as $field) {
            if (! in_array($field['type'] ?? '', EsignField::TYPES, true)) {
                continue;
            }

            // Fields are placed against a signatory by their position in the list the caller sent,
            // because the signer rows do not exist until the envelope is created.
            $signer = isset($field['signer']) ? $signers->get((int) $field['signer']) : null;

            EsignField::create([
                'envelope_id' => $envelope->id,
                'signer_id' => $signer?->id,
                'type' => $field['type'],
                'page' => (int) ($field['page'] ?? 1),
                'x' => (float) ($field['x'] ?? 0.1),
                'y' => (float) ($field['y'] ?? 0.8),
                'width' => (float) ($field['width'] ?? 0.25),
                'height' => (float) ($field['height'] ?? 0.06),
                'label' => $field['label'] ?? null,
                'is_required' => (bool) ($field['is_required'] ?? true),
            ]);
        }
    }

    /**
     * Brings the document's status in line with its envelope.
     *
     * Read from the envelope each time rather than kept in step by events. The signing module is
     * reached through a portal, a queue and an expiry sweep, and a status maintained by whoever
     * remembered to fire an event is a status that drifts.
     */
    public function sync(Document $document): ?string
    {
        $envelope = $document->signature_envelope_id ? Envelope::find($document->signature_envelope_id) : null;

        if (! $envelope) {
            return null;
        }

        $status = $this->statusOf($envelope);

        if ($document->signature_status !== $status) {
            $document->forceFill(['signature_status' => $status])->save();

            if ($status === self::SIGNED) {
                $this->storeSignedCopy($document, $envelope);
            }
        }

        return $status;
    }

    /** The requirement's vocabulary, from the envelope's. */
    private function statusOf(Envelope $envelope): string
    {
        $declined = $envelope->signers()->where('status', SignerStatus::Declined->value)->exists();

        if ($declined) {
            return self::DECLINED;
        }

        return match ($envelope->status) {
            EnvelopeStatus::Completed => self::SIGNED,
            EnvelopeStatus::Expired => self::EXPIRED,
            EnvelopeStatus::Voided => self::CANCELLED,
            EnvelopeStatus::InProgress => $envelope->signers()
                ->where('status', SignerStatus::Signed->value)->exists()
                    ? self::PARTIAL
                    : self::AWAITING,
            default => self::AWAITING,
        };
    }

    /**
     * Files the executed copy as a new version of the document.
     *
     * A new version rather than a replacement, so the unsigned original stays: "what did we send
     * them" and "what came back" are different questions, and an audit needs both.
     */
    private function storeSignedCopy(Document $document, Envelope $envelope): void
    {
        /*
         * The executed document, not the certificate.
         *
         * `signed_document_path` is a misleading name for what it holds: the signature
         * certificate, a standalone page listing who signed, when, from which address. It is
         * evidence about the signing, not the signed document — and filing it as "(signed).pdf"
         * gave people a version that did not contain their contract.
         *
         * The signed document is `executed_document_path`: the original pages carrying each
         * signature, stamped in turn as the envelope moves down the signing order. That is the
         * file somebody means when they ask for the signed copy.
         *
         * The certificate remains the fallback for an envelope that has no executed copy — a
         * signing completed before the stamping path existed — because a certificate filed
         * against the document beats nothing at all. It is named for what it is in that case.
         */
        $executed = filled($envelope->executed_document_path);
        $path = $envelope->executed_document_path ?: $envelope->signed_document_path;

        if (blank($path)) {
            return;
        }

        $disk = $envelope->disk ?: 'documents';

        try {
            $bytes = Storage::disk($disk)->get($path);
        } catch (\Throwable) {
            return;
        }

        if (blank($bytes)) {
            return;
        }

        $next = (int) $document->versions()->max('version') + 1;
        $previous = $document->currentVersion;

        $storedName = Str::uuid() . '.pdf';
        $path = 'documents/' . $document->uuid . '/' . $storedName;
        Storage::disk('documents')->put($path, $bytes);

        $version = \App\Modules\Operations\DocumentManagement\Models\DocumentVersion::create([
            'document_id' => $document->id,
            'version' => $next,
            // A signed copy is a new edition of the document, not a correction to it.
            'major' => (int) ($previous?->major ?: 1) + 1,
            'minor' => 0,
            'original_name' => Str::of($document->title)->replaceMatches('/[^\w.\- ]+/u', '_')
                ->limit(180, '')->trim()->value() . ($executed ? ' (signed).pdf' : ' (signature certificate).pdf'),
            'stored_name' => $storedName,
            'disk' => 'documents',
            'path' => $path,
            'extension' => 'pdf',
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
            'change_note' => __('document_management.signing.signed_note'),
            'supersedes_id' => $previous?->id,
            'uploaded_by' => $envelope->created_by,
        ]);

        $document->forceFill([
            'current_version_id' => $version->id,
            'version_count' => $next,
        ])->save();

        app(DocumentService::class)->record($document, DocumentEvent::SIGNED, $version, [
            'envelope' => $envelope->uuid,
        ]);
    }

    /** Whether a signing is still in progress. */
    public function isOpen(Document $document): bool
    {
        return in_array($document->signature_status, [self::AWAITING, self::PARTIAL], true);
    }

    /**
     * Progress, for the screen.
     *
     * @return array{status: ?string, signed: int, total: int, signers: list<array<string, mixed>>}|null
     */
    public function progress(Document $document): ?array
    {
        $envelope = $document->signature_envelope_id ? Envelope::find($document->signature_envelope_id) : null;

        if (! $envelope) {
            return null;
        }

        $signers = $envelope->signers()->orderBy('signing_order')->get();

        return [
            'status' => $this->sync($document),
            'uuid' => $envelope->uuid,
            'sent_at' => $envelope->sent_at?->toDayDateTimeString(),
            'expires_at' => $envelope->expires_at?->toDateString(),
            'signed' => $signers->where('status', SignerStatus::Signed->value)->count(),
            'total' => $signers->count(),
            'signers' => $signers->map(fn (Signer $s) => [
                'name' => $s->name,
                'email' => $s->email,
                'order' => $s->signing_order,
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
                'signed_at' => $s->signed_at?->toDayDateTimeString(),
                'declined_reason' => $s->declined_reason,
            ])->values()->all(),
        ];
    }
}
