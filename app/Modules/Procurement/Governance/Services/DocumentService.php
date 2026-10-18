<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Core\User\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documents & evidence (§8): one register, many contexts, versioned, access-controlled and logged.
 *
 * The behaviour that makes §8's "never upload the same document twice" real is `attach()`: linking
 * an existing document to another procurement is a row in `procgov_document_links`, not a copy of
 * the file. A tax clearance certificate uploaded once is the same document on every tender the
 * supplier bids for, and it expires in one place.
 *
 * Access is decided by `canRead()` from three inputs together — the document's confidentiality
 * level, any explicit grants, and the reader's permissions. Every actual read is logged, including
 * denials: an attempt to open a competitor's bid before the tender closed is precisely the event an
 * investigation would want, and it only exists in the log if denials are written down too.
 *
 * Files live on the configured disk, addressed by a random path rather than the original filename —
 * a bid document should not be guessable from a supplier's name, and an original filename is
 * attacker-controlled input that has no business in a filesystem path.
 */
class DocumentService
{
    /** Highest to lowest. A reader needs the matching permission at or above the level. */
    private const CONFIDENTIALITY_PERMISSION = [
        'RESTRICTED' => 'procgov.document.restricted',
        'CONFIDENTIAL' => 'procgov.document.confidential',
        'INTERNAL' => 'procgov.document.view',
        'PUBLIC' => null,
    ];

    public function __construct(
        private readonly AuditLedger $audit,
        private readonly ReferenceGenerator $references,
    ) {}

    /**
     * Store an uploaded file as a new document, optionally linked to something straight away.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function store(int $companyId, UploadedFile $file, array $attributes): object
    {
        return DB::transaction(function () use ($companyId, $file, $attributes) {
            $now = Carbon::now();
            $category = $this->resolveCategory($companyId, $attributes['category_code'] ?? null);

            $documentId = DB::table('procgov_documents')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'reference' => $this->references->next($companyId, 'document'),
                'title' => $attributes['title'] ?? $file->getClientOriginalName(),
                'description' => $attributes['description'] ?? null,
                'category_id' => $category?->id,
                'confidentiality' => $attributes['confidentiality']
                    ?? $category?->default_confidentiality
                    ?? 'INTERNAL',
                'owner_id' => $attributes['owner_id'] ?? auth()->id(),
                'approval_status' => ($category?->requires_approval ?? false) ? 'PENDING_APPROVAL' : 'APPROVED',
                'effective_from' => $attributes['effective_from'] ?? null,
                'expires_at' => $attributes['expires_at'] ?? null,
                'requires_acknowledgement' => $attributes['requires_acknowledgement']
                    ?? ($category?->requires_acknowledgement ?? false),
                'version_count' => 0,
                'created_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->storeVersion($companyId, $documentId, $file, $attributes['change_note'] ?? 'Initial upload');

            if (! empty($attributes['link'])) {
                $this->attach($companyId, $documentId, $attributes['link']);
            }

            $document = DB::table('procgov_documents')->find($documentId);

            $link = $attributes['link'] ?? [];
            $recordId = ($link['entity_type'] ?? null) === 'procgov_records'
                ? ($link['entity_id'] ?? null)
                : null;

            $this->audit->record($companyId, [
                'action' => 'document_uploaded',
                'capability' => 'governance',
                'entity_type' => 'procgov_documents',
                'entity_id' => $documentId,
                'entity_reference' => $document->reference,
                'record_id' => $recordId,
                'summary' => "Uploaded document \"{$document->title}\"",
            ]);

            return $document;
        });
    }

    /** Add a version to an existing document. The previous version stays addressable. */
    public function addVersion(int $companyId, int $documentId, UploadedFile $file, ?string $changeNote = null): object
    {
        return DB::transaction(function () use ($companyId, $documentId, $file, $changeNote) {
            DB::table('procgov_document_versions')
                ->where('document_id', $documentId)
                ->update(['is_current' => false, 'updated_at' => Carbon::now()]);

            $version = $this->storeVersion($companyId, $documentId, $file, $changeNote);

            $document = DB::table('procgov_documents')->find($documentId);

            $this->audit->record($companyId, [
                'action' => 'document_versioned',
                'capability' => 'governance',
                'entity_type' => 'procgov_documents',
                'entity_id' => $documentId,
                'entity_reference' => $document->reference,
                'comment' => $changeNote,
                'summary' => "Uploaded version {$version->version_no} of \"{$document->title}\"",
            ]);

            return $version;
        });
    }

