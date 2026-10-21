<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalStep;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Services\ApprovalService;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Farm records are Document Management documents.
 *
 * A title deed, a lease, a water-use licence, an operator's certificate, an AI-drafted report: each
 * is filed in Document Management under the "Farm records" category, linked to its farm and to what
 * it is about (a field, a crop, a herd, an animal, a machine). Document Management keeps the file in
 * the database with its checksum and versions, sends the expiry reminders, applies retention and runs
 * the approval — through the category's workflow, which requires someone other than whoever filed
 * the document to approve it. A document counts (for compliance, for an inspector) once approved.
 */
class FarmDocuments
{
    public const CATEGORY = 'farm-records';

    /** What a farm document can be about, as Document Management relations. */
    public const SUBJECTS = ['field' => DocumentRelations::FARM_FIELD, 'crop_plan' => DocumentRelations::CROP_PLAN,
        'livestock_group' => DocumentRelations::LIVESTOCK_GROUP, 'animal' => DocumentRelations::ANIMAL, 'machine' => DocumentRelations::FARM_MACHINE];

    public const TYPES = ['title_deed', 'lease', 'water_use_licence', 'pesticide_registration', 'operator_certificate', 'veterinary_certificate',
        'insurance', 'audit_report', 'certification', 'permit', 'contract', 'report', 'other'];

    public function __construct(private readonly DocumentService $documents, private readonly ApprovalService $approvals) {}

    /**
     * File a document for a farm.
     *
     * @param  array{document_type?: string, title?: string, reference?: ?string, issued_on?: ?string, expires_on?: ?string, subject?: ?Model, description?: ?string}  $data
     * @param  array{original_name: string, mime: string, data: string}  $file  data is base64
     */
    public function file(Farm $farm, array $data, array $file, User $actor, ?Document $replaces = null): Document
    {
        $bytes = base64_decode($file['data'], true);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['file' => 'The file is empty or unreadable.']);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'agridoc');
        file_put_contents($tmp, $bytes);
        $upload = new UploadedFile($tmp, $file['original_name'], $file['mime'] ?? null, null, true);

        try {
            if ($replaces) {
                $r = $this->documents->replace($replaces, $upload, $data['change_note'] ?? 'New version');
                if ($r['error'] ?? null) {
                    throw ValidationException::withMessages(['file' => $r['error']]);
                }
                $replaces->forceFill(array_filter(['expiry_date' => $data['expires_on'] ?? null, 'document_date' => $data['issued_on'] ?? null]))->save();
                $this->submit($replaces->fresh());

                return $replaces->fresh();
            }

            $r = $this->documents->store($upload, [
                'title' => $data['title'] ?? pathinfo($file['original_name'], PATHINFO_FILENAME),
                'description' => $data['description'] ?? "Farm record for {$farm->name}",
                'category_id' => self::category((int) $farm->company_id)->id,
                'document_type' => $data['document_type'] ?? 'other',
                'document_number' => $data['reference'] ?? null,
                'document_date' => $data['issued_on'] ?? null,
                'expiry_date' => $data['expires_on'] ?? null,
                'owner_id' => $actor->id,
            ]);
            if ($r['error'] ?? null) {
                throw ValidationException::withMessages(['file' => $r['error']]);
            }
            /** @var Document $doc */
            $doc = $r['document'];
            $doc->linkTo($farm, DocumentRelations::FARM);
            if (! empty($data['subject'])) {
                $doc->linkTo($data['subject']);
            }
            $this->submit($doc);

            return $doc->fresh();
        } finally {
            @unlink($tmp);
        }
    }

    /** Sent for approval through the farm records workflow (four eyes). */
    private function submit(Document $doc): void
    {
        $workflow = self::workflow((int) $doc->company_id);
        $this->approvals->submit($doc, $workflow, 'Farm record');
    }

    public static function approved(Document $doc): bool
    {
        return in_array($doc->approval_status, [Document::APPROVAL_APPROVED, Document::APPROVAL_PUBLISHED], true);
    }

    /** Documents linked to any of these farms. */
    public static function forFarms(array $farmIds): Builder
    {
        return Document::query()->where('status', Document::STATUS_ACTIVE)
            ->whereHas('links', fn ($q) => $q->where('relation', DocumentRelations::FARM)->where('linkable_type', Farm::class)->whereIn('linkable_id', $farmIds));
    }

    /**
     * The farms' documents that have expired or expire within the horizon, soonest first.
     *
     * @return \Illuminate\Support\Collection<int, array{document: Document, farm_id: int, state: string}>
     */
    public static function expiring(array $farmIds, ?int $horizon = null)
    {
        $horizon ??= (int) config('agri.alert_horizon_days', 30);

        return self::forFarms($farmIds)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', today()->addDays($horizon))
            ->with(['links' => fn ($q) => $q->where('relation', DocumentRelations::FARM)])->orderBy('expiry_date')->get()
            ->map(fn (Document $d) => ['document' => $d, 'farm_id' => (int) $d->links->first()?->linkable_id,
                'state' => $d->expiry_date->lt(today()) ? 'expired' : 'expiring']);
    }

    /** Where a person opens it: Document Management's own page. */
    public static function url(Document $d): string
    {
        return '/document-management/documents/'.$d->uuid;
    }

    /** The farm a document is filed under. */
    public static function farmIdOf(Document $doc): ?int
    {
        return $doc->links()->where('relation', DocumentRelations::FARM)->value('linkable_id');
    }

    /** "Farm records": a category in Document Management, kept 7 years, approved by a second person. */
    public static function category(int $companyId): DocumentCategory
    {
        return DB::transaction(function () use ($companyId) {
            $c = DocumentCategory::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId, 'slug' => self::CATEGORY], [
                'uuid' => (string) Str::uuid(), 'name' => 'Farm records', 'description' => 'Title deeds, leases, licences, certificates, permits and reports for the farms.',
                'icon' => 'tabler-plant-2', 'is_system' => true, 'is_active' => true, 'retention_years' => 7, 'workflow_required' => true]);
            if (! $c->default_workflow_id) {
                $c->forceFill(['default_workflow_id' => self::workflow($companyId, $c->id)->id])->save();
            }

            return $c;
        });
    }

    public static function workflow(int $companyId, ?int $categoryId = null): DocumentApprovalWorkflow
    {
        $w = DocumentApprovalWorkflow::withoutGlobalScopes()->where('company_id', $companyId)->where('name', 'Farm records')->first();
        if ($w) {
            return $w;
        }
        $w = DocumentApprovalWorkflow::withoutGlobalScopes()->create(['uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => 'Farm records',
            'description' => 'A farm record is approved by a farm manager other than whoever filed it.', 'category_id' => $categoryId,
            'is_active' => true, 'is_mandatory' => true, 'separate_from_submitter' => true]);
        DocumentApprovalStep::withoutGlobalScopes()->create(['uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'workflow_id' => $w->id, 'name' => 'Farm manager',
            'position' => 1, 'quorum' => DocumentApprovalStep::QUORUM_ANY, 'approver_type' => DocumentApprovalStep::APPROVER_ROLE, 'approver_name' => 'Farm Manager']);

        return $w;
    }
}
