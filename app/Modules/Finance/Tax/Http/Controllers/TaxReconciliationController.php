<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxReconciliation;
use App\Modules\Finance\Tax\Models\TaxReconciliationItem;
use App\Modules\Finance\Tax\Services\TaxReconciliationService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** §12 Reconciliation workspace. */
class TaxReconciliationController extends TaxController
{
    public function __construct(private readonly TaxReconciliationService $recons)
    {
    }

    public function index(): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $names = collect($this->people())->pluck('name', 'id');
        $business = $this->businessFilter();

        return Inertia::render('Modules/Finance/Tax/Reconciliations', [
            'reconciliations' => TaxReconciliation::query()->with('period:id,uuid,label')->forBusiness($business)->orderByDesc('id')->limit(200)->get()
                ->map(fn ($r) => $this->row($r, $names)),
            'periods' => TaxPeriod::query()->with('taxType:id,code')->forBusiness($business)
                ->whereHas('taxType', fn ($q) => $q->whereIn('code', ['VAT', 'PAYE', 'CIT', 'PROV']))
                ->whereDate('start_date', '<=', today())->orderByDesc('end_date')->limit(60)->get()
                ->map(fn ($p) => ['uuid' => $p->uuid, 'label' => "{$p->taxType->code} · {$p->label} · " . $this->entityName($p->entity_id),
                    'tax_type' => $p->taxType->code, 'processable' => in_array($p->status, ['open', 'in_preparation', 'rejected'], true)]),
            'types' => TaxReconciliation::TYPES,
            'can' => ['run' => request()->user()->can(TaxPermissions::RECONCILE)],
        ] + $this->businessProps($business));
    }

    public function show(TaxReconciliation $reconciliation): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $names = collect($this->people())->pluck('name', 'id');
        $reconciliation->load('period');

        return Inertia::render('Modules/Finance/Tax/ReconciliationShow', [
            'reconciliation' => $this->row($reconciliation, $names) + [
                'type_key' => $reconciliation->type, 'summary' => $reconciliation->summary, 'diagnostics' => $reconciliation->diagnostics,
                'sign_off_comment' => $reconciliation->sign_off_comment, 'cutoff_at' => $reconciliation->cutoff_at?->toDateTimeString(),
                'evidence' => TaxEvidenceController::listFor($reconciliation),
            ],
            'items' => $reconciliation->items()->orderByRaw("CASE match_state WHEN 'exception' THEN 0 WHEN 'unmatched' THEN 1 WHEN 'partial' THEN 2 ELSE 3 END")
                ->orderBy('id')->get()->map(fn (TaxReconciliationItem $i) => [
                    'id' => $i->id, 'side' => $i->side, 'reference' => $i->reference, 'description' => $i->description,
                    'source' => (string) $i->source_amount, 'target' => (string) $i->target_amount, 'difference' => (string) $i->difference,
                    'state' => $i->match_state, 'class' => $i->difference_class, 'explanation' => $i->explanation,
                    'explained_by' => $names[$i->explained_by] ?? null, 'links' => $i->links,
                    'assigned_to' => $i->assigned_to, 'assignee' => $names[$i->assigned_to] ?? null,
                ]),
            'can' => [
                'reconcile' => request()->user()->can(TaxPermissions::RECONCILE),
                'sign_off' => request()->user()->can(TaxPermissions::SIGN_OFF),
                'reopen' => request()->user()->can(TaxPermissions::REOPEN_PERIODS),
            ],
            'me' => request()->user()->id,
            'people' => $this->people(),
        ]);
    }

    public function run(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::RECONCILE, 'reconciliation.run');
        $data = $request->validate(['period' => ['required', 'uuid'], 'tolerance' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'type' => ['nullable', 'in:' . implode(',', array_keys(TaxReconciliation::TYPES))]]);
        $period = TaxPeriod::where('uuid', $data['period'])->firstOrFail();
        $tolerance = isset($data['tolerance']) ? (string) $data['tolerance'] : null;

        try {
            $recon = match ($data['type'] ?? 'vat_register_to_gl') {
                'payroll_to_gl' => $this->recons->runPayrollToLedger($period, $tolerance),
                'register_completeness' => $this->recons->runRegisterCompleteness($period, $tolerance),
                'liabilities_to_payments' => $this->recons->runLiabilitiesToPayments($period, $tolerance),
                default => $this->recons->runVatToLedger($period, $tolerance),
            };
        } catch (\App\Modules\Finance\Tax\Exceptions\TaxControlException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.reconciliations.show', $recon)->with('success', "Reconciliation v{$recon->version} complete.");
    }

    public function explain(Request $request, TaxReconciliationItem $item): RedirectResponse
    {
        $this->allow(TaxPermissions::RECONCILE, 'reconciliation.explain');
        $data = $request->validate(['explanation' => ['required', 'string', 'min:5', 'max:2000'], 'class' => ['nullable', 'in:timing,rounding,missing,genuine']]);

        return $this->attempt(fn () => $this->recons->explain($item, $data['explanation'], $data['class'] ?? null), 'Explanation saved.');
    }

    public function match(Request $request, TaxReconciliationItem $item): RedirectResponse
    {
        $this->allow(TaxPermissions::RECONCILE, 'reconciliation.match');
        $d = $request->validate(['with' => ['required', 'integer'], 'rationale' => ['required', 'string', 'min:10', 'max:2000']]);
        $other = TaxReconciliationItem::query()->findOrFail($d['with']);

        return $this->attempt(fn () => $this->recons->matchManually($item, $other, $d['rationale']), 'Items matched.');
    }

    public function assign(Request $request, TaxReconciliationItem $item): RedirectResponse
    {
        $this->allow(TaxPermissions::RECONCILE, 'reconciliation.assign');
        $d = $request->validate(['user_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::in(collect($this->people())->pluck('id')->all())]]);

        return $this->attempt(fn () => $this->recons->assign($item, $d['user_id'] ?? null), 'Assigned.');
    }

    public function signOff(Request $request, TaxReconciliation $reconciliation): RedirectResponse
    {
        $this->allow(TaxPermissions::SIGN_OFF, 'reconciliation.sign_off');
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->recons->signOff($reconciliation, $data['comment'] ?? null), 'Signed off.');
    }

    public function reopen(Request $request, TaxReconciliation $reconciliation): RedirectResponse
    {
        $this->allow(TaxPermissions::REOPEN_PERIODS, 'reconciliation.reopen');
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->attempt(fn () => $this->recons->reopen($reconciliation, $data['reason']), 'Reopened. Run a new version to reconcile again.');
    }

    private function row(TaxReconciliation $r, $names): array
    {
        return [
            'uuid' => $r->uuid, 'type' => TaxReconciliation::TYPES[$r->type] ?? $r->type, 'period' => $r->period?->label,
            'period_uuid' => $r->period?->uuid, 'entity' => $this->entityName($r->entity_id), 'version' => $r->version, 'status' => $r->status,
            'source_total' => (string) $r->source_total, 'target_total' => (string) $r->target_total, 'difference' => (string) $r->difference,
            'tolerance' => (string) $r->tolerance, 'matched' => $r->matched_count, 'unmatched' => $r->unmatched_count, 'exceptions' => $r->exception_count,
            'prepared_by' => $names[$r->prepared_by] ?? null, 'prepared_by_id' => $r->prepared_by,
            'signed_off_by' => $names[$r->signed_off_by] ?? null, 'signed_off_at' => $r->signed_off_at?->toDateTimeString(),
            'created_at' => $r->created_at?->toDateTimeString(),
        ];
    }
}
