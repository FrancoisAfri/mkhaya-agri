<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskTiering;
use App\Modules\SupplierManagement\DueDiligence\Services\ScreeningService;
use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Assessing a supplier: score it, tier the engagement, screen the people, capture a credit report,
 * and adjudicate what comes back.
 *
 * Every action here writes a record somebody can be asked about later — the score keeps its inputs
 * and weights, the adjudication keeps its reason, the credit pull keeps its date. None of them
 * change the supplier's approval state on their own; that stays a decision a person makes with the
 * gates in front of them.
 */
class SupplierRiskController extends Controller
{
    public function __construct(
        private readonly RiskEngine $risk,
        private readonly RiskTiering $tiering,
        private readonly ScreeningService $screening,
    ) {}

    /** Recalculate and store a snapshot. */
    public function assess(Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $score = $this->risk->assess($supplier, 'manual');

        return back()->with(
            $score->auto_approvable ? 'success' : 'warning',
            __('supplier_management.risk.assessed', [
                'score' => number_format((float) $score->total, 1),
                'band' => $score->bandLabel(),
            ])
        );
    }

    /** Set the diligence tier from what the engagement actually is. */
    public function tier(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $factors = $request->validate([
            'annual_spend' => ['nullable', 'numeric', 'min:0'],
            'contract_value' => ['nullable', 'numeric', 'min:0'],
            ...collect(RiskModel::tierFactors())
                ->mapWithKeys(fn ($definition, $key) => [$key => ['nullable', 'boolean']])
                ->all(),
        ]);

        $result = $this->tiering->apply($supplier, array_filter($factors, fn ($v) => $v !== null));

        // The tier changes what the score means, so the snapshot is refreshed rather than left
        // describing the previous tier's expectations.
        $this->risk->assess($supplier->fresh(), 'event');

        return back()->with('success', __('supplier_management.risk.tiered', [
            'tier' => $result['tier'],
            'label' => $result['label'],
        ]));
    }

    /** Screen the supplier and its people against a list type. */
    public function screen(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'list_type' => ['required', Rule::in([ScreeningHit::TYPE_SANCTIONS, ScreeningHit::TYPE_PEP, ScreeningHit::TYPE_ADVERSE])],
        ]);

        $result = $this->screening->screen($supplier, $data['list_type']);
        $this->risk->assess($supplier->fresh(), 'event');

        if ($result['unavailable']) {
            return back()->with('warning', __('supplier_management.risk.screening_unavailable'));
        }

        return back()->with(
            $result['new_hits'] > 0 ? 'warning' : 'success',
            __('supplier_management.risk.screened', [
                'subjects' => $result['subjects'],
                'new' => $result['new_hits'],
            ])
        );
    }

    /** Record a hit somebody found on a published list. */
    public function recordHit(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'list_type' => ['required', Rule::in([ScreeningHit::TYPE_SANCTIONS, ScreeningHit::TYPE_PEP, ScreeningHit::TYPE_ADVERSE])],
            'matched_name' => ['required', 'string', 'max:250'],
            'list_name' => ['nullable', 'string', 'max:150'],
            'subject_type' => ['nullable', Rule::in(['supplier', 'person'])],
            'person_id' => ['nullable', 'integer'],
            'external_ref' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->screening->recordHit(
            supplier: $supplier,
            listType: $data['list_type'],
            matchedName: $data['matched_name'],
            subjectType: $data['subject_type'] ?? ($data['person_id'] ?? null ? 'person' : 'supplier'),
            subjectId: $data['person_id'] ?? null,
            listName: $data['list_name'] ?? null,
            details: array_filter(['note' => $data['note'] ?? null]),
            externalRef: $data['external_ref'] ?? null,
        );

        $this->risk->assess($supplier->fresh(), 'event');

        return back()->with('warning', __('supplier_management.risk.hit_recorded'));
    }

    /** A person's verdict on a match. */
    public function adjudicate(Request $request, Supplier $supplier, ScreeningHit $hit): RedirectResponse
    {
        $this->authorize('update', $supplier);

        abort_unless((int) $hit->supplier_id === (int) $supplier->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                ScreeningHit::STATUS_CONFIRMED,
                ScreeningHit::STATUS_FALSE_POSITIVE,
                ScreeningHit::STATUS_DISMISSED,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->screening->adjudicate($hit, $data['status'], $data['note'] ?? null);
        } catch (\InvalidArgumentException $e) {
            // Clearing a match without saying why leaves a decision nobody can defend later.
            throw ValidationException::withMessages(['note' => __('supplier_management.risk.errors.reason_required')]);
        }

        $this->risk->assess($supplier->fresh(), 'event');

        return back()->with('success', __('supplier_management.risk.adjudicated'));
    }

    /** Capture a credit report. Manual until a bureau is licensed — the history is worth having now. */
    public function storeCreditReport(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'provider_code' => ['nullable', 'string', 'max:60'],
            'score' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'score_max' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'risk_band' => ['nullable', 'string', 'max:30'],
            'payment_behaviour' => ['nullable', Rule::in(['on_time', 'slow', 'delinquent'])],
            'defaults_count' => ['nullable', 'integer', 'min:0'],
            'judgments_count' => ['nullable', 'integer', 'min:0'],
            'judgments_value' => ['nullable', 'numeric', 'min:0'],
            'collections_count' => ['nullable', 'integer', 'min:0'],
            'insolvency' => ['nullable', 'boolean'],
            'business_rescue' => ['nullable', 'boolean'],
            'recommended_limit' => ['nullable', 'numeric', 'min:0'],
            'report_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        SupplierCreditReport::create([
            'supplier_id' => $supplier->id,
            'provider_code' => $data['provider_code'] ?? 'manual',
            'score_max' => $data['score_max'] ?? 100,
            'report_date' => $data['report_date'],
        ] + collect($data)->except(['provider_code', 'score_max', 'report_date'])->all());

        $this->risk->assess($supplier->fresh(), 'event');

        return back()->with('success', __('supplier_management.risk.credit_recorded'));
    }
}
