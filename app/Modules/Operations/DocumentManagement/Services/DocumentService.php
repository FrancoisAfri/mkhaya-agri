<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Putting documents in, taking them out, and everything that happens to them in between.
 *
 * Two ideas run through this class.
 *
 * A document is not its file. Replacing the file adds a version and leaves the document — its
 * identity, description, sensitivity, place in the tree and audit trail — exactly where it was.
 * Nothing is overwritten, so "what did this look like in March?" is always answerable.
 *
 * Nothing destructive is quiet. Archive, restore and delete all write an event with a person's
 * name against them, and reads are recorded too, because the first question asked of a
 * confidential document is who has opened it.
 */
class DocumentService
{
    /**
     * The matter recorded when a hold is placed through the sensitivity form rather than the
     * register, where nobody was asked for a reference. Named plainly so a register reader can see
     * it was never given one, instead of inferring a case that does not exist.
     */
    public const UNSPECIFIED_MATTER = 'Unspecified';

    public function __construct(private readonly FileTypeGuard $guard)
    {
    }

    /**
     * Stores an uploaded file as a new document.
     *
     * @return array{document: ?Document, error: ?string}
     */
    public function store(UploadedFile $file, array $attributes = []): array
    {
        $settings = DocumentSetting::current();
        $inspection = $this->guard->inspect($file, $settings);

        if (! $inspection['ok']) {
            return ['document' => null, 'error' => $inspection['reason']];
        }

        if ($settings->require_description && blank($attributes['description'] ?? null)) {
            return ['document' => null, 'error' => 'This organisation requires a description on every document.'];
        }

        /*
         * Every storage limit bearing on this upload — the uploader's own, their department's, and
         * the target folder's. Checked before the bytes are written rather than after, because a
         * document written and then rejected leaves the count wrong for whoever looks next.
         *
         * The message names what is full. "Storage limit reached" tells somebody nothing they can
         * act on; "the Marketing folder is full" tells them who to talk to.
         */
        /*
         * What the category asks for. Checked before the bytes are written, and the message names
         * the fields rather than saying "invalid" — "Counterparty and End date are needed" is
         * something somebody can act on.
         */
        $category = ($attributes['category_id'] ?? null)
            ? \App\Modules\Operations\DocumentManagement\Models\DocumentCategory::find($attributes['category_id'])
            : null;

        $rules = app(CategoryRules::class);
        $required = $rules->validate($category, $attributes['custom_fields'] ?? []);

        if (! $required['ok']) {
            return ['document' => null, 'error' => __('document_management.fields.required', [
                'fields' => implode(', ', $required['missing']),
            ])];
        }

        $quota = app(QuotaService::class)->check(
            Auth::user(),
            ($attributes['folder_id'] ?? null) ? DocumentFolder::find($attributes['folder_id']) : null,
            (int) $file->getSize(),
        );

        if (! $quota['ok']) {
            return ['document' => null, 'error' => __('document_management.quotas.full', [
                'name' => $quota['breached']['name'] ?? '',
            ])];
        }

        /*
         * Scanned before anything is written, where the server can scan at all.
         *
         * Refused only on an actual detection: a repository that rejected uploads because no
         * scanner was installed would be unusable, and one that reported every file as clean
         * because nothing looked would be worse — so "unavailable" is recorded as itself and the
         * upload proceeds.
         */
        $scan = app(MalwareScanner::class)->scan($file);

        if ($scan['status'] === MalwareScanner::INFECTED) {
            return ['document' => null, 'error' => __('document_management.uploads.infected', [
                'detail' => $scan['detail'] ?? '',
            ])];
        }

        return DB::transaction(function () use ($file, $attributes, $inspection, $settings, $scan, $rules, $category) {
            $document = Document::create([
                'folder_id'   => $attributes['folder_id'] ?? null,
                'title'       => $this->titleFor($file, $attributes, $settings),
                'description' => $attributes['description'] ?? null,
                'notes'       => $attributes['notes'] ?? null,
                'tags'        => $attributes['tags'] ?? null,
                'status'      => Document::STATUS_ACTIVE,
                'is_confidential'      => (bool) ($attributes['is_confidential'] ?? false),
                'is_legally_sensitive' => (bool) ($attributes['is_legally_sensitive'] ?? false),
                'sensitivity_note'     => $attributes['sensitivity_note'] ?? null,

                /*
                 * Structured metadata, which is what lets a document be found by anything other
                 * than its name. Each is optional — an upload should never be blocked on knowing
                 * an expiry date — and each is asked for on the form because the alternative is a
                 * repository where every search is a search of titles.
                 */
                'category_id'        => $attributes['category_id'] ?? null,
                /*
                 * Numbered from the category's own pattern where it has one, and only where nobody
                 * typed a number in. A document arriving with its number already printed on it
                 * keeps that number — the pattern is for the ones being created here.
                 */
                'document_number'    => $attributes['document_number'] ?? $rules->nextNumber($category),
                'custom_fields'      => $rules->clean($category, $attributes['custom_fields'] ?? []),
                'document_type'      => $attributes['document_type'] ?? null,
                'department_unit_id' => $attributes['department_unit_id'] ?? null,
                // The person accountable for it, defaulting to whoever uploaded it — which is
                // usually right, and is at least someone rather than nobody.
                'owner_id'           => $attributes['owner_id'] ?? Auth::id(),
                'document_date'      => $attributes['document_date'] ?? null,
                'effective_date'     => $attributes['effective_date'] ?? null,
                'expiry_date'        => $attributes['expiry_date'] ?? null,
                'confidentiality'    => $attributes['confidentiality'] ?? null,

                'created_by'  => Auth::id(),
                'updated_by'  => Auth::id(),
            ]);

            $version = $this->writeVersion($document, $file, $inspection, 1, $attributes['change_note'] ?? null);

            $version->forceFill([
                'scan_status' => $scan['status'],
                'scan_detail' => $scan['detail'],
                'scanned_at' => now(),
            ])->save();

            $document->forceFill([
                'current_version_id' => $version->id,
                'version_count'      => 1,
            ])->save();

            $this->queueExtraction($document, $version);

            $this->record($document, DocumentEvent::UPLOADED, $version, [
                'name' => $version->original_name, 'size' => $version->size,
            ]);

            return ['document' => $document->fresh(), 'error' => null];
        });
    }

