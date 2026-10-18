<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * SHEQ onboarding of suppliers and contractors.
 *
 * Why this exists as a gate rather than a form: section 37(2) of the OHS Act makes the employer
 * liable for the acts and omissions of a mandatary unless there is a written agreement and the
 * employer took reasonable steps to ensure compliance. "We asked them for a safety file" is not
 * reasonable steps. A scored assessment against required evidence, with a dated approval and an
 * expiry, is.
 *
 * The design decisions that make it useful rather than bureaucratic:
 *
 * **Required documents follow the risk category.** A stationery supplier delivering to reception
 * and a rigging contractor working at height do not need the same file, and demanding one of the
 * former is how the whole process gets routed around.
 *
 * **Approval expires.** A supplier approved on the strength of a letter of good standing that
 * lapsed two years ago has not been assessed — they have been remembered.
 *
 * **An expired mandatory document invalidates the approval on its own.** Compliance is computed
 * from the evidence, never stored as a flag somebody set once.
 */
class SupplierOnboardingService
{
    public const RISK_CATEGORIES = ['LOW', 'MEDIUM', 'HIGH'];

    /**
     * The evidence each risk band must produce.
     *
     * Declared here rather than configured per tenant, because these are the documents South
     * African law and insurers actually require — a company that decides it does not need a letter
     * of good standing for a high-risk contractor is not making a configuration choice, it is
     * accepting an uninsured COIDA exposure.
     */
    public const REQUIRED_DOCUMENTS = [
        'LOW' => ['COMPANY_REGISTRATION'],
        'MEDIUM' => ['COMPANY_REGISTRATION', 'LETTER_OF_GOOD_STANDING', 'PUBLIC_LIABILITY'],
        'HIGH' => ['COMPANY_REGISTRATION', 'LETTER_OF_GOOD_STANDING', 'PUBLIC_LIABILITY',
            'SAFETY_FILE', 'RISK_ASSESSMENT', 'MEDICAL_CERTIFICATES'],
    ];

    public function __construct(private readonly ApprovalService $approvals) {}

