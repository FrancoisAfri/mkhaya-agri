<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Links a SHEQ supplier assessment to the existing Supplier Management record, so contractors use
 * the portal they already have.
 *
 * There is deliberately no SHEQ contractor portal. Supplier Management already onboards suppliers,
 * already issues them a `portal_token`, and they already sign in there. Building a second portal
 * would mean a contractor holding two logins for the same company, and being told which one to use
 * depending on whether the document was wanted by procurement or by safety — which is not a
 * distinction they have any reason to understand.
 *
 * So SHEQ contributes the *safety* requirements to a supplier who already exists, and the portal
 * shows them alongside the commercial ones.
 */
class SupplierPortalBridge
{
    public function isAvailable(): bool
    {
        return Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'portal_token');
    }

    /**
     * Attach a SHEQ assessment to a supplier record, matching on registration number then name.
     *
     * Registration number first because two entities can trade under confusingly similar names, and
     * attaching a safety file to the wrong supplier is worse than not attaching it — it makes an
     * unassessed contractor look cleared.
     */
    public function link(int $companyId, string $assessmentUuid): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Supplier Management is not installed on this instance.');
        }

        $assessment = DB::table('sheq_supplier_assessments')
            ->where('company_id', $companyId)->where('uuid', $assessmentUuid)
            ->whereNull('deleted_at')->first();

        if ($assessment === null) {
            throw new RuntimeException("No supplier assessment {$assessmentUuid} in this company.");
        }

        if ($assessment->supplier_id !== null) {
            return ['linked' => true, 'supplier_id' => (int) $assessment->supplier_id,
                'created' => false];
        }

        $supplier = $this->matchSupplier($companyId, $assessment->registration_number,
            $assessment->supplier_name);

        if ($supplier === null) {
            return ['linked' => false, 'supplier_id' => null, 'created' => false,
                'message' => sprintf(
                    '%s is not in Supplier Management. Onboard them there first — they will then '
                    .'use the same portal login for their safety file as for their commercial '
                    .'documents.', $assessment->supplier_name,
                )];
        }

        DB::table('sheq_supplier_assessments')->where('id', $assessment->id)
            ->update(['supplier_id' => $supplier->id, 'updated_at' => now()]);

        return ['linked' => true, 'supplier_id' => (int) $supplier->id, 'created' => false,
            'has_portal_access' => ! empty($supplier->portal_token)];
    }

    /**
     * What the supplier sees in their portal: the SHEQ documents still wanted from them.
     *
     * Deliberately returns the requirement rather than the assessment. A supplier does not need to
     * know their internal risk score; they need to know which four documents are missing and by
     * when — and a portal that shows a score invites an argument about the score instead of an
     * upload.
     *
     * @return array{assessment_uuid:?string, outstanding:list<array>, status:?string}
     */
    /**
     * Find the Supplier Management record for a name on a safety file.
     *
     * Registration number first, because two companies may trade under similar names and only one
     * of them holds a given registration. Name is the fallback, lowercased, because somebody typed
     * it twice — once into procurement and once into SHEQ — and they will not match on case.
     *
     * One matcher for both directions. A contractor and a supplier assessment that resolved to the
     * same company by different rules would be the worst possible outcome here: the two records
     * would each claim a link and the join between them would find nothing.
     */
    private function matchSupplier(int $companyId, ?string $registrationNumber, string $name): ?object
    {
        $supplier = null;

        if (! empty($registrationNumber)) {
            $supplier = DB::table('suppliers')->where('company_id', $companyId)
                ->where('company_registration_number', $registrationNumber)
                ->whereNull('deleted_at')->first(['id', 'company_name', 'portal_token']);
        }

        return $supplier ?? DB::table('suppliers')->where('company_id', $companyId)
            ->whereRaw('lower(company_name) = ?', [strtolower($name)])
            ->whereNull('deleted_at')->first(['id', 'company_name', 'portal_token']);
    }

    /**
     * Tie a SHEQ contractor to its Supplier Management record (CON-5).
     *
     * `sheq_contractors.supplier_id` and `sheq_supplier_assessments.supplier_id` are the join
     * between a contractor's safety file and their SHEQ assessment, and nothing ever wrote either
     * of them: no validator accepted the field and `link()` had no caller. So the two halves of a
     * contractor's standing — the documents at the gate and the assessment procurement did — sat
     * in the same database with no way to see one from the other.
     *
     * @return array{linked:bool, supplier_id:?int, supplier_name:?string, assessment:?array, message?:string}
     */
    public function linkContractor(int $companyId, string $contractorUuid, ?int $actorId = null): array
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Supplier Management is not installed on this instance.');
        }

        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $contractorUuid)
            ->whereNull('deleted_at')->first(['id', 'name', 'registration_number', 'supplier_id']);

        if ($contractor === null) {
            throw new RuntimeException("No contractor {$contractorUuid} in this company.");
        }

        $supplier = $contractor->supplier_id !== null
            ? DB::table('suppliers')->where('company_id', $companyId)
                ->where('id', $contractor->supplier_id)->first(['id', 'company_name', 'portal_token'])
            : $this->matchSupplier($companyId, $contractor->registration_number, $contractor->name);

        if ($supplier === null) {
            return ['linked' => false, 'supplier_id' => null, 'supplier_name' => null,
                'assessment' => null,
                'message' => sprintf(
                    '%s is not in Supplier Management. Onboard them there first — the link is what '
                    .'lets their safety file and their commercial record be read against each '
                    .'other.', $contractor->name,
                )];
        }

        if ((int) ($contractor->supplier_id ?? 0) !== (int) $supplier->id) {
            DB::table('sheq_contractors')->where('id', $contractor->id)
                ->update(['supplier_id' => $supplier->id, 'updated_at' => now()]);

            // Rule 3: who tied these two records together, and to what.
            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId,
                'actor_id' => $actorId,
                'actor_type' => 'USER',
                'action' => 'contractor.supplier_linked',
                'subject_type' => 'Contractor',
                'subject_id' => (int) $contractor->id,
                'before' => json_encode(['supplier_id' => $contractor->supplier_id]),
                'after' => json_encode(['supplier_id' => (int) $supplier->id]),
                'reason' => sprintf('Linked to %s in Supplier Management.', $supplier->company_name),
            ]);
        }

        return [
            'linked' => true,
            'supplier_id' => (int) $supplier->id,
            'supplier_name' => $supplier->company_name,
            'assessment' => $this->assessmentFor($companyId, (int) $supplier->id),
        ];
    }

    /**
     * The SHEQ assessment behind a supplier, for the contractor's profile.
     *
     * The most recent one: an assessment is renewed rather than amended, and the current standing
     * is what a gate decision turns on.
     *
     * @return array<string,mixed>|null
     */
    public function assessmentFor(int $companyId, int $supplierId): ?array
    {
        $assessment = DB::table('sheq_supplier_assessments')
            ->where('company_id', $companyId)->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')->orderByDesc('created_at')
            ->first(['uuid', 'supplier_name', 'status', 'risk_category', 'score', 'valid_until',
                'scope_of_work', 'submitted_at', 'decided_at']);

        return $assessment === null ? null : (array) $assessment;
    }

    public function outstandingFor(int $companyId, int $supplierId): array
    {
        $assessment = DB::table('sheq_supplier_assessments')
            ->where('company_id', $companyId)->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')->orderByDesc('created_at')->first();

        if ($assessment === null) {
            return ['assessment_uuid' => null, 'outstanding' => [], 'status' => null];
        }

        $documents = DB::table('sheq_supplier_documents')
            ->where('company_id', $companyId)->where('assessment_id', $assessment->id)
            ->whereNull('deleted_at')->where('is_mandatory', true)
            ->get(['uuid', 'doc_type', 'title', 'status', 'expires_on', 'file_id', 'review_note']);

        $outstanding = [];

        foreach ($documents as $document) {
            $needed = $document->file_id === null
                || $document->status === 'REJECTED'
                || ($document->expires_on !== null
                    && Carbon::parse($document->expires_on)->isPast());

            if (! $needed) {
                continue;
            }

            $outstanding[] = [
                'uuid' => $document->uuid,
                'doc_type' => $document->doc_type,
                // Plain language: the portal is read by an administrator at a small contractor,
                // not by a safety professional.
                'label' => ucfirst(strtolower(str_replace('_', ' ', $document->doc_type))),
                'reason' => $document->file_id === null ? 'Not yet supplied'
                    : ($document->status === 'REJECTED'
                        ? ($document->review_note ?: 'Rejected — please resupply')
                        : 'Expired on '.$document->expires_on),
            ];
        }

        return [
            'assessment_uuid' => $assessment->uuid,
            'status' => $assessment->status,
            'outstanding' => $outstanding,
        ];
    }

    /** Suppliers with SHEQ requirements outstanding — who to chase. */
    public function chaseList(int $companyId): array
    {
        return DB::table('sheq_supplier_assessments as a')
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            ->whereIn('a.status', ['INVITED', 'SUBMITTED', 'UNDER_REVIEW'])
            ->get(['a.uuid', 'a.supplier_name', 'a.contact_email', 'a.contact_phone',
                'a.risk_category', 'a.status', 'a.supplier_id'])
            ->map(fn ($a) => (array) $a + [
                'portal_linked' => $a->supplier_id !== null,
            ])->values()->all();
    }
}