    /**
     * Replaces the file behind a document, keeping everything else.
     *
     * The previous version is not touched — it stays readable at its own URL, which is the whole
     * point of versioning rather than overwriting.
     *
     * @return array{version: ?DocumentVersion, error: ?string}
     */
    /**
     * Replaces the file, keeping every earlier one.
     *
     * `$major` is the uploader's call and cannot be inferred from the bytes: a corrected typo is
     * 1.1 and a renegotiated contract is 2.0, and only the person who made the change knows which
     * they did. Minor is the default, because most replacements are corrections.
     */
    public function replace(
        Document $document,
        UploadedFile $file,
        ?string $changeNote = null,
        bool $major = false,
    ): array {
        $inspection = $this->guard->inspect($file);
        if (! $inspection['ok']) {
            return ['version' => null, 'error' => $inspection['reason']];
        }

        if ($document->isArchived()) {
            return ['version' => null, 'error' => __('document_management.versions.archived')];
        }

        /*
         * Checked here rather than only in the policy, because this is where the loss would happen.
         * A lock that the upload endpoint honours and the service does not is a lock that any other
         * caller — a job, an import, a future controller — walks straight through.
         */
        if (app(LockService::class)->blocks($document, Auth::user())) {
            return ['version' => null, 'error' => __('document_management.lock.held_by_another')];
        }

        // A new version is new bytes. Versions are kept, so replacing a 2MB file with a 40MB one
        // adds 40MB — a limit that only looked at first uploads would be filled by revisions.
        $quota = app(QuotaService::class)->check(Auth::user(), $document->folder, (int) $file->getSize());

        if (! $quota['ok']) {
            return ['version' => null, 'error' => __('document_management.quotas.full', [
                'name' => $quota['breached']['name'] ?? '',
            ])];
        }

        $scan = app(MalwareScanner::class)->scan($file);

        if ($scan['status'] === MalwareScanner::INFECTED) {
            return ['version' => null, 'error' => __('document_management.uploads.infected', [
                'detail' => $scan['detail'] ?? '',
            ])];
        }

        return DB::transaction(function () use ($document, $file, $inspection, $changeNote, $major, $scan) {
            $previous = $document->currentVersion;
            $next = (int) $document->versions()->max('version') + 1;
            $version = $this->writeVersion($document, $file, $inspection, $next, $changeNote);

            [$majorNumber, $minorNumber] = $this->nextLabel($previous, $major);

            $version->forceFill([
                'major' => $majorNumber,
                'minor' => $minorNumber,
                'supersedes_id' => $previous?->id,
                'scan_status' => $scan['status'],
                'scan_detail' => $scan['detail'],
                'scanned_at' => now(),
            ])->save();

            $document->forceFill([
                'current_version_id' => $version->id,
                'version_count'      => $next,
                'updated_by'         => Auth::id(),

                /*
                 * A replacement is how an expired document gets renewed, so the expiry marker is
                 * cleared. The date itself is not touched — the new certificate has a new expiry
                 * and only the person uploading it knows what it is, so leaving the old one visible
                 * is the prompt to update it.
                 */
                'expired_at' => null,
            ])->save();

            $this->record($document, DocumentEvent::REPLACED, $version, [
                'version' => $version->label(), 'name' => $version->original_name, 'note' => $changeNote,
            ]);

            /*
             * Uploading the new version is what the document was checked out for, so the claim is
             * released rather than left for the person to remember. A lock nobody remembers to
             * release is how a document ends up unavailable for a day for no reason anybody can see.
             */
            if ($document->locked_by !== null) {
                app(LockService::class)->release($document);
            }

            // The new file has to be read, or the previous one's words keep answering searches and
            // questions about a document that no longer says them.
            $this->queueExtraction($document, $version);

            return ['version' => $version, 'error' => null];
        });
    }