    /** Invite a supplier to be assessed. */
    public function invite(int $companyId, array $data, ?int $actorId = null): array
    {
        if (trim($data['supplier_name'] ?? '') === '') {
            throw new InvalidArgumentException('An assessment needs the supplier’s name.');
        }

        $risk = strtoupper($data['risk_category'] ?? 'MEDIUM');

        if (! in_array($risk, self::RISK_CATEGORIES, true)) {
            throw new InvalidArgumentException(
                "'{$risk}' is not a risk category. The category decides what evidence is required, "
                .'so it cannot be left unset.'
            );
        }

        return DB::transaction(function () use ($companyId, $data, $risk, $actorId) {
            $uuid = (string) Str::uuid();

            $id = DB::table('sheq_supplier_assessments')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_name' => $data['supplier_name'],
                'registration_number' => $data['registration_number'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'scope_of_work' => $data['scope_of_work'] ?? null,
                'risk_category' => $risk,
                'status' => 'INVITED',
                'assessed_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // The checklist is created up front so the supplier can see exactly what is wanted,
            // rather than discovering it one rejection at a time.
            foreach (self::REQUIRED_DOCUMENTS[$risk] as $docType) {
                DB::table('sheq_supplier_documents')->insert([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'assessment_id' => $id,
                    'doc_type' => $docType,
                    'is_mandatory' => true,
                    'status' => 'PENDING',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return ['id' => $id, 'uuid' => $uuid, 'risk_category' => $risk,
                'required_documents' => self::REQUIRED_DOCUMENTS[$risk]];
        });
    }

    /** Attach a document against one of the required slots. */
    public function attachDocument(int $companyId, string $assessmentUuid, array $data): array
    {
        $assessment = $this->find($companyId, $assessmentUuid);

        $docType = strtoupper($data['doc_type'] ?? '');

        if ($docType === '') {
            throw new InvalidArgumentException('A document must say what it is.');
        }

        $slot = DB::table('sheq_supplier_documents')
            ->where('company_id', $companyId)->where('assessment_id', $assessment->id)
            ->where('doc_type', $docType)->whereNull('deleted_at')->first(['id']);

        $payload = [
            'title' => $data['title'] ?? null,
            'file_id' => $data['file_id'] ?? null,
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'status' => 'PENDING',
            'updated_at' => now(),
        ];

        if ($slot !== null) {
            DB::table('sheq_supplier_documents')->where('id', $slot->id)->update($payload);

            return ['uuid' => $assessmentUuid, 'doc_type' => $docType, 'replaced_slot' => true];
        }

        // An extra document beyond the required set is allowed but not mandatory — suppliers
        // routinely send more than was asked for, and refusing it would be perverse.
        DB::table('sheq_supplier_documents')->insert($payload + [
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'assessment_id' => $assessment->id,
            'doc_type' => $docType,
            'is_mandatory' => false,
            'created_at' => now(),
        ]);

        return ['uuid' => $assessmentUuid, 'doc_type' => $docType, 'replaced_slot' => false];
    }

    /**
     * What is still missing, expired, or unreviewed.
     *
     * Computed, never stored. A compliance flag set once is a flag that stays green after the
     * insurance lapses.
     *
     * @return array{complete:bool, missing:list<string>, expired:list<string>,
     *               pending_review:list<string>, score:float}
     */
    public function completeness(int $companyId, string $assessmentUuid): array
    {
        $assessment = $this->find($companyId, $assessmentUuid);
        $today = Carbon::today();

        $documents = DB::table('sheq_supplier_documents')
            ->where('company_id', $companyId)->where('assessment_id', $assessment->id)
            ->whereNull('deleted_at')->get();

        $missing = [];
        $expired = [];
        $pending = [];
        $accepted = 0;
        $mandatory = 0;

        foreach ($documents as $document) {
            if (! $document->is_mandatory) {
                continue;
            }

            $mandatory++;

            if ($document->file_id === null) {
                $missing[] = $document->doc_type;

                continue;
            }

            if ($document->expires_on !== null && Carbon::parse($document->expires_on)->lt($today)) {
                $expired[] = $document->doc_type;

                continue;
            }

            if ($document->status !== 'ACCEPTED') {
                $pending[] = $document->doc_type;

                continue;
            }

            $accepted++;
        }

        return [
            'complete' => $missing === [] && $expired === [] && $pending === [],
            'missing' => $missing,
            'expired' => $expired,
            'pending_review' => $pending,
            'score' => $mandatory === 0 ? 0.0 : round(($accepted / $mandatory) * 100, 1),
        ];
    }

    /**
     * Submit for approval.
     *
     * Refused while mandatory evidence is missing or expired: the approval is supposed to record
     * that somebody checked the file, and there is no file to check.
     */
    public function submitForApproval(int $companyId, string $assessmentUuid, int $actorId): array
    {
        $assessment = $this->find($companyId, $assessmentUuid);
        $state = $this->completeness($companyId, $assessmentUuid);

        if (! $state['complete']) {
            throw new RuntimeException(sprintf(
                'This assessment is not ready. Missing: %s. Expired: %s. Awaiting review: %s. '
                .'Approving it now would record that somebody checked evidence that is not there.',
                $state['missing'] === [] ? 'none' : implode(', ', $state['missing']),
                $state['expired'] === [] ? 'none' : implode(', ', $state['expired']),
                $state['pending_review'] === [] ? 'none' : implode(', ', $state['pending_review']),
            ));
        }

        DB::table('sheq_supplier_assessments')->where('id', $assessment->id)->update([
            'status' => 'UNDER_REVIEW', 'submitted_at' => now(),
            'score' => $state['score'], 'updated_at' => now(),
        ]);

        return $this->approvals->open($companyId, [
            'subject_type' => 'SUPPLIER_ONBOARDING',
            'subject_id' => (int) $assessment->id,
            'subject_label' => $assessment->supplier_name.' — '.$assessment->risk_category.' risk',
            'subject_subtype' => $assessment->risk_category,
            'requested_by' => $actorId,
            'note' => $assessment->scope_of_work,
        ]);
    }

    /** Apply the approval outcome, with an expiry so it has to be revisited. */
    public function applyDecision(int $companyId, int $assessmentId, bool $approved,
        ?string $conditions = null, int $validMonths = 12): void
    {
        DB::table('sheq_supplier_assessments')->where('company_id', $companyId)
            ->where('id', $assessmentId)->update([
                'status' => $approved ? ($conditions ? 'CONDITIONAL' : 'APPROVED') : 'REJECTED',
                'conditions' => $conditions,
                // Never open-ended. An approval with no expiry becomes a permanent pass that
                // outlives every document it was based on.
                'valid_until' => $approved
                    ? Carbon::today()->addMonths($validMonths)->toDateString() : null,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * May this supplier be engaged right now?
     *
     * Recomputed at the moment of asking, from the approval date and the documents. This is what
     * procurement calls before raising an order, and the honest answer changes the day an
     * insurance certificate lapses — not when somebody next remembers to look.
     *
     * @return array{allowed:bool, reasons:list<string>}
     */
    public function mayEngage(int $companyId, string $assessmentUuid): array
    {
        $assessment = $this->find($companyId, $assessmentUuid);
        $reasons = [];

        if (! in_array($assessment->status, ['APPROVED', 'CONDITIONAL'], true)) {
            $reasons[] = 'The SHEQ assessment is '.strtolower(str_replace('_', ' ', $assessment->status)).'.';
        }

        if ($assessment->valid_until !== null && Carbon::parse($assessment->valid_until)->isPast()) {
            $reasons[] = "The approval expired on {$assessment->valid_until} and must be renewed.";
        }

        $state = $this->completeness($companyId, $assessmentUuid);

        if ($state['expired'] !== []) {
            $reasons[] = 'Expired evidence: '.implode(', ', $state['expired']).'.';
        }

        return ['allowed' => $reasons === [], 'reasons' => $reasons];
    }

    /** Approvals lapsing soon, so renewal starts before the supplier is blocked. */
    public function expiringWithin(int $companyId, int $days = 60): array
    {
        $today = Carbon::today();

        return DB::table('sheq_supplier_assessments')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('status', ['APPROVED', 'CONDITIONAL'])
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', $today->copy()->addDays($days)->toDateString())
            ->orderBy('valid_until')
            ->get(['uuid', 'supplier_name', 'risk_category', 'valid_until', 'status'])
            ->map(fn ($a) => (array) $a + [
                'expired' => Carbon::parse($a->valid_until)->isPast(),
            ])->values()->all();
    }

    private function find(int $companyId, string $uuid): object
    {
        $assessment = DB::table('sheq_supplier_assessments')->where('company_id', $companyId)
            ->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($assessment === null) {
            throw new RuntimeException("No supplier assessment {$uuid} in this company.");
        }

        return $assessment;
    }
}
