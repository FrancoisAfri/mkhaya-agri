<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Exceptions\TaxControlException;
use App\Modules\Finance\Tax\Models\TaxAccountClassification;
use App\Modules\Finance\Tax\Models\TaxCarryForward;
use App\Modules\Finance\Tax\Models\TaxComputation;
use App\Modules\Finance\Tax\Models\TaxComputationLine;
use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Services\IncomeTaxService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** §8 Income tax: computations, provisional estimates, classifications and carried-forward balances. */
class IncomeTaxController extends TaxController
{
    public function __construct(private readonly IncomeTaxService $tax)
    {
    }

    public function index(): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $business = $this->businessFilter();

        $computations = TaxComputation::query()->with('period:id,uuid,label')->forBusiness($business)
            ->where('status', '!=', 'superseded')->orderByDesc('year_end')->orderBy('kind')->orderBy('provisional_number')->get();
        $periods = TaxPeriod::query()->with('taxType:id,code')->forBusiness($business)
            ->whereHas('taxType', fn ($q) => $q->whereIn('code', ['CIT', 'PROV']))->whereIn('status', ['open', 'in_preparation', 'rejected'])
            ->orderBy('end_date')->get();

        return Inertia::render('Modules/Finance/Tax/IncomeTax', [
            'computations' => $computations->map(fn (TaxComputation $c) => $this->row($c)),
            'periods' => $periods->map(fn ($p) => ['uuid' => $p->uuid, 'label' => "{$p->taxType->code} · {$p->label} · " . $this->entityName($p->entity_id),
                'tax_type' => $p->taxType->code]),
            'carryForwards' => TaxCarryForward::query()->forBusiness($business)->orderByDesc('year_end')->get()->map(fn ($c) => [
                'id' => $c->id, 'entity' => $this->entityName($c->entity_id), 'kind' => $c->kind, 'year_end' => $c->year_end->toDateString(),
                'opening' => (string) $c->opening, 'movement' => (string) $c->movement, 'closing' => (string) $c->closing,
                'source' => $c->source, 'note' => $c->note]),
            'can' => ['prepare' => request()->user()->can(TaxPermissions::PREPARE)],
        ] + $this->businessProps($business));
    }

    public function show(TaxComputation $computation): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $computation->load(['lines', 'period']);
        $names = collect($this->people())->pluck('name', 'id');
        $user = request()->user();

        return Inertia::render('Modules/Finance/Tax/ComputationShow', [
            'computation' => $this->row($computation) + [
                'lines' => $computation->lines->map(fn (TaxComputationLine $l) => ['id' => $l->id, 'section' => $l->section,
                    'account_code' => $l->account_code, 'description' => $l->description, 'amount' => (string) $l->amount, 'source' => $l->source,
                    'reason' => $l->reason, 'reference' => $l->reference]),
                'figures' => collect(['accounting_profit', 'add_backs', 'deductions', 'exempt_income', 'allowances', 'losses_set_off',
                    'taxable_income', 'tax', 'credits', 'amount_payable'])->mapWithKeys(fn ($k) => [$k => (string) $computation->{$k}]),
                'rate' => $computation->rate === null ? null : rtrim(rtrim((string) $computation->rate, '0'), '.'),
                'basic_amount' => $computation->basic_amount === null ? null : (string) $computation->basic_amount,
                'validation' => $computation->validation, 'snapshot' => $computation->snapshot,
                'prepared_by' => $names[$computation->prepared_by] ?? null, 'reviewed_by' => $names[$computation->reviewed_by] ?? null,
                'approved_by' => $names[$computation->approved_by] ?? null, 'review_comment' => $computation->review_comment,
                'ids' => ['prepared_by' => $computation->prepared_by, 'reviewed_by' => $computation->reviewed_by],
                'documents' => TaxEvidenceController::listFor($computation),
            ],
            'sections' => TaxComputationLine::SECTIONS,
            'position' => $this->tax->yearPosition($computation->entity_id, $computation->year_end),
            'versions' => TaxComputation::query()->where('period_id', $computation->period_id)->where('kind', $computation->kind)
                ->where('provisional_number', $computation->provisional_number)->orderByDesc('version')->get(['uuid', 'version', 'status', 'amount_payable'])
                ->map(fn ($v) => ['uuid' => $v->uuid, 'version' => $v->version, 'status' => $v->status, 'amount_payable' => (string) $v->amount_payable]),
            'can' => ['prepare' => $user->can(TaxPermissions::PREPARE), 'review' => $user->can(TaxPermissions::REVIEW), 'approve' => $user->can(TaxPermissions::APPROVE)],
            'me' => $user->id,
        ]);
    }

    public function prepare(Request $request, TaxPeriod $period): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'income_tax.prepare');
        $d = $request->validate(['estimate' => ['nullable', 'numeric'], 'basis' => ['nullable', 'in:estimate,trial_balance,prior_year'],
            'basic_amount' => ['nullable', 'numeric'], 'number' => ['nullable', 'integer', 'between:1,3']]);

        try {
            $comp = $period->taxType?->code === 'PROV'
                ? $this->tax->prepareProvisional($period, (string) ($d['estimate'] ?? ''), $d['basis'] ?? 'estimate',
                    isset($d['basic_amount']) ? (string) $d['basic_amount'] : null, $d['number'] ?? null)
                : $this->tax->prepareCit($period);
        } catch (TaxControlException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tax.income.show', $comp)->with('success', $comp->label() . " v{$comp->version} prepared.");
    }

    public function addLine(Request $request, TaxComputation $computation): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'income_tax.adjust');
        $d = $request->validate(['section' => ['required', 'in:add_back,deduction,exempt,allowance'], 'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:300'], 'reason' => ['required', 'string', 'min:10', 'max:2000'], 'reference' => ['nullable', 'string', 'max:120']]);

        return $this->attempt(fn () => $this->tax->addLine($computation, $d['section'], (string) $d['amount'], $d['description'], $d['reason'], $d['reference'] ?? null), 'Adjustment added.');
    }

    public function removeLine(Request $request, TaxComputation $computation, TaxComputationLine $line): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'income_tax.adjust');
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->attempt(fn () => $this->tax->removeLine($computation, $line, $d['reason']), 'Adjustment removed.');
    }

    public function send(TaxComputation $computation): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'income_tax.send');

        return $this->attempt(fn () => $this->tax->sendForReview($computation), 'Sent for review.');
    }

    public function review(Request $request, TaxComputation $computation): RedirectResponse
    {
        $this->allow(TaxPermissions::REVIEW, 'income_tax.review');
        $d = $request->validate(['pass' => ['required', 'boolean'], 'comment' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->attempt(fn () => $this->tax->review($computation, (bool) $d['pass'], $d['comment']), $d['pass'] ? 'Reviewed.' : 'Sent back.');
    }

    public function approve(Request $request, TaxComputation $computation): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'income_tax.approve');
        $d = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return $this->attempt(fn () => $this->tax->approve($computation, $d['comment'] ?? null), 'Approved.');
    }

    public function evidence(Request $request, TaxComputation $computation, DocumentService $documents): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'income_tax.evidence');
        $d = $request->validate(['file' => ['required', 'file', 'max:20480'], 'title' => ['required', 'string', 'max:200']]);
        $stored = $documents->store($request->file('file'), ['title' => $d['title'], 'description' => 'Income tax evidence', 'is_confidential' => true]);
        if (! $stored['document']) {
            return back()->with('error', $stored['error'] ?? 'The file could not be stored.');
        }

        return $this->attempt(fn () => $this->tax->attachEvidence($computation, $stored['document'], $d['title']), 'Evidence filed and linked.');
    }

    /** Classification workspace: the year's P&L accounts with their tax treatment. */
    public function classifications(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $business = $this->businessFilter() ?? $this->businesses()->single() ?? ($this->businesses()->ids()[0] ?? null);
        $end = $request->query('year_end') ? Carbon::parse($request->query('year_end')) : now()->endOfMonth();
        $start = $end->copy()->subYear()->addDay();
        $ledger = $business ? $this->tax->profitAndLoss((int) $request->user()->company_id, $business, $start, $end) : collect();
        $classes = TaxAccountClassification::query()->where('entity_id', $business)->get()->keyBy('account_id');

        return Inertia::render('Modules/Finance/Tax/Classifications', [
            'accounts' => $ledger->map(fn ($r) => ['account_id' => $r->account_id, 'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                'amount' => $r->amount, 'treatment' => $classes[$r->account_id]->treatment ?? null, 'note' => $classes[$r->account_id]->note ?? null])->values(),
            'treatments' => TaxAccountClassification::TREATMENTS,
            'year' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'can' => ['classify' => $request->user()->can(TaxPermissions::PREPARE)],
        ] + $this->businessProps($business));
    }

    public function classify(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'income_tax.classify');
        $d = $request->validate(['entity_id' => ['required', 'integer', $this->businessRule()], 'account_id' => ['required', 'integer'],
            'treatment' => ['required', 'in:' . implode(',', array_keys(TaxAccountClassification::TREATMENTS))], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->tax->classify((int) $d['entity_id'], (int) $d['account_id'], $d['treatment'], $d['note'] ?? null), 'Classification saved.');
    }

    public function openingBalance(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'income_tax.opening_balance');
        $d = $request->validate(['entity_id' => ['required', 'integer', $this->businessRule()], 'kind' => ['required', 'in:assessed_loss,allowance_balance'],
            'year_end' => ['required', 'date'], 'closing' => ['required', 'numeric', 'min:0'], 'basis' => ['required', 'string', 'min:10', 'max:1000']]);

        return $this->attempt(fn () => $this->tax->recordOpeningBalance((int) $d['entity_id'], $d['kind'], $d['year_end'], (string) $d['closing'], $d['basis']), 'Opening balance recorded.');
    }

    private function row(TaxComputation $c): array
    {
        return [
            'uuid' => $c->uuid, 'label' => $c->label(), 'kind' => $c->kind, 'number' => $c->provisional_number, 'version' => $c->version,
            'status' => $c->status, 'entity' => $this->entityName($c->entity_id), 'period' => $c->period?->label, 'period_uuid' => $c->period?->uuid,
            'year_start' => $c->year_start->toDateString(), 'year_end' => $c->year_end->toDateString(), 'basis' => $c->basis,
            'taxable_income' => (string) $c->taxable_income, 'tax' => (string) $c->tax, 'amount_payable' => (string) $c->amount_payable,
        ];
    }
}