    /**
     * Puts an earlier version back as the current one.
     *
     * A new version rather than a pointer moved backwards. The history has to remain a record of
     * what happened, and "we went back to 1.1 on Tuesday" is part of what happened — rewinding the
     * pointer would erase both the restoration and whatever it replaced.
     *
     * The bytes are copied, so the restored version owns its own file and deleting the original
     * later cannot empty it.
     */
    public function restoreVersion(Document $document, DocumentVersion $version, ?string $reason = null): array
    {
        if ($version->document_id !== $document->id) {
            return ['version' => null, 'error' => __('document_management.versions.wrong_document')];
        }

        if ($document->currentVersion && $version->id === $document->currentVersion->id) {
            return ['version' => null, 'error' => __('document_management.versions.already_current')];
        }

        if ($document->isArchived()) {
            return ['version' => null, 'error' => __('document_management.versions.archived')];
        }

        return DB::transaction(function () use ($document, $version, $reason) {
            $bytes = Storage::disk('documents')->get($version->path);
            $previous = $document->currentVersion;

            $next = (int) $document->versions()->max('version') + 1;
            [$majorNumber, $minorNumber] = $this->nextLabel($previous, major: false);

            $storedName = Str::uuid() . '.' . $version->extension;
            $path = 'documents/' . $document->uuid . '/' . $storedName;
            Storage::disk('documents')->put($path, $bytes);

            $restored = DocumentVersion::create([
                'document_id'   => $document->id,
                'version'       => $next,
                'major'         => $majorNumber,
                'minor'         => $minorNumber,
                'original_name' => $version->original_name,
                'stored_name'   => $storedName,
                'disk'          => 'documents',
                'path'          => $path,
                'extension'     => $version->extension,
                'mime'          => $version->mime,
                'declared_mime' => $version->declared_mime,
                'size'          => $version->size,
                // Recomputed from what was written, so it describes the bytes that are there.
                'checksum'      => hash('sha256', (string) $bytes),
                'change_note'   => $reason ?: __('document_management.versions.restored_note', [
                    'version' => $version->label(),
                ]),
                'supersedes_id' => $previous?->id,
                'restored_from_id' => $version->id,
                'uploaded_by'   => Auth::id(),
            ]);

            $document->forceFill([
                'current_version_id' => $restored->id,
                'version_count'      => $next,
                'updated_by'         => Auth::id(),
            ])->save();

            $this->record($document, DocumentEvent::VERSION_RESTORED, $restored, [
                'restored_from' => $version->label(),
                'now' => $restored->label(),
                'reason' => $reason,
            ]);

            return ['version' => $restored, 'error' => null];
        });
    }

    /**
     * The next version label.
     *
     * @return array{0:int,1:int}
     */
    private function nextLabel(?DocumentVersion $previous, bool $major): array
    {
        $currentMajor = (int) ($previous?->major ?: 1);
        $currentMinor = (int) ($previous?->minor ?: 0);

        return $major ? [$currentMajor + 1, 0] : [$currentMajor, $currentMinor + 1];
    }

