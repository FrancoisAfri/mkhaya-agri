<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Contracts\Providers\DocumentStore;
use App\Modules\Operations\ProjectManagement\Models\DocumentBlob;
use App\Modules\Operations\ProjectManagement\Models\DocumentSignature;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Providers\Local\LocalDocumentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Document revisions and signatures (PM-1501, PM-1502).
 *
 * ── Why supersession rather than overwriting ─────────────────────────────────
 *
 * A drawing at revision C does not replace revision B; somebody built to B, and when the work is
 * wrong the question is always "which revision were they given, and when". So a new revision is a
 * new row, the old row is stamped `superseded_at`, and the two are joined by `supersedes_id`. The
 * document's public id — the external ref uuid — never changes, so every link, task attachment and
 * evidence event written against it keeps resolving, and resolves to the current revision.
 *
 * ── Why signatures carry a hash ──────────────────────────────────────────────
 *
 * A signature is a statement about specific bytes. `content_sha256` records the bytes as they were
 * at signing, so uploading a new revision afterwards does not quietly inherit the old signatures —
 * they stay attached to what was actually signed, and the document reads as unsigned again.
 *
 * ── Why this is local-store only ─────────────────────────────────────────────
 *
 * With the Documents module licensed, revisions and signing are that module's job and duplicating
 * them here would create two version histories that disagree. `supportsVersioning()` says which
 * world we are in, and the screen hides the controls rather than offering something that would
 * silently do nothing.
 */
