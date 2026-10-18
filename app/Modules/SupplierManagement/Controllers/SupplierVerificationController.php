<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\BankingGate;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Running and recording due-diligence checks against one supplier.
 *
 * Both routes end in the same engine call, so a check performed by a person and a check performed by
 * an integration are the same kind of record with the same evidence trail. The only difference is who
 * the observation says looked.
 */
class SupplierVerificationController extends Controller
{
    public function __construct(
        private readonly VerificationEngine $engine,
        private readonly ProviderRegistry $registry,
        private readonly BankingGate $banking,
    ) {}

    /**
     * Ask the configured source for an answer.
     *
     * Refused rather than silently recorded as unavailable when no source is configured: the person
     * clicked a button expecting a check to happen, and a log entry saying "we could not" is not what
     * they asked for. They are told to record it themselves instead.
     */
    public function run(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'check_type' => ['required', 'string', Rule::in(CheckType::verificationChecks())],
            'person_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        if (! $this->registry->hasDataSourceFor($data['check_type'])) {
            return back()->withErrors([
                'check_type' => __('supplier_management.verifications.errors.no_source', [
                    'check' => CheckType::label($data['check_type']),
                ]),
            ]);
        }

        [$subjectType, $subjectId, $subjectLabel] = $this->subject($supplier, $data['person_id'] ?? null);

        $observation = $this->engine->verify(
            supplier: $supplier,
            checkType: $data['check_type'],
            context: $this->contextFor($supplier, $data['check_type']),
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
            reference: $data['reference'] ?? null,
        );

        return back()->with(
            VerificationStatus::needsAttention($observation->status) ? 'warning' : 'success',
            __('supplier_management.verifications.ran', [
                'check' => $observation->checkLabel(),
                'status' => $observation->status,
            ]).' '.$observation->summary
        );
    }

    /** Record a check somebody performed at the source themselves. */
    public function record(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'check_type' => ['required', 'string', Rule::in(array_keys(CheckType::all()))],
            'status' => ['required', 'string', Rule::in([
                VerificationStatus::VERIFIED,
                VerificationStatus::MISMATCH,
                VerificationStatus::NOT_FOUND,
                VerificationStatus::MANUAL_REVIEW,
            ])],
            'observed' => ['nullable', 'array'],
            'observed.*' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:120'],
            'person_id' => ['nullable', 'integer'],
        ]);

        [$subjectType, $subjectId, $subjectLabel] = $this->subject($supplier, $data['person_id'] ?? null);

        $observation = $this->engine->recordManual(
            supplier: $supplier,
            checkType: $data['check_type'],
            status: $data['status'],
            observed: array_filter($data['observed'] ?? [], fn ($v) => $v !== null && $v !== ''),
            note: $data['note'] ?? null,
            reference: $data['reference'] ?? null,
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
        );

        return back()->with('success', __('supplier_management.verifications.recorded', [
            'check' => $observation->checkLabel(),
            'status' => $observation->status,
        ]));
    }

    /**
     * Capture the supplier's Tax Compliance Status PIN.
     *
     * Stored encrypted, and stored at all only because it is what a tax compliance check needs: the
     * PIN is the supplier's own authorisation for a third party to see their status. It is never the
     * evidence — the verification it enables is.
     */
    public function storeTcsPin(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'tcs_pin' => ['required', 'string', 'max:60'],
            'tcs_pin_expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $supplier->update([
            'tcs_pin' => $data['tcs_pin'],
            'tcs_pin_expires_at' => $data['tcs_pin_expires_at'] ?? null,
        ]);

        return back()->with('success', __('supplier_management.verifications.pin_saved'));
    }

    /** Provider-specific inputs a check needs beyond the supplier's own fields. */
    private function contextFor(Supplier $supplier, string $checkType): array
    {
        return match ($checkType) {
            // Without the PIN there is nothing to verify against, so it is passed as context and the
            // provider decides what to do when it is absent.
            CheckType::TAX_COMPLIANCE => array_filter(['tcs_pin' => $supplier->tcs_pin]),
            default => [],
        };
    }

    /**
     * Whether this check is about the company or about one of its people.
     *
     * A person id that belongs to another supplier is rejected rather than quietly ignored — screening
     * the wrong person and filing it against this supplier would be worse than an error message.
     */
    private function subject(Supplier $supplier, ?int $personId): array
    {
        if (! $personId) {
            return ['supplier', null, $supplier->company_name];
        }

        $person = SupplierPerson::query()
            ->where('supplier_id', $supplier->id)
            ->findOrFail($personId);

        return ['person', $person->id, $person->name()];
    }
}