    /** Archives a document: out of the way, still findable, still readable, entirely reversible. */
    /**
     * Marks one document as replaced by another.
     *
     * Not a version, and not an archive. A policy replaced by next year's policy is a different
     * document — both exist, both are cited, and people hold links to the old one. Archiving it
     * hides the thing somebody was sent; leaving it alone lets it read as current. This is the
     * third option: readable, findable, and plainly not the one in force.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function supersede(Document $old, Document $replacement): array
    {
        if ($old->id === $replacement->id) {
            return ['ok' => false, 'error' => __('document_management.superseded.itself')];
        }

        /*
         * Refused where the replacement is itself superseded. Otherwise a chain forms — A replaced
         * by B replaced by C — and the banner on A sends the reader to B, which sends them to C. The
         * reader has to be pointed at the one in force, so the newer document is fixed first.
         */
        if ($replacement->isSuperseded()) {
            return ['ok' => false, 'error' => __('document_management.superseded.replacement_superseded')];
        }

        DB::transaction(function () use ($old, $replacement) {
            $old->forceFill([
                'status' => Document::STATUS_SUPERSEDED,
                'superseded_by_id' => $replacement->id,
                'superseded_at' => now(),
            ])->save();

            $this->record($old, DocumentEvent::SUPERSEDED, null, ['by' => $replacement->title]);
            $this->record($replacement, DocumentEvent::SUPERSEDES, null, ['replaced' => $old->title]);
        });

