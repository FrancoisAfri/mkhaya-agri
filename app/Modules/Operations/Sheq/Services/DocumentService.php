<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Document control (§5.8).
 *
 * Four rules carry the requirement:
 *
 * **Only published versions are visible to readers** (SHEQ-DOC-3). A draft procedure someone found
 * in the system and followed is the failure mode this prevents.
 *
 * **Publishing supersedes rather than replaces** (SHEQ-DOC-1). The old version stays readable —
 * an investigation into last year's incident needs the procedure as it was, not as it is.
 *
 * **Acknowledgements are per version.** Republishing resets them, because "I have read and
 * understood" refers to a specific text. Carrying them forward would let a rewritten procedure
 * inherit consent nobody gave.
 *
 * **Every rendered copy is watermarked** (SHEQ-DOC-3) with version, publish date and "uncontrolled
 * when printed", so a photocopy on a noticeboard can be dated at a glance.
 */
class DocumentService
{
    public function __construct(
        private readonly \App\Modules\Operations\Sheq\Approvals\ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array{id:int, uuid:string, doc_number:string}
     */
    public function create(int $companyId, array $input, ?int $actorId = null): array
    {
        $uuid = (string) Str::uuid();

        $id = DB::table('sheq_documents')->insertGetId([
            'uuid' => $uuid,
            'company_id' => $companyId,
            'doc_number' => $input['doc_number'],
            'title' => $input['title'],
            'doc_type' => $input['doc_type'] ?? 'PROCEDURE',
            'status' => 'DRAFT',
            'owner_id' => $input['owner_id'] ?? $actorId,
            'site_id' => $input['site_id'] ?? null,
            'review_due' => $input['review_due'] ?? Carbon::now()->addYear()->toDateString(),
            'requires_acknowledgement' => $input['requires_acknowledgement'] ?? false,
            'applies_to' => isset($input['applies_to']) ? json_encode($input['applies_to']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'document.created', $id, null,
            ['doc_number' => $input['doc_number'], 'title' => $input['title']]);

        // Raised on creation, so the chain can be signed while the draft is written.
        $this->approvals->openIfConfigured($companyId, [
            'subject_type' => 'DOCUMENT',
            'subject_id' => $id,
            'subject_label' => $input['title'] ?? $input['doc_number'],
            'subject_subtype' => $input['doc_type'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'requested_by' => (int) ($actorId ?? 0),
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'doc_number' => $input['doc_number']];
    }

    /**
     * Draft a new version. Always allowed — drafting the next revision of a published document is
     * normal practice, and it does not disturb what is currently in force.
     *
     * @return array{id:int, version:int}
     */
    public function draftVersion(int $companyId, string $documentUuid, array $input, ?int $actorId = null): array
    {
        $document = $this->find($companyId, $documentUuid);

        $version = (int) DB::table('sheq_document_versions')
            ->where('document_id', $document->id)->max('version') + 1;

        $id = DB::table('sheq_document_versions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'document_id' => $document->id,
            'version' => $version,
            'status' => 'DRAFT',
            'summary_of_changes' => $input['summary_of_changes'] ?? null,
            'file_path' => $input['file_path'] ?? null,
            'mime_type' => $input['mime_type'] ?? null,
            'byte_size' => $input['byte_size'] ?? null,
            'sha256' => $input['sha256'] ?? null,
            'authored_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'version' => $version];
    }

    /**
     * Publish a version: it becomes the one in force, the previous is superseded, and any earlier
     * acknowledgements no longer apply.
     */
    public function publish(int $companyId, string $documentUuid, int $version, ?int $actorId = null): void
    {
        $document = $this->find($companyId, $documentUuid);

        $target = DB::table('sheq_document_versions')
            ->where('document_id', $document->id)->where('version', $version)->first();

        if ($target === null) {
            throw new RuntimeException("Version {$version} does not exist for {$document->doc_number}.");
        }

        if ($target->status === 'PUBLISHED') {
            throw new RuntimeException('That version is already published.');
        }
        /*
         * An approval workflow, where the tenant configured one.
         *
         * The Approvals screen offered a workflow for this subject and nothing read it, so a
         * tenant could build a chain, save it, and watch it ignored. With no workflow configured
         * this is a no-op, so nothing changes for a tenant that has not set one up.
         */
        $blocked = $this->approvals->blockingReason($companyId, 'DOCUMENT', (int) $document->id, $document->doc_type, $document->site_id);

        if ($blocked !== null) {
            throw new RuntimeException($blocked);
        }


        DB::transaction(function () use ($document, $target, $companyId, $actorId) {
            DB::table('sheq_document_versions')
                ->where('document_id', $document->id)
                ->where('status', 'PUBLISHED')
                ->update(['status' => 'SUPERSEDED', 'superseded_at' => now(), 'updated_at' => now()]);

            DB::table('sheq_document_versions')->where('id', $target->id)->update([
                'status' => 'PUBLISHED',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'published_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('sheq_documents')->where('id', $document->id)->update([
                'status' => 'PUBLISHED',
                'current_version' => $target->version,
                'approver_id' => $actorId,
                'published_at' => now(),
                // Re-indexing is required, and the superseded text must leave the index so
                // retrieval cannot answer from a withdrawn procedure (SHEQ-DOC-4).
                'indexed_at' => null,
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'document.published', $document->id,
                ['version' => $document->current_version], ['version' => $target->version]);
        });
    }

    /**
     * Withdraw a document from circulation. It stays readable to those who administer it, and stops
     * being visible to readers — the point is that nobody follows it by accident.
     */
    public function withdraw(int $companyId, string $documentUuid, string $reason, ?int $actorId = null): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Withdrawing a controlled document needs a reason.');
        }

        $document = $this->find($companyId, $documentUuid);

        DB::transaction(function () use ($document, $companyId, $reason, $actorId) {
            DB::table('sheq_documents')->where('id', $document->id)->update([
                'status' => 'WITHDRAWN', 'indexed_at' => null, 'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'document.withdrawn', $document->id,
                ['status' => $document->status], ['status' => 'WITHDRAWN'], $reason);
        });
    }

    /**
     * What a reader may see (SHEQ-DOC-3): the published version, or nothing.
     *
     * @return array<string,mixed>|null
     */
    /**
     * One document as a reader sees it.
     *
     * Deliberately not called by anything, and recorded here so the next audit does not report it
     * as an oversight. The document library answers the same question for a whole list in one
     * query — it needs the audience filter and each version's file anyway — and routing a single
     * document through here as well would be a second definition of "what a reader is shown". The
     * one part of it that was genuinely missing, the watermark, is now built by
     * {@see watermark()} straight from the library's rows.
     *
     * Kept rather than deleted because the API has no single-document read yet and this is the
     * shape it should take when it does: published only, the version in force, nothing about
     * drafts.
     */
    public function readerView(int $companyId, string $documentUuid): ?array
    {
        $document = $this->find($companyId, $documentUuid);

        if ($document->status !== 'PUBLISHED') {
            return null;
        }

        $version = DB::table('sheq_document_versions')
            ->where('document_id', $document->id)->where('status', 'PUBLISHED')->first();

        if ($version === null) {
            return null;
        }

        return [
            'doc_number' => $document->doc_number,
            'title' => $document->title,
            'version' => $version->version,
            'published_at' => $version->published_at,
            'file_path' => $version->file_path,
            'watermark' => $this->watermark($document, $version),
        ];
    }

    /**
     * The watermark every rendered copy carries. Assembled here rather than in a template so the
     * wording cannot drift between the PDF, the print stylesheet and the viewer.
     */
    public function watermark(object $document, object $version): string
    {
        return sprintf(
            '%s v%d · published %s · uncontrolled when printed',
            $document->doc_number,
            $version->version,
            $version->published_at ? Carbon::parse($version->published_at)->toDateString() : 'not yet',
        );
    }

    /**
     * Record that someone has read and understood the version in force (SHEQ-DOC-2).
     *
     * Acknowledging anything other than the current published version is refused: it would create a
     * record that looks like compliance while the person read superseded text.
     */
    public function acknowledge(
        int $companyId, string $documentUuid, int $hrPersonId, ?int $userId = null, array $input = []
    ): int {
        $document = $this->find($companyId, $documentUuid);

        if ($document->status !== 'PUBLISHED') {
            throw new RuntimeException('Only a published document can be acknowledged.');
        }

        $version = DB::table('sheq_document_versions')
            ->where('document_id', $document->id)->where('status', 'PUBLISHED')->first();

        if ($version === null) {
            throw new RuntimeException('This document has no published version.');
        }

        DB::table('sheq_document_acknowledgements')->updateOrInsert(
            ['company_id' => $companyId, 'document_version_id' => $version->id, 'hr_person_id' => $hrPersonId],
            [
                'document_id' => $document->id,
                'user_id' => $userId,
                'acknowledged_at' => Timestamps::utc(now()),
                'signature' => $input['signature'] ?? null,
                'ip_address' => $input['ip_address'] ?? null,
                'comprehension_score' => $input['comprehension_score'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return (int) DB::table('sheq_document_acknowledgements')
            ->where('company_id', $companyId)->where('document_version_id', $version->id)
            ->where('hr_person_id', $hrPersonId)->value('id');
    }

    /**
     * Acknowledgement coverage for the version in force.
     *
     * @param  list<int>  $expectedHrPersonIds  who the document targets
     * @return array{version:?int, expected:int, acknowledged:int, outstanding:list<int>, percent:?float}
     */
    public function acknowledgementStatus(int $companyId, string $documentUuid, array $expectedHrPersonIds): array
    {
        $document = $this->find($companyId, $documentUuid);

        $version = DB::table('sheq_document_versions')
            ->where('document_id', $document->id)->where('status', 'PUBLISHED')->first();

        if ($version === null) {
            return ['version' => null, 'expected' => count($expectedHrPersonIds),
                'acknowledged' => 0, 'outstanding' => $expectedHrPersonIds, 'percent' => null];
        }

        $done = DB::table('sheq_document_acknowledgements')
            ->where('company_id', $companyId)
            ->where('document_version_id', $version->id)
            ->whereNotNull('acknowledged_at')
            ->pluck('hr_person_id')->map(fn ($id) => (int) $id)->all();

        $outstanding = array_values(array_diff($expectedHrPersonIds, $done));
        $expected = count($expectedHrPersonIds);

        return [
            'version' => $version->version,
            'expected' => $expected,
            'acknowledged' => $expected - count($outstanding),
            'outstanding' => $outstanding,
            'percent' => $expected > 0
                ? round((($expected - count($outstanding)) / $expected) * 100, 1) : null,
        ];
    }

    /** Documents whose periodic review has fallen due (SHEQ-DOC-1). */
    public function dueForReview(int $companyId, ?Carbon $asAt = null): array
    {
        $asAt ??= Carbon::today();

        return DB::table('sheq_documents')
            ->where('company_id', $companyId)
            ->where('status', 'PUBLISHED')
            ->whereNotNull('review_due')
            ->where('review_due', '<=', $asAt->toDateString())
            ->whereNull('deleted_at')
            ->orderBy('review_due')
            ->get(['uuid', 'doc_number', 'title', 'current_version', 'review_due', 'owner_id'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Published versions awaiting (re)indexing for retrieval (SHEQ-DOC-4). Returned rather than
     * indexed here: the vector store is infrastructure, and this service should not know which one.
     */
    public function pendingIndexing(int $companyId): array
    {
        return DB::table('sheq_documents as d')
            ->join('sheq_document_versions as v', function ($join) {
                $join->on('v.document_id', '=', 'd.id')->where('v.status', '=', 'PUBLISHED');
            })
            ->where('d.company_id', $companyId)
            ->where('d.status', 'PUBLISHED')
            ->where('d.is_indexed_for_ai', true)
            ->whereNull('d.indexed_at')
            ->whereNull('d.deleted_at')
            ->get(['d.uuid', 'd.doc_number', 'd.title', 'v.version', 'v.file_path'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function find(int $companyId, string $uuid): object
    {
        $document = DB::table('sheq_documents')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($document === null) {
            throw new RuntimeException('Document not found.');
        }

        return $document;
    }

    private function audit(
        int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, ?array $after, ?string $reason = null
    ): void {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Document',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
