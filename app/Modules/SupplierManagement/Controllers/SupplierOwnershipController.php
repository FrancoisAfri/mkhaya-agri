<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\OwnershipDiscrepancy;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierEntity;
use App\Modules\SupplierManagement\Models\SupplierOwnership;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Building and reading a supplier's ownership chain.
 *
 * Capturing a stake is deliberately a small, explicit act — holder, subject, percentage, as at when,
 * from what source — rather than a free-text ownership field. The graph is only as good as the edges
 * in it, and an ownership note nobody can walk is what the module had before.
 */
class SupplierOwnershipController extends Controller
{
    public function __construct(private readonly OwnershipDiscrepancy $discrepancy) {}

    /** Add a stake. The holder may be a person or a company; the subject is always a company node. */
    public function store(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'holder_kind' => ['required', Rule::in([SupplierOwnership::KIND_PERSON, SupplierOwnership::KIND_ENTITY])],
            'holder_id' => ['nullable', 'integer'],
            // A holder who is not on file yet is created here, so capturing a chain does not mean
            // leaving the screen to add each company first.
            'holder_name' => ['nullable', 'string', 'max:250'],
            'holder_registration_number' => ['nullable', 'string', 'max:60'],
            'subject_entity_id' => ['nullable', 'integer'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ownership_type' => ['nullable', Rule::in(SupplierOwnership::TYPES)],
            'as_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Defaults to the supplier itself, which is the common case: most stakes are held directly
        // in the company you are buying from. `??` rather than a bare index because validate() omits
        // a nullable key the request never sent.
        $subject = ! empty($data['subject_entity_id'])
            ? SupplierEntity::query()->where('supplier_id', $supplier->id)->findOrFail($data['subject_entity_id'])
            : SupplierEntity::rootFor($supplier);

        $holder = $this->resolveHolder($supplier, $data);

        // A company owning itself is not a structure, it is a typo — and it would be walked as a
        // cycle for the rest of the supplier's life.
        if ($data['holder_kind'] === SupplierOwnership::KIND_ENTITY && (int) $holder->id === (int) $subject->id) {
            throw ValidationException::withMessages([
                'holder_id' => __('supplier_management.ownership.errors.self_ownership'),
            ]);
        }

        SupplierOwnership::create([
            'supplier_id' => $supplier->id,
            'holder_kind' => $data['holder_kind'],
            'holder_id' => $holder->id,
            'subject_entity_id' => $subject->id,
            'percentage' => $data['percentage'] ?? null,
            'ownership_type' => $data['ownership_type'] ?? 'shares',
            'as_at' => $data['as_at'] ?? now()->toDateString(),
            'source' => 'supplier',
            'notes' => $data['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', __('supplier_management.ownership.added'));
    }

    /**
     * Close a stake off, or correct it.
     *
     * Ending is the normal case and deletion is not offered: "who owned this when the contract was
     * awarded" has to stay answerable, so a stake that has ended is dated rather than removed.
     */
    public function update(Request $request, Supplier $supplier, SupplierOwnership $ownership): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $ownership->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate([
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ownership_type' => ['nullable', Rule::in(SupplierOwnership::TYPES)],
            'as_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $ownership->update(array_filter($data, fn ($value, $key) => $value !== null || $key === 'ended_at', ARRAY_FILTER_USE_BOTH));

        return back()->with('success', __('supplier_management.ownership.updated'));
    }

    /**
     * Record what a registry says the beneficial owners are, and compare.
     *
     * Entered by hand until a beneficial-ownership source is configured — which is the point of the
     * manual path: the comparison is worth having long before the integration is.
     */
    public function compare(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'owners' => ['required', 'array', 'min:1'],
            'owners.*.name' => ['required', 'string', 'max:250'],
            'owners.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $observation = $this->discrepancy->compare(
            $supplier,
            $data['owners'],
            $data['source'] ?? 'registry',
            $data['reference'] ?? null,
        );

        return back()->with(
            $observation->passed() ? 'success' : 'warning',
            $observation->summary ?: $observation->note
        );
    }

    /**
     * A holder, found or created.
     *
     * Matching on normalised name before creating is what stops "ABC Holdings (Pty) Ltd" and "ABC
     * HOLDINGS PROPRIETARY LIMITED" becoming two nodes — which would hide the shared parent the
     * graph exists to reveal.
     */
    private function resolveHolder(Supplier $supplier, array $data): SupplierPerson|SupplierEntity
    {
        if ($data['holder_kind'] === SupplierOwnership::KIND_PERSON) {
            if (! empty($data['holder_id'])) {
                return SupplierPerson::query()->where('supplier_id', $supplier->id)->findOrFail($data['holder_id']);
            }

            $name = trim((string) ($data['holder_name'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages(['holder_name' => __('supplier_management.ownership.errors.holder_required')]);
            }

            $parts = preg_split('/\s+/', $name) ?: [$name];

            return SupplierPerson::create([
                'supplier_id' => $supplier->id,
                'first_name' => count($parts) > 1 ? implode(' ', array_slice($parts, 0, -1)) : $name,
                'surname' => count($parts) > 1 ? end($parts) : null,
                'role' => 'shareholder',
                'source' => 'supplier',
            ]);
        }

        if (! empty($data['holder_id'])) {
            return SupplierEntity::query()->where('supplier_id', $supplier->id)->findOrFail($data['holder_id']);
        }

        $name = trim((string) ($data['holder_name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages(['holder_name' => __('supplier_management.ownership.errors.holder_required')]);
        }

        $normalised = app(\App\Modules\SupplierManagement\DueDiligence\Services\FieldComparator::class)->normaliseEntityName($name);

        $existing = SupplierEntity::query()
            ->where('supplier_id', $supplier->id)
            ->where('normalised_name', $normalised)
            ->first();

        return $existing ?: SupplierEntity::create([
            'supplier_id' => $supplier->id,
            'name' => $name,
            'registration_number' => $data['holder_registration_number'] ?? null,
            'source' => 'supplier',
        ]);
    }
}
