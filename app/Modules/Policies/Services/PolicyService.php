<?php

namespace App\Modules\Policies\Services;

use App\Core\User\Trait\FileStorage;
use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Policies\Jobs\ExtractPolicyClausesJob;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyDocument;
use App\Modules\Policies\Models\PolicyReviewer;
use App\Modules\Policies\Models\PolicyVersion;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PolicyService
{
    use FileStorage;

    public function getAll(array $filters = [])
    {
        $query = Policy::with([
            'category',
            'owner.person',
            'owner.companyUnits.parent.parent.parent.parent',
            'reviewers',
            'departmentUnit.parent.parent.parent.parent',
        ])
            ->withCount('versions');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    public function findById(int $id): ?Policy
    {
        return Policy::with([
            'category',
            'owner.person',
            'owner.companyUnits.parent.parent.parent.parent',
            'departmentUnit.parent.parent.parent.parent',
            'currentVersion',
            'versions.creator.person',
            'clauses',
            'rules',
            'documents',
            'reviewers.user.person',
        ])->find($id);
    }

    public function create(array $data): Policy
    {
        return DB::transaction(function () use ($data) {
            $documentFile = $data['document'] ?? null;
            $reviewerIds = collect($data['reviewer_ids'] ?? [])->map(fn($id) => (int) $id)->all();

            unset($data['reviewer_ids']);

            if ($documentFile instanceof UploadedFile) {
                // Keep policy-level document field lightweight; binary lives in policy_documents.
                $data['document'] = $documentFile->getClientOriginalName();
            }

            if (empty($data['slug']) && !empty($data['name'])) {
                $data['slug'] = Str::slug($data['name']) . '-' . uniqid();
            }

            if (!empty($data['date'])) {
                $data['date'] = is_string($data['date']) ? strtotime($data['date']) : $data['date'];
            }

            $policy = Policy::create($data);

            $version = $this->createSnapshotVersion($policy, 'Initial version created');
            $policy->update(['current_version_id' => $version->id]);

            $this->syncReviewers($policy, $reviewerIds, $policy->owner_id);

            if ($documentFile instanceof UploadedFile) {
                $this->storePolicyDocument($policy, $documentFile, $version->id);
            }

            return $policy->fresh([
                'category',
                'owner.person',
                'owner.companyUnits.parent.parent.parent.parent',
                'departmentUnit.parent.parent.parent.parent',
                'currentVersion',
                'versions.creator.person',
                'documents',
                'reviewers.user.person',
            ]);
        });
    }

    public function update(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data) {
            $policy = $this->findById($id);
            if (!$policy) {
                return false;
            }

            $documentFile = $data['document'] ?? null;
            $reviewerIds = array_key_exists('reviewer_ids', $data)
                ? collect($data['reviewer_ids'] ?? [])->map(fn($rid) => (int) $rid)->all()
                : null;

            unset($data['reviewer_ids']);

            if ($documentFile instanceof UploadedFile) {
                $data['document'] = $documentFile->getClientOriginalName();
            }

            if (!empty($data['date'])) {
                $data['date'] = is_string($data['date']) ? strtotime($data['date']) : $data['date'];
            }

            $updated = $policy->update($data);
            if (!$updated) {
                return false;
            }

            if (is_array($reviewerIds)) {
                $this->syncReviewers($policy->fresh(), $reviewerIds, $policy->owner_id);
            }

            $version = $this->createSnapshotVersion(
                $policy->fresh(['clauses', 'rules']),
                $data['change_summary'] ?? 'Policy updated'
            );

            $policy->update(['current_version_id' => $version->id]);

            if ($documentFile instanceof UploadedFile) {
                $this->storePolicyDocument($policy, $documentFile, $version->id);
            }

            return true;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $policy = $this->findById($id);
            if (!$policy) {
                return false;
            }
            return (bool) $policy->delete();
        });
    }

    public function uploadDocument(UploadedFile $file): array
    {
        return $this->saveFileToDatabase($file);
    }

    public function storePolicyDocument(Policy $policy, UploadedFile $file, ?int $versionId = null): PolicyDocument
    {
        $fileData = $this->uploadDocument($file);

        $document = PolicyDocument::create([
            'policy_id' => $policy->id,
            'version_id' => $versionId,
            'filename' => $fileData['original_name'] ?? $file->getClientOriginalName(),
            'disk' => 'database',
            'path' => json_encode($fileData),
            'mime_type' => $fileData['mime'] ?? ($file->getClientMimeType() ?? 'application/octet-stream'),
            'size_bytes' => $fileData['size'] ?? $file->getSize(),
            'extraction_status' => 'pending',
            'uploaded_by' => Auth::id(),
        ]);

        (new ExtractPolicyClausesJob($document))
            ->handle(app(AgentRegistry::class));

        return $document;
    }

    public function buildDocumentResponse(PolicyDocument $document, bool $inline = false): Response
    {
        // The shared disk first, then the JSON envelope that `path` holds despite its name. Both
        // go through the model, which gets the headers right — this used to serve a document with
        // its own MIME type and no nosniff.
        if ($document->hasBytes()) {
            return $document->fileResponse(
                $document->filename ?: $document->legacyEnvelopeValue('original_name') ?: 'policy-document',
                $document->mime_type ?: $document->legacyEnvelopeValue('mime'),
                inline: $inline,
            );
        }

        // Backward compatibility for previously stored disk-path documents.
        if ($document->disk === 'public' && !empty($document->path) && Storage::disk('public')->exists($document->path)) {
            $binary = Storage::disk('public')->get($document->path);
            $mime = $document->mime_type ?: 'application/octet-stream';
            $name = $document->filename ?: 'policy-document';
            $disposition = $inline ? 'inline' : 'attachment';

            return response($binary, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => $disposition . '; filename="' . $name . '"',
            ]);
        }

        throw new \RuntimeException('Document payload not found.');
    }

    private function createSnapshotVersion(Policy $policy, ?string $changeSummary = null): PolicyVersion
    {
        $currentVersion = $policy->versions()->count() + 1;

        $snapshot = [
            'policy' => $policy->only([
                'id', 'name', 'description', 'status', 'date', 'category_id',
                'refresh_month', 'slug', 'owner_id', 'department_id',
                'effective_date', 'review_date', 'requires_attestation', 'tags',
            ]),
            'clauses' => $policy->clauses()->get()->map->toArray()->values(),
            'rules' => $policy->rules()->with('conditions', 'actions')->get()->map->toArray()->values(),
            'captured_at' => now()->toDateTimeString(),
        ];

        return $policy->versions()->create([
            'version_number' => (string) $currentVersion,
            'change_summary' => $changeSummary,
            'snapshot' => $snapshot,
            'created_by' => Auth::id(),
            'published_at' => now(),
        ]);
    }

    private function syncReviewers(Policy $policy, array $reviewerIds, ?int $ownerId): void
    {
        $reviewerIds = collect($reviewerIds)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($ownerId) {
            PolicyReviewer::updateOrCreate(
                [
                    'policy_id' => $policy->id,
                    'user_id' => $ownerId,
                    'role' => 'owner',
                ],
                [
                    'assigned_at' => now(),
                ]
            );
        }

        PolicyReviewer::where('policy_id', $policy->id)
            ->where('role', 'reviewer')
            ->whereNotIn('user_id', $reviewerIds->all())
            ->delete();

        foreach ($reviewerIds as $reviewerId) {
            PolicyReviewer::updateOrCreate(
                [
                    'policy_id' => $policy->id,
                    'user_id' => $reviewerId,
                    'role' => 'reviewer',
                ],
                [
                    'assigned_at' => now(),
                ]
            );
        }
    }
}