        return ['ok' => true, 'error' => null];
    }

    /** Undoes a superseding, for when the wrong document was pointed at. */
    public function unsupersede(Document $document): void
    {
        if (! $document->isSuperseded()) {
            return;
        }

        $document->forceFill([
            'status' => Document::STATUS_ACTIVE,
            'superseded_by_id' => null,
            'superseded_at' => null,
        ])->save();

        $this->record($document, DocumentEvent::RESTORED, null, ['from' => 'superseded']);
    }

    public function archive(Document $document, ?string $reason = null): void
    {
        $document->forceFill([
            'status'         => Document::STATUS_ARCHIVED,
            'archived_at'    => Carbon::now(),
            'archived_by'    => Auth::id(),
            'archive_reason' => $reason,
        ])->save();

        $this->record($document, DocumentEvent::ARCHIVED, null, ['reason' => $reason]);
    }

    public function restore(Document $document): void
    {
        $document->forceFill([
            'status'         => Document::STATUS_ACTIVE,
            'archived_at'    => null,
            'archived_by'    => null,
            'archive_reason' => null,
        ])->save();

        $this->record($document, DocumentEvent::RESTORED);
    }

    /**
     * Removes a document from view, recoverably.
     *
     * A soft delete first, because most deletions are mistakes and the alternative is restoring a
     * database backup. Purging is the separate, permissioned act below.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function delete(Document $document, ?string $reason = null): array
    {
        if (! $document->isDestructible()) {
            // Not a permission question. The hold exists precisely to stop this.
            return ['ok' => false, 'error' => 'This document is marked legally sensitive and cannot be deleted while that hold is in place.'];
        }

        /*
         * Live links stop with it. The public route already refuses a deleted document, so this is
         * belt and braces — but it is also what makes the register honest: a link left showing as
         * active for a document that no longer exists is a question somebody has to chase.
         *
         * Deliberately not undone by restoring the document. Bringing a document back should not
         * silently reopen external access that was issued before it was deleted.
         */
        app(\App\Modules\Operations\DocumentManagement\Services\ShareService::class)->revokeAllFor($document);

        $this->record($document, DocumentEvent::DELETED, null, ['reason' => $reason]);

        // Captured at the moment of deletion. "Cleaning up duplicates" read a month later is the
        // difference between restoring a document and leaving it where it is.
        $document->forceFill(['deleted_by' => Auth::id(), 'delete_reason' => $reason])->save();
        $document->delete();

        return ['ok' => true, 'error' => null];
    }

    /**
     * Destroys a document and every file it ever held.
     *
     * The event rows are deliberately kept. A record that a document existed and was destroyed, by
     * whom and when, is the only thing standing between a deletion and an allegation.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function purge(Document $document): array
    {
        if (! $document->isDestructible()) {
            return ['ok' => false, 'error' => 'This document is marked legally sensitive and cannot be destroyed while that hold is in place.'];
        }

        app(\App\Modules\Operations\DocumentManagement\Services\ShareService::class)->revokeAllFor($document);

        $versions = $document->versions()->withoutGlobalScopes()->get();

        DB::transaction(function () use ($document, $versions) {
            foreach ($versions as $version) {
                $this->deleteBytes($version);
            }

            $this->record($document, DocumentEvent::PURGED, null, [
                'title' => $document->title, 'versions' => $versions->count(),
            ]);

            $document->versions()->delete();
            $document->forceDelete();
        });

        return ['ok' => true, 'error' => null];
    }

    /**
     * Changes the sensitivity flags, which is itself worth recording.
     *
     * Confidentiality is set here. Legal sensitivity is not: it belongs to the holds, and this
     * routes through them rather than writing the flag a second time. Two writers is how a document
     * ends up flagged with no hold behind it — or, worse, held with nothing in the register to say
     * by whom, which is exactly the question asked when a hold is challenged.
     *
     * Turning it on places a hold; turning it off lifts every hold standing on the document. The
     * flag still moves the way the caller expects, and the register stays complete either way.
     */
    public function setSensitivity(Document $document, bool $confidential, bool $legallySensitive, ?string $note = null): void
    {
        $before = ['confidential' => $document->is_confidential, 'legally_sensitive' => $document->is_legally_sensitive];

        // Resolved here rather than injected: LegalHoldService depends on this one, and a
        // constructor cycle would fail to resolve at all.
        $holds = app(\App\Modules\Operations\DocumentManagement\Services\LegalHoldService::class);

        if ($legallySensitive && ! $document->is_legally_sensitive) {
            $holds->place($document, self::UNSPECIFIED_MATTER, $note);
        } elseif (! $legallySensitive && $document->is_legally_sensitive) {
            foreach ($holds->activeFor($document) as $hold) {
                $holds->release($hold, $note);
            }

            /*
             * A flag with no hold behind it — set directly before holds were records, or by a code
             * path that bypassed them. There is nothing to release, so releasing nothing would
             * leave it flagged forever with no way to lift it. Clearing it here is the only exit,
             * and the event below still records who did it.
             */
            $document->refresh();

            if ($document->is_legally_sensitive) {
                $document->forceFill(['is_legally_sensitive' => false])->save();
            }
        }

        $document->refresh();

        $document->forceFill([
            'is_confidential'      => $confidential,
            'sensitivity_note'     => $note,
            'updated_by'           => Auth::id(),
        ])->save();

        // Lifting a legal hold is the change somebody will be asked about later.
        $this->record($document, DocumentEvent::SENSITIVITY_CHANGED, null, [
            'from' => $before,
            'to' => ['confidential' => $confidential, 'legally_sensitive' => $document->is_legally_sensitive],
            'note' => $note,
        ]);
    }

    /** Reads the bytes of a version, or null where the file has gone missing underneath us. */
    public function contents(DocumentVersion $version): ?string
    {
        try {
            $disk = Storage::disk($version->disk);

            return $disk->exists($version->path) ? $disk->get($version->path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function record(Document $document, string $action, ?DocumentVersion $version = null, array $detail = []): void
    {
        DocumentEvent::create([
            'document_id' => $document->id,
            'version_id'  => $version?->id,
            'action'      => $action,
            'user_id'     => Auth::id(),
            'ip'          => request()?->ip(),
            'user_agent'  => Str::limit((string) request()?->userAgent(), 250, ''),
            'detail'      => $detail ?: null,
            'created_at'  => Carbon::now(),
        ]);
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    private function writeVersion(Document $document, UploadedFile $file, array $inspection, int $version, ?string $note): DocumentVersion
    {
        /*
         * The stored path is generated from values we control — never from the uploaded name.
         *
         * A client-supplied filename is the classic route to writing outside the intended
         * directory, and no amount of sanitising a hostile string is as safe as not using it.
         * The original name is kept as a label, and only as a label.
         */
        $extension = $inspection['extension'];
        $storedName = Str::uuid() . '.' . $extension;
        $path = 'documents/' . $document->uuid . '/' . $storedName;

        Storage::disk('documents')->put($path, file_get_contents($file->getRealPath()));

        return DocumentVersion::create([
            'document_id'   => $document->id,
            'version'       => $version,
            'original_name' => Str::limit($file->getClientOriginalName(), 240, ''),
            'stored_name'   => $storedName,
            'disk'          => 'documents',
            'path'          => $path,
            'extension'     => $extension,
            'mime'          => $inspection['mime'],
            'declared_mime' => $inspection['declared_mime'] ?? null,
            'size'          => (int) $file->getSize(),
            'checksum'      => hash_file('sha256', $file->getRealPath()) ?: null,
            'change_note'   => $note,
            'uploaded_by'   => Auth::id(),
        ]);
    }

    /**
     * Queues the new file to be read.
     *
     * Dispatched after the transaction commits, not inside it. A worker is a separate connection
     * and would look for a version the committing transaction has not yet made visible — the job
     * then finds nothing, succeeds, and the document is silently never searchable by its contents.
     */
    private function queueExtraction(Document $document, DocumentVersion $version): void
    {
        \App\Modules\Operations\DocumentManagement\Jobs\ExtractDocumentText::dispatch(
            $version->id,
            $document->company_id,
        )->afterCommit();
    }

    private function deleteBytes(DocumentVersion $version): void
    {
        try {
            Storage::disk($version->disk)->delete($version->path);
        } catch (\Throwable) {
            // A file already gone is not a reason to abandon the purge; the row still goes.
        }
    }

    /**
     * The document's title.
     *
     * An organisation can insist on a naming convention, in which case the uploader's filename is
     * a starting point rather than the answer.
     */
    private function titleFor(UploadedFile $file, array $attributes, DocumentSetting $settings): string
    {
        if (! blank($attributes['title'] ?? null)) {
            return Str::limit(trim($attributes['title']), 240, '');
        }

        $fallback = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Document';

        if (! $settings->auto_name || blank($settings->naming_pattern)) {
            return Str::limit($fallback, 240, '');
        }

        return Str::limit($this->applyPattern($settings->naming_pattern, $fallback, $attributes), 240, '');
    }

    private function applyPattern(string $pattern, string $original, array $attributes): string
    {
        $folder = $attributes['folder_id'] ?? null;
        $sequence = Document::withoutGlobalScopes()->count() + 1;

        return str_replace(
            ['{original}', '{date}', '{year}', '{month}', '{seq}', '{folder}'],
            [
                $original,
                Carbon::now()->toDateString(),
                Carbon::now()->format('Y'),
                Carbon::now()->format('m'),
                str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
                $folder ? (string) optional(\App\Modules\Operations\DocumentManagement\Models\DocumentFolder::find($folder))->name : '',
            ],
            $pattern,
        );
    }

    /**
     * Copies an existing version's bytes into another document as its first version.
     *
     * Used when a folder is copied. The bytes are duplicated rather than shared: two documents
     * pointing at one file would mean replacing one silently replaced the other, and deleting
     * either would take the file from under its twin.
     *
     * Only the current version travels. A copy is a new document, not a new claim to somebody
     * else's revision history — and the event log below says where it came from, so the lineage is
     * recorded even though the versions are not.
     */
    public function copyVersionInto(Document $document, DocumentVersion $source): DocumentVersion
    {
        $bytes = Storage::disk('documents')->get($source->path);

        $storedName = Str::uuid() . '.' . $source->extension;
        $path = 'documents/' . $document->uuid . '/' . $storedName;

        Storage::disk('documents')->put($path, $bytes);

        $version = DocumentVersion::create([
            'document_id'   => $document->id,
            'version'       => 1,
            'original_name' => $source->original_name,
            'stored_name'   => $storedName,
            'disk'          => 'documents',
            'path'          => $path,
            'extension'     => $source->extension,
            'mime'          => $source->mime,
            'declared_mime' => $source->declared_mime,
            'size'          => $source->size,
            // Recomputed rather than copied: it describes the bytes that were actually written.
            'checksum'      => hash('sha256', (string) $bytes),
            'change_note'   => __('document_management.folders.copied_from', ['title' => $source->document?->title ?? '']),
            'uploaded_by'   => Auth::id(),
        ]);

        $document->forceFill(['current_version_id' => $version->id, 'version_count' => 1])->save();

        $this->record($document, DocumentEvent::UPLOADED, $version, ['copied_from_version' => $source->id]);

        return $version;
    }
}
