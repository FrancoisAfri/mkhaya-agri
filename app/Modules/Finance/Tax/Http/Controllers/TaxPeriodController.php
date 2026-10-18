<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Services\TaxPeriodService;
use App\Modules\Finance\Tax\Services\VatRegisterService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §9 Tax periods: list, one period's workspace, and its lifecycle. */
class TaxPeriodController extends TaxController
{
    public function __construct(
        private readonly TaxPeriodService $periods,
        private readonly VatRegisterService $register,
        private readonly \App\Modules\Finance\Tax\Services\PayrollTaxService $payroll,
    ) {
    }

    /** §7: bring released payroll into a PAYE (EMP201) period. */
    public function importPayroll(TaxPeriod $period): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'payroll_tax.import');

        return $this->attempt(function () use ($period) {
            $s = $this->payroll->import($period);

            return "Payroll imported: {$s['runs']} released run(s), {$s['payslips']} payslip(s); {$s['created']} new line(s), {$s['superseded']} changed, {$s['excluded']} withdrawn. {$s['exceptions']} need attention.";
        }, 'Payroll imported.');
    }

    public function reconcilePayroll(TaxPeriod $period): RedirectResponse
    {
        $this->allow(TaxPermissions::RECONCILE, 'reconciliation.run');
        try {
            $recon = app(\App\Modules\Finance\Tax\Services\TaxReconciliationService::class)->runPayrollToLedger($period);
        } catch (\App\Modules\Finance\Tax\Exceptions\TaxControlException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.reconciliations.show', $recon)->with('success', "Payroll reconciliation v{$recon->version} complete.");
    }

    public function index(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $filters = $request->only(['tax_type', 'status', 'year']);
        $business = $this->businessFilter();

        $rows = TaxPeriod::query()->with('taxType')->forBusiness($business)
            ->when($filters['tax_type'] ?? null, fn ($q, $c) => $q->whereHas('taxType', fn ($t) => $t->where('code', $c)))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['year'] ?? null, fn ($q, $y) => $q->whereYear('end_date', $y))
            ->orderByDesc('end_date')->paginate(50)->withQueryString();

        return Inertia::render('Modules/Finance/Tax/Periods', [
            'periods' => $rows->through(fn (TaxPeriod $p) => $this->row($p)),
            'filters' => $filters,
            'statuses' => TaxPeriod::STATUSES,
        ] + $this->businessProps($business));
    }

    public function show(TaxPeriod $period): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $period->load(['taxType', 'registration', 'events']);
        $names = collect($this->people())->pluck('name', 'id');
        $isVat = $period->taxType?->code === 'VAT';
        $isPaye = $period->taxType?->code === 'PAYE';
        $user = request()->user();

        return Inertia::render('Modules/Finance/Tax/PeriodShow', [
            'period' => $this->row($period) + [
                'registration_reference' => $period->registration?->reference,
                'events' => $period->events->map(fn ($e) => ['from' => $e->from_status, 'to' => $e->to_status, 'reason' => $e->reason,
                    'by' => $names[$e->user_id] ?? 'System', 'at' => $e->created_at?->toDateTimeString()])->values(),
            ],
            'blockers' => $this->periods->blockers($period),
            'summary' => $isVat ? $this->register->summary($period) : null,
            'counts' => [
                'lines' => TaxTransaction::query()->where('period_id', $period->id)->current()->count(),
                'exceptions' => TaxTransaction::query()->where('period_id', $period->id)->current()->where('has_exception', true)->count(),
            ],
            'reconciliation' => TaxReconciliation::query()->where('period_id', $period->id)->orderByDesc('version')->first(['uuid', 'version', 'status', 'difference', 'exception_count', 'type']),
            'payroll' => $isPaye ? $this->payroll->summary($period) : null,
            // Employee-level payroll tax is personal information (§16): only with tax.payroll.view.
            'employees' => $isPaye && $user->can(TaxPermissions::VIEW_PAYROLL) ? $this->payroll->employees($period) : null,
            'taxReturn' => \App\Modules\Finance\Tax\Models\TaxReturn::query()->where('period_id', $period->id)->orderByDesc('version')
                ->first(['uuid', 'reference', 'version', 'status', 'amount_payable', 'submission_status', 'payment_status']),
            'can' => [
                'prepare' => request()->user()->can(TaxPermissions::PREPARE),
                'review' => request()->user()->can(TaxPermissions::REVIEW),
                'approve' => request()->user()->can(TaxPermissions::APPROVE),
                'manage' => request()->user()->can(TaxPermissions::MANAGE_PERIODS),
                'reopen' => request()->user()->can(TaxPermissions::REOPEN_PERIODS),
                'reconcile' => request()->user()->can(TaxPermissions::RECONCILE),
            ],
        ]);
    }

    public function refresh(TaxPeriod $period): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'vat_register.refresh');

        return $this->attempt(function () use ($period) {
            $s = $this->register->refresh($period);

            return "Register updated: {$s['created']} new, {$s['superseded']} changed, {$s['deltas']} change(s) to finalised periods, {$s['late']} late document(s), {$s['unchanged']} unchanged. {$s['exceptions']} line(s) need attention.";
        }, 'Register updated.');
    }

    public function transition(Request $request, TaxPeriod $period): RedirectResponse
    {
        // Permission before validation: someone with no tax access gets a refusal, not a form error.
        $this->allow(TaxPermissions::VIEW, 'period.transition');
        $data = $request->validate([
            'to' => ['required', 'in:' . implode(',', TaxPeriod::STATUSES)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        // Each step needs the permission of the person who normally takes it (§10.3).
        $this->allow(match ($data['to']) {
            'under_review', 'in_preparation', 'open' => TaxPermissions::PREPARE,
            'approved' => TaxPermissions::APPROVE,
            'submitted', 'accepted', 'rejected' => TaxPermissions::APPROVE,
            'closed', 'locked' => TaxPermissions::MANAGE_PERIODS,
        }, 'period.' . $data['to']);

        return $this->attempt(fn () => $this->periods->transition($period, $data['to'], $data['reason'] ?? null,
            array_filter(['reference' => $data['reference'] ?? null])), 'Period is now ' . str_replace('_', ' ', $data['to']) . '.');
    }

    public function reopen(Request $request, TaxPeriod $period): RedirectResponse
    {
        $this->allow(TaxPermissions::REOPEN_PERIODS, 'period.reopen');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->attempt(fn () => $this->periods->reopen($period, $data['reason']), 'Period reopened. The earlier figures and history are kept.');
    }

    private function row(TaxPeriod $p): array
    {
        return [
            'uuid' => $p->uuid, 'label' => $p->label, 'tax_type' => $p->taxType?->code, 'entity' => $this->entityName($p->entity_id),
            'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString(),
            'filing_due_date' => $p->filing_due_date?->toDateString(), 'payment_due_date' => $p->payment_due_date?->toDateString(),
            'status' => $p->status, 'reopen_count' => $p->reopen_count,
            'overdue' => $p->filing_due_date && $p->filing_due_date->lt(today()) && ! in_array($p->status, ['submitted', 'accepted', 'closed', 'locked'], true),
        ];
    }
}