class DocumentVersionService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly InternalEmitter $emitter,
    ) {
    }

    public function supportsVersioning(): bool
    {
        return $this->providers->for(DocumentStore::class) instanceof LocalDocumentStore;
    }

    /**
     * Upload a new revision of an existing document.
     *
     * Storing goes through the DocumentStore so the file is validated, compressed and shaped
     * exactly like any other upload; this method then re-points the existing document at the new
     * blob and discards the throwaway ref that `put()` created for it. Doing it that way rather
     * than reaching into FileStorage directly keeps one upload path in the module.
     *
     * @return string the document id, which is unchanged by design
     *
     * @throws \InvalidArgumentException when the file is refused by the store
     */
    public function newVersion(
        Project $project,
        string $documentId,
        UploadedFile $file,
        ?string $revision,
        int $userId,
    ): string {
        $this->assertLocal();

        $ref = $this->ref($documentId);
        $current = $this->currentBlob($ref);

        // Outside the transaction: it writes a row of its own, and re-running it on a retry would
        // leave an orphan blob behind either way — but keeping it out keeps the transaction short.
        $newDocumentId = $this->providers->for(DocumentStore::class)
            ->put($project->uuid, $file, ['uploaded_by' => $userId]);

        return DB::transaction(function () use ($ref, $current, $newDocumentId, $revision, $project) {
            $tempRef = $this->ref($newDocumentId);
            // Without the payload column: this only stamps metadata, and pulling a 50 MB base64
            // string into memory to set a version number would be an expensive way to do nothing.
            $fresh = DocumentBlob::where('uuid', $tempRef->source_id)
                ->firstOrFail(DocumentBlob::INDEX_COLUMNS);

            $fresh->version = ($current?->version ?? 0) + 1;
            $fresh->supersedes_id = $current?->id;
            $fresh->revision = $revision ?: $this->nextRevisionLabel($current);
            // A new revision is unsigned. Not carrying the old status over is the whole point of
            // hashing the content: signatures describe bytes, and these are different bytes.
            $fresh->signature_status = null;
            $fresh->esign_envelope_uuid = null;
            $fresh->save();

            if ($current) {
                $current->superseded_at = now();
                $current->save();
            }

            // The document keeps its identity; only what it points at moves.
            $ref->source_id = $fresh->uuid;
            $ref->display_name = $fresh->original_name;
            $ref->snapshot = array_merge($ref->snapshot ?? [], [
                'size'     => $fresh->size,
                'mime'     => $fresh->mime,
                'type'     => $fresh->type,
                'version'  => $fresh->version,
                'revision' => $fresh->revision,
            ]);
            $ref->save();

            // The temporary ref, not the blob: the blob is now the live revision.
            $tempRef->delete();

            // Work done to the previous revision is at risk, which is exactly what this event type
            // exists to make visible on the timeline.
            $this->emitter->emit($project, 'drawing.revised', [
                'subject_type' => 'document',
                'subject_id'   => $ref->uuid,
                'source_ref'   => 'document:' . $ref->uuid . ':v' . $fresh->version,
                'payload'      => [
                    'drawing'   => $fresh->original_name,
                    'revision'  => (string) $fresh->revision,
                    'supersedes' => (string) ($current?->revision ?? ''),
                ],
            ]);

            return $ref->uuid;
        });
    }

    /**
     * Every revision, newest first.
     *
     * Walks `supersedes_id` from the current blob rather than filtering by name, because a revision
     * is frequently uploaded under a different filename and matching on names would split one
     * document's history into two.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $documentId): array
    {
        if (! $this->supportsVersioning()) {
            return [];
        }

        $blob = $this->currentBlob($this->ref($documentId));
        $chain = [];

        // Bounded: a corrupt chain that pointed at itself would otherwise spin here forever.
        for ($guard = 0; $blob && $guard < 200; $guard++) {
            $chain[] = [
                'version'      => (int) $blob->version,
                'revision'     => $blob->revision,
                'name'         => $blob->original_name,
                'size'         => (int) $blob->size,
                'sha256'       => $blob->sha256,
                'uploaded_by'  => $blob->uploaded_by,
                'uploaded_at'  => optional($blob->created_at)->toIso8601String(),
                'superseded_at' => optional($blob->superseded_at)->toIso8601String(),
                'is_current'   => $blob->superseded_at === null,
            ];

            $blob = $blob->supersedes_id
                ? DocumentBlob::whereKey($blob->supersedes_id)->first(DocumentBlob::INDEX_COLUMNS)
                : null;
        }

        return $chain;
    }

    /**
     * Open a signing round.
     *
     * A fresh envelope each time, so re-sending after a revision starts clean instead of inheriting
     * signatures given against bytes that are no longer there.
     *
     * @param  list<array{name:string,email?:string,user_id?:int,role?:string}>  $signers
     */
    public function sendForSignature(Project $project, string $documentId, array $signers): string
    {
        $this->assertLocal();

        if ($signers === []) {
            throw new RuntimeException(__('project_management.documents.no_signers'));
        }

        $ref = $this->ref($documentId);
        $blob = $this->currentBlob($ref);

        if (! $blob) {
            throw new RuntimeException(__('project_management.documents.missing'));
        }

        return DB::transaction(function () use ($ref, $blob, $signers, $project) {
            $envelope = (string) Str::uuid();

            // The previous round is withdrawn rather than deleted: that somebody was asked and the
            // request was cancelled is itself part of the record.
            DocumentSignature::where('document_uuid', $ref->uuid)
                ->where('status', 'pending')
                ->update(['status' => 'withdrawn', 'updated_at' => now()]);

            foreach (array_values($signers) as $i => $signer) {
                DocumentSignature::create([
                    'document_uuid' => $ref->uuid,
                    'blob_id'       => $blob->id,
                    'envelope_uuid' => $envelope,
                    'sign_order'    => $i + 1,
                    'user_id'       => $signer['user_id'] ?? null,
                    'signer_name'   => Str::limit((string) $signer['name'], 190, ''),
                    'signer_email'  => $signer['email'] ?? null,
                    'role'          => $signer['role'] ?? null,
                    'status'        => 'pending',
                ]);
            }

            $blob->esign_envelope_uuid = $envelope;
            $blob->signature_status = 'pending';
            $blob->save();

            $this->emitter->emit($project, 'document.uploaded', [
                'subject_type' => 'document',
                'subject_id'   => $ref->uuid,
                'source_ref'   => 'document:' . $ref->uuid . ':esign:' . $envelope,
                'payload'      => ['document' => $ref->display_name, 'kind' => 'sent_for_signature'],
            ]);

            return $envelope;
        });
    }

    /**
     * Sign.
     *
     * The order is enforced: with two signatories the second cannot sign before the first, because
     * a countersignature given before the signature it counter-signs is not a countersignature.
     */
    public function sign(
        Project $project,
        string $documentId,
        int $userId,
        string $typedName,
        ?string $ip = null,
        ?string $userAgent = null,
    ): DocumentSignature {
        $this->assertLocal();

        return DB::transaction(function () use ($project, $documentId, $userId, $typedName, $ip, $userAgent) {
            $ref = $this->ref($documentId);
            $blob = $this->currentBlob($ref);

            $mine = DocumentSignature::where('document_uuid', $ref->uuid)
                ->where('envelope_uuid', (string) $blob?->esign_envelope_uuid)
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->orderBy('sign_order')
                ->lockForUpdate()
                ->first();

            if (! $mine) {
                throw new RuntimeException(__('project_management.documents.not_a_signer'));
            }

            $blocking = DocumentSignature::where('envelope_uuid', $mine->envelope_uuid)
                ->where('sign_order', '<', $mine->sign_order)
                ->where('status', 'pending')
                ->exists();

            if ($blocking) {
                throw new RuntimeException(__('project_management.documents.awaiting_earlier_signer'));
            }

            $mine->fill([
                'status'         => 'signed',
                'signed_at'      => now(),
                'typed_name'     => Str::limit($typedName, 190, ''),
                // The hash of what was in front of them, taken from the blob rather than recomputed
                // — recomputing later would only ever prove the file matches itself.
                'content_sha256' => $blob?->sha256,
                'ip_address'     => $ip,
                'user_agent'     => Str::limit((string) $userAgent, 255, ''),
            ])->save();

            $outstanding = DocumentSignature::where('envelope_uuid', $mine->envelope_uuid)
                ->where('status', 'pending')
                ->exists();

            if ($blob && ! $outstanding) {
                $blob->signature_status = 'signed';
                $blob->save();

                $this->emitter->emit($project, 'document.approved', [
                    'subject_type' => 'document',
                    'subject_id'   => $ref->uuid,
                    'source_ref'   => 'document:' . $ref->uuid . ':signed:' . $mine->envelope_uuid,
                    'payload'      => [
                        'document'    => (string) $ref->display_name,
                        'approved_by' => $mine->signer_name,
                    ],
                ]);
            }

            return $mine;
        });
    }

    public function decline(string $documentId, int $userId, string $reason): DocumentSignature
    {
        $this->assertLocal();

        return DB::transaction(function () use ($documentId, $userId, $reason) {
            $ref = $this->ref($documentId);
            $blob = $this->currentBlob($ref);

            $mine = DocumentSignature::where('document_uuid', $ref->uuid)
                ->where('envelope_uuid', (string) $blob?->esign_envelope_uuid)
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $mine) {
                throw new RuntimeException(__('project_management.documents.not_a_signer'));
            }

            $mine->fill([
                'status'         => 'declined',
                'decline_reason' => Str::limit($reason, 500, ''),
                'signed_at'      => now(),
            ])->save();

            // One refusal ends the round: the remaining signatories are being asked to sign
            // something that is not going ahead.
            if ($blob) {
                $blob->signature_status = 'declined';
                $blob->save();
            }

            return $mine;
        });
    }

    /**
     * The signature panel for a document.
     *
     * @return array{status:?string, envelope:?string, content_changed:bool, signers:list<array<string,mixed>>}
     */
    public function signatures(string $documentId): array
    {
        if (! $this->supportsVersioning()) {
            return ['status' => null, 'envelope' => null, 'content_changed' => false, 'signers' => []];
        }

        $ref = $this->ref($documentId);
        $blob = $this->currentBlob($ref);

        $signers = DocumentSignature::where('document_uuid', $ref->uuid)
            ->when($blob?->esign_envelope_uuid, fn ($q, $e) => $q->where('envelope_uuid', $e))
            ->orderBy('sign_order')
            ->get();

        return [
            'status'   => $blob?->signature_status,
            'envelope' => $blob?->esign_envelope_uuid,
            // True when something was signed against bytes that are no longer the current ones. The
            // screen says so rather than showing a tick over a document nobody signed.
            'content_changed' => $signers->contains(
                fn (DocumentSignature $s) => $s->status === 'signed' && ! $s->matchesContent($blob?->sha256)
            ),
            'signers'  => $signers->map(fn (DocumentSignature $s) => [
                'id'       => $s->uuid,
                'order'    => $s->sign_order,
                'name'     => $s->signer_name,
                'email'    => $s->signer_email,
                'role'     => $s->role,
                'user_id'  => $s->user_id,
                'status'   => $s->status,
                'signed_at' => optional($s->signed_at)->toIso8601String(),
                'reason'   => $s->decline_reason,
            ])->all(),
        ];
    }

    /** Whether this user is the one currently being waited on. */
    public function awaitingSignatureFrom(string $documentId, int $userId): bool
    {
        if (! $this->supportsVersioning()) {
            return false;
        }

        $blob = $this->currentBlob($this->ref($documentId));

        if (! $blob?->esign_envelope_uuid) {
            return false;
        }

        $mine = DocumentSignature::where('envelope_uuid', $blob->esign_envelope_uuid)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->orderBy('sign_order')
            ->first();

        return $mine !== null && ! DocumentSignature::where('envelope_uuid', $blob->esign_envelope_uuid)
            ->where('sign_order', '<', $mine->sign_order)
            ->where('status', 'pending')
            ->exists();
    }

    private function ref(string $documentId): ExternalRef
    {
        return ExternalRef::where('uuid', $documentId)
            ->where('source_entity', 'document')
            ->firstOrFail();
    }

    private function currentBlob(ExternalRef $ref): ?DocumentBlob
    {
        return $ref->source_id
            ? DocumentBlob::where('uuid', $ref->source_id)->first(DocumentBlob::INDEX_COLUMNS)
            : null;
    }

    /**
     * A, B, C … the construction convention, not v1/v2.
     *
     * Only used when the uploader did not give one. Past Z it falls back to the version number,
     * which is ugly but honest — a document on its twenty-seventh revision has bigger problems.
     */
    private function nextRevisionLabel(?DocumentBlob $current): string
    {
        $version = ($current?->version ?? 0) + 1;

        return $version <= 26 ? chr(64 + $version) : (string) $version;
    }

    private function assertLocal(): void
    {
        if (! $this->supportsVersioning()) {
            throw new RuntimeException(__('project_management.documents.versioning_unavailable'));
        }
    }
}
