<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\ReputationMonitor;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierFinding;
use App\Modules\SupplierManagement\Models\SupplierHandle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Running the watching, and deciding about what it finds.
 *
 * Adjudication carries the same rule as sanctions screening, for the same reason: clearing a finding
 * needs a stated reason, because "not our supplier" is a judgement somebody may have to defend a year
 * later and an unexplained dismissal cannot be told apart from a mis-click.
 */
class SupplierReputationController extends Controller
{
    public function __construct(
        private readonly ReputationMonitor $monitor,
        private readonly RiskEngine $risk,
    ) {}

    public function run(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'scope' => ['nullable', Rule::in(['social', 'news', 'full'])],
        ]);

        $run = $this->monitor->run($supplier, $data['scope'] ?? 'full', 'manual');
        $this->risk->assess($supplier->fresh(), 'monitoring');

        if ($run->sources_queried === 0) {
            return back()->with('warning', __('supplier_management.reputation.no_sources'));
        }

        return back()->with(
            $run->findings_new > 0 ? 'warning' : 'success',
            __('supplier_management.reputation.ran', [
                'sources' => $run->sources_queried,
                'new' => $run->findings_new,
                'review' => $run->below_confidence_floor,
            ])
        );
    }

    /** A decision on one finding. */
    public function adjudicate(Request $request, Supplier $supplier, SupplierFinding $finding): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $finding->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                SupplierFinding::STATUS_CONFIRMED,
                SupplierFinding::STATUS_FALSE_POSITIVE,
                SupplierFinding::STATUS_DISMISSED,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
            // A person may correct the machine's reading as well as its verdict.
            'category' => ['nullable', Rule::in(array_keys(SupplierFinding::CATEGORIES))],
            'severity' => ['nullable', Rule::in(array_keys(SupplierFinding::SEVERITIES))],
        ]);

        if (in_array($data['status'], [SupplierFinding::STATUS_FALSE_POSITIVE, SupplierFinding::STATUS_DISMISSED], true)
            && trim((string) ($data['note'] ?? '')) === '') {
            return back()->withErrors(['note' => __('supplier_management.reputation.errors.reason_required')]);
        }

        // A corrected category is the person's, not the model's — recorded as such so the provenance
        // of every field on the finding stays honest.
        if (! empty($data['category']) || ! empty($data['severity'])) {
            $finding->forceFill(array_filter([
                'category' => $data['category'] ?? null,
                'severity' => $data['severity'] ?? null,
                'is_positive' => ($data['category'] ?? null) === 'positive',
                'ai_generated' => false,
            ], fn ($v) => $v !== null))->save();
        }

        $finding->adjudicate($data['status'], Auth::id(), $data['note'] ?? null);
        $this->risk->assess($supplier->fresh(), 'event');

        return back()->with('success', __('supplier_management.reputation.adjudicated'));
    }

    /** What to watch: a brand, a website or a platform handle. */
    public function storeHandle(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'kind' => ['required', Rule::in([
                SupplierHandle::KIND_BRAND, SupplierHandle::KIND_WEBSITE,
                SupplierHandle::KIND_HANDLE, SupplierHandle::KIND_PERSON,
            ])],
            'platform' => ['nullable', 'string', 'max:30'],
            'value' => ['required', 'string', 'max:250'],
            'person_id' => ['nullable', 'integer'],
        ]);

        SupplierHandle::create($data + ['supplier_id' => $supplier->id, 'added_by_source' => 'manual']);

        return back()->with('success', __('supplier_management.reputation.handle_added'));
    }

    public function destroyHandle(Supplier $supplier, SupplierHandle $handle): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $handle->supplier_id === (int) $supplier->id, 404);

        $handle->delete();

        return back()->with('success', __('supplier_management.reputation.handle_removed'));
    }
}