    /**
     * Link an existing document to another context. This is what removes repeat uploads.
     *
     * @param  array{link_role:string, entity_type:string, entity_id:int, entity_reference?:string, is_required?:bool}  $link
     */
    public function attach(int $companyId, int $documentId, array $link): void
    {
        $now = Carbon::now();

        DB::table('procgov_document_links')->updateOrInsert(
            [
                'document_id' => $documentId,
                'entity_type' => $link['entity_type'],
                'entity_id' => $link['entity_id'],
                'link_role' => $link['link_role'],
            ],
            [
                'company_id' => $companyId,
                'source_module' => $link['source_module'] ?? 'procurement_governance',
                'entity_uuid' => $link['entity_uuid'] ?? null,
                'entity_reference' => $link['entity_reference'] ?? null,
                'is_required' => $link['is_required'] ?? false,
                'linked_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * May this user read the document? The three inputs are combined, and an explicit grant can
     * widen access without lowering the classification.
     */
    public function canRead(?Authenticatable $user, object $document, bool $forDownload = false): bool
    {
        if (! $user) {
            return false;
        }

        $userId = (int) $user->getAuthIdentifier();

        if ((int) $document->owner_id === $userId) {
            return true;
        }

        $grant = DB::table('procgov_document_permissions')
            ->where('document_id', $document->id)
            ->where(function ($q) use ($userId) {
                $q->where(function ($inner) use ($userId) {
                    $inner->where('subject_type', 'user')->where('subject_id', $userId);
                });
            })
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
            ->first();

        if ($grant) {
            return $forDownload ? (bool) $grant->can_download : (bool) $grant->can_view;
        }

        // A role grant, if the user holds the role.
        $roleGrant = DB::table('procgov_document_permissions as p')
            ->join('model_has_roles as mhr', function ($join) use ($userId) {
                $join->on('mhr.role_id', '=', 'p.subject_id')
                    ->where('mhr.model_type', User::class)
                    ->where('mhr.model_id', $userId);
            })
            ->where('p.document_id', $document->id)
            ->where('p.subject_type', 'role')
            ->where(fn ($q) => $q->whereNull('p.expires_at')->orWhere('p.expires_at', '>', Carbon::now()))
            ->first(['p.can_view', 'p.can_download']);

        if ($roleGrant) {
            return $forDownload ? (bool) $roleGrant->can_download : (bool) $roleGrant->can_view;
        }

        $permission = self::CONFIDENTIALITY_PERMISSION[$document->confidentiality] ?? 'procgov.document.view';

        return $permission === null || (bool) $user->can($permission);
    }

    /**
     * Read a document's current file, logging the access. Returns null when the caller may not have
     * it — and writes the denial, because a refused attempt on a confidential bid is exactly what an
     * investigation looks for.
     *
     * @return array{path:string, disk:string, filename:string, mime:string|null}|null
     */
    public function open(int $companyId, object $document, ?Authenticatable $user, bool $forDownload = false): ?array
    {
        $version = DB::table('procgov_document_versions')
            ->where('document_id', $document->id)
            ->where('is_current', true)
            ->first();

        if (! $this->canRead($user, $document, $forDownload)) {
            $this->logAccess($companyId, $document->id, $version?->id, 'denied', $user);

            return null;
        }

        if (! $version) {
            return null;
        }

        $this->logAccess($companyId, $document->id, $version->id, $forDownload ? 'downloaded' : 'viewed', $user);

        return [
            'path' => $version->path,
            'disk' => $version->disk,
            'filename' => $version->original_filename,
            'mime' => $version->mime_type,
        ];
    }

    /** Documents attached to a subject, filtered to what the reader may see. */
    public function forSubject(int $companyId, string $entityType, int $entityId, ?Authenticatable $user): Collection
    {
        return DB::table('procgov_document_links as l')
            ->join('procgov_documents as d', 'd.id', '=', 'l.document_id')
            ->leftJoin('procgov_document_categories as c', 'c.id', '=', 'd.category_id')
            ->where('l.company_id', $companyId)
            ->where('l.entity_type', $entityType)
            ->where('l.entity_id', $entityId)
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.id')
            ->get([
                'd.id', 'd.uuid', 'd.title', 'd.confidentiality', 'd.approval_status', 'd.owner_id',
                'd.expires_at', 'd.version_count', 'd.requires_acknowledgement',
                'c.name as category_name', 'c.code as category_code',
                'l.link_role', 'l.is_required',
            ])
            ->filter(fn ($d) => $this->canRead($user, $d))
            ->values();
    }

    /** §9: documents expiring within the window, for the notification sweep. */
    public function expiring(int $companyId, int $withinDays = 30): Collection
    {
        return DB::table('procgov_documents')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [Carbon::today(), Carbon::today()->addDays($withinDays)])
            ->orderBy('expires_at')
            ->get(['id', 'uuid', 'reference', 'title', 'expires_at', 'owner_id']);
    }

    public function acknowledge(int $companyId, int $documentId, int $userId, ?string $statement = null): void
    {
        $versionId = DB::table('procgov_document_versions')
            ->where('document_id', $documentId)
            ->where('is_current', true)
            ->value('id');

        DB::table('procgov_document_acknowledgements')->updateOrInsert(
            ['document_id' => $documentId, 'version_id' => $versionId, 'user_id' => $userId],
            [
                'company_id' => $companyId,
                'acknowledged_at' => Carbon::now(),
                'statement' => $statement,
                'ip' => request()->ip(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        $this->audit->record($companyId, [
            'action' => 'document_acknowledged',
            'capability' => 'governance',
            'entity_type' => 'procgov_documents',
            'entity_id' => $documentId,
            'comment' => $statement,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function storeVersion(int $companyId, int $documentId, UploadedFile $file, ?string $changeNote): object
    {
        $now = Carbon::now();
        $disk = config('filesystems.default', 'local');

        $nextVersion = (int) DB::table('procgov_document_versions')
            ->where('document_id', $documentId)
            ->max('version_no') + 1;

        // Random storage name: an original filename is attacker-controlled and a bid document
        // should not be guessable from the supplier's name.
        $extension = $file->getClientOriginalExtension();
        $storedName = Str::uuid().($extension ? '.'.preg_replace('/[^A-Za-z0-9]/', '', $extension) : '');
        $directory = "procurement-governance/{$companyId}/documents/{$documentId}";

        $path = $file->storeAs($directory, $storedName, ['disk' => $disk]);

        $versionId = DB::table('procgov_document_versions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'document_id' => $documentId,
            'version_no' => $nextVersion,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            // Proves the file backing an evaluation has not been swapped since it was scored.
            'checksum' => hash_file('sha256', $file->getRealPath() ?: Storage::disk($disk)->path($path)),
            'change_note' => $changeNote,
            'is_current' => true,
            'uploaded_by' => auth()->id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('procgov_documents')->where('id', $documentId)->update([
            'current_version_id' => $versionId,
            'version_count' => $nextVersion,
            'updated_at' => $now,
        ]);

        $this->logAccess($companyId, $documentId, $versionId, 'uploaded', auth()->user());

        return DB::table('procgov_document_versions')->find($versionId);
    }

    private function logAccess(int $companyId, int $documentId, ?int $versionId, string $action, ?Authenticatable $user): void
    {
        DB::table('procgov_document_access_log')->insert([
            'company_id' => $companyId,
            'document_id' => $documentId,
            'version_id' => $versionId,
            'action' => $action,
            'user_id' => $user?->getAuthIdentifier(),
            'ip' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
            'created_at' => Carbon::now(),
        ]);
    }

    private function resolveCategory(int $companyId, ?string $code): ?object
    {
        if (! $code) {
            return null;
        }

        return DB::table('procgov_document_categories')
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();
    }
}
