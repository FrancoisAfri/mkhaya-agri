<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxRuleVersion;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Services\TaxRuleService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** §5 Tax configuration: codes as versioned, effective-dated rules. */
class TaxRuleController extends TaxController
{
    public function __construct(private readonly TaxRuleService $rules)
    {
    }

    public function index(): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $names = collect($this->people())->pluck('name', 'id');

        $rules = TaxRuleVersion::query()->with('taxType')->orderBy('tax_code')->orderByDesc('version')->get()
            ->map(fn (TaxRuleVersion $r) => [
                'uuid' => $r->uuid, 'tax_code' => $r->tax_code, 'name' => $r->name, 'version' => $r->version,
                'tax_type' => $r->taxType?->code, 'tax_type_id' => $r->tax_type_id, 'entity_id' => $r->entity_id,
                'entity' => $r->entity_id ? $this->entityName($r->entity_id) : 'All businesses',
                'treatment' => $r->treatment, 'direction' => $r->direction, 'method' => $r->method,
                'rate' => rtrim(rtrim((string) $r->rate, '0'), '.'), 'rounding' => $r->rounding, 'precision' => $r->precision,
                'control_account_id' => $r->control_account_id, 'return_field' => $r->return_field, 'reporting_class' => $r->reporting_class,
                'effective_from' => $r->effective_from?->toDateString(), 'effective_to' => $r->effective_to?->toDateString(),
                'status' => $r->status, 'legal_reference' => $r->legal_reference, 'change_reason' => $r->change_reason,
                'approval_comment' => $r->approval_comment, 'simulation' => $r->simulation,
                'created_by' => $names[$r->created_by] ?? null, 'approved_by' => $names[$r->approved_by] ?? null,
                'published_at' => $r->published_at?->toDateTimeString(),
                'in_force' => $r->status === 'published' && $r->appliesOn(today()),
            ]);

        return Inertia::render('Modules/Finance/Tax/Rules', [
            'rules' => $rules,
            'taxTypes' => TaxType::where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']),
            'accounts' => DB::table('fin_accounts')->where('company_id', request()->user()->company_id)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name']),
            'financeCodes' => DB::table('fin_tax_codes')->where('is_active', true)->orderBy('code')->pluck('code'),
            'options' => ['treatments' => TaxRuleVersion::TREATMENTS, 'directions' => TaxRuleVersion::DIRECTIONS, 'rounding' => TaxRuleVersion::ROUNDING],
            'can' => [
                'draft' => request()->user()->can(TaxPermissions::DRAFT_RULES),
                'approve' => request()->user()->can(TaxPermissions::APPROVE_RULES),
            ],
            'me' => request()->user()->id,
        ] + $this->businessProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::DRAFT_RULES, 'rule.draft');

        return $this->attempt(fn () => $this->rules->draft($this->validated($request)), 'Draft created. Simulate it, then submit it for approval.');
    }

    public function update(Request $request, TaxRuleVersion $rule): RedirectResponse
    {
        $this->allow(TaxPermissions::DRAFT_RULES, 'rule.edit');

        return $this->attempt(fn () => $this->rules->updateDraft($rule, $this->validated($request, false)), 'Draft saved. Simulate it again before submitting.');
    }

    public function simulate(Request $request, TaxRuleVersion $rule): RedirectResponse
    {
        $this->allow(TaxPermissions::DRAFT_RULES, 'rule.simulate');
        $data = $request->validate([
            'cases' => ['required', 'array', 'min:1', 'max:50'],
            'cases.*.amount' => ['required', 'numeric'],
            'cases.*.inclusive' => ['boolean'],
            'cases.*.expected_tax' => ['nullable', 'numeric'],
        ]);

        return $this->attempt(function () use ($rule, $data) {
            $sim = $this->rules->simulate($rule, $data['cases']);

            return $sim['passed'] ? 'Every test case passed. You can submit the rule.' : 'Some test cases failed — see the results.';
        }, 'Simulated.');
    }

    public function submit(Request $request, TaxRuleVersion $rule): RedirectResponse
    {
        $this->allow(TaxPermissions::DRAFT_RULES, 'rule.submit');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(fn () => $this->rules->submit($rule, $data['reason']), 'Submitted for approval.');
    }

    public function approve(Request $request, TaxRuleVersion $rule): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE_RULES, 'rule.approve');
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000'], 'close_previous' => ['boolean']]);

        return $this->attempt(fn () => $this->rules->approveAndPublish($rule, $data['comment'] ?? null, (bool) ($data['close_previous'] ?? false)), 'Approved and published.');
    }

    public function reject(Request $request, TaxRuleVersion $rule): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE_RULES, 'rule.reject');
        $data = $request->validate(['comment' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(fn () => $this->rules->reject($rule, $data['comment']), 'Returned to the author.');
    }

    public function retire(Request $request, TaxRuleVersion $rule): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE_RULES, 'rule.retire');
        $data = $request->validate(['from' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:2000']]);

        return $this->attempt(fn () => $this->rules->retire($rule, $data['from'], $data['reason']), 'Rule retired from that date.');
    }

    public function draftIncomeTax(): RedirectResponse
    {
        $this->allow(TaxPermissions::DRAFT_RULES, 'rule.import');

        return $this->attempt(fn () => app(\App\Modules\Finance\Tax\Services\TaxRuleService::class)->draftIncomeTaxRules()
            ? 'Drafted the company income tax rates (28% and 27%). Simulate and submit them for approval.'
            : 'Income tax (CIT) rules already exist.', 'Done.');
    }

    public function importFinanceCodes(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::DRAFT_RULES, 'rule.import');
        $data = $request->validate(['entity_id' => ['nullable', 'integer', $this->businessRule()]]);

        return $this->attempt(fn () => $this->rules->draftsFromFinanceCodes($data['entity_id'] ?? null) . ' draft rule(s) created from Finance tax codes.', 'Done.');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'tax_type_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:tax_types,id'],
            'tax_code' => [$creating ? 'required' : 'prohibited', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'entity_id' => [$creating ? 'nullable' : 'prohibited', 'integer', $this->businessRule()],
            'name' => ['required', 'string', 'max:160'],
            'treatment' => ['required', 'in:' . implode(',', TaxRuleVersion::TREATMENTS)],
            'direction' => ['required', 'in:' . implode(',', TaxRuleVersion::DIRECTIONS)],
            'method' => ['required', 'in:percentage,fixed,none'],
            'rate' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'rounding' => ['required', 'in:' . implode(',', TaxRuleVersion::ROUNDING)],
            'precision' => ['required', 'integer', 'between:0,4'],
            'control_account_id' => ['nullable', 'integer', 'exists:fin_accounts,id'],
            'return_field' => ['nullable', 'string', 'max:20'],
            'reporting_class' => ['nullable', 'string', 'max:40'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'legal_reference' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
