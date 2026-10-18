<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxPeriod;
use App\Modules\Finance\Tax\Models\TaxTransaction;
use App\Modules\Finance\Tax\Services\VatRegisterService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §6 VAT register: search, trace to source, resolve exceptions, export. */
class TaxTransactionController extends TaxController
{
    public function __construct(private readonly VatRegisterService $register)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $filters = $this->filters($request);
        $business = $this->businessFilter();

        $rows = $this->query($filters)->forBusiness($business)->with('period:id,uuid,label,status')->orderByDesc('document_date')->orderBy('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('Modules/Finance/Tax/Transactions', [
            'transactions' => $rows->through(fn (TaxTransaction $t) => $this->row($t)),
            'filters' => $filters,
            'periods' => TaxPeriod::query()->with('taxType')->forBusiness($business)->whereHas('taxType', fn ($q) => $q->where('code', 'VAT'))
                ->orderByDesc('end_date')->limit(36)->get()->map(fn ($p) => ['uuid' => $p->uuid, 'label' => $p->label . ' · ' . $this->entityName($p->entity_id)]),
            'codes' => TaxTransaction::query()->current()->whereNotNull('tax_code')->distinct()->orderBy('tax_code')->pluck('tax_code'),
            'can' => ['prepare' => request()->user()->can(TaxPermissions::PREPARE)],
        ] + $this->businessProps($business));
    }

    public function resolve(Request $request, TaxTransaction $transaction): RedirectResponse
    {
        $this->allow(TaxPermissions::PREPARE, 'vat_register.resolve');
        $data = $request->validate([
            'action' => ['required', 'in:classify,accept'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'tax_code' => ['required_if:action,classify', 'nullable', 'string', 'max:30'],
        ]);

        return $this->attempt(fn () => $this->register->resolve($transaction, $data['action'], $data['reason'], $data['tax_code'] ?? null),
            $data['action'] === 'classify' ? 'Line classified.' : 'Exception accepted with your reason.');
    }

    public function export(Request $request): StreamedResponse
    {
        $this->allow(TaxPermissions::VIEW);
        $filters = $this->filters($request);
        $period = isset($filters['period']) ? TaxPeriod::where('uuid', $filters['period'])->first() : null;

        $rows = (function () use ($filters) {
            foreach ($this->query($filters)->forBusiness($this->businessFilter())->with('period:id,label')->orderBy('document_date')->orderBy('id')->cursor() as $t) {
                yield [$t->period?->label, $t->document_date->toDateString(), $t->document_type, $t->document_number, $t->direction,
                    $t->party_name, $t->party_vat_number, $t->tax_code, $t->tax_code_source, $t->treatment, $t->return_field,
                    (string) $t->taxable_amount, (string) $t->tax_amount, $t->calculated_tax === null ? null : (string) $t->calculated_tax,
                    (string) $t->difference, $t->currency, (string) $t->fx_rate, $t->source_key, $t->version,
                    collect($t->exceptions ?? [])->map(fn ($e) => $e['code'] . (isset($e['resolved_at']) ? ' (accepted)' : ''))->implode('; ')];
            }
        })();

        return $this->csv('vat-register-' . now()->format('Ymd-His') . '.csv',
            ['Period', 'Date', 'Type', 'Document', 'Direction', 'Party', 'Party VAT no.', 'Code', 'Code source', 'Treatment', 'VAT201 field',
                'Taxable (ZAR)', 'VAT (ZAR)', 'Calculated VAT', 'Difference', 'Currency', 'FX rate', 'Source', 'Version', 'Exceptions'],
            $rows,
            ['Report' => 'VAT transaction register', 'Business' => $period ? $this->entityName($period->entity_id) : 'All', 'Period' => $period?->label ?? 'All',
                'Generated' => now()->toDateTimeString(), 'Report version' => '1']);
    }

    private function filters(Request $request): array
    {
        return array_filter($request->validate([
            'period' => ['nullable', 'uuid'], 'direction' => ['nullable', 'in:output,input'], 'code' => ['nullable', 'string', 'max:30'],
            'exceptions' => ['nullable', 'in:1,0'], 'search' => ['nullable', 'string', 'max:100'], 'history' => ['nullable', 'in:1,0'],
        ]), fn ($v) => $v !== null && $v !== '');
    }

    private function query(array $f): Builder
    {
        return TaxTransaction::query()
            ->when(($f['history'] ?? '0') !== '1', fn ($q) => $q->current())
            ->when($f['period'] ?? null, fn ($q, $u) => $q->whereHas('period', fn ($p) => $p->where('uuid', $u)))
            ->when($f['direction'] ?? null, fn ($q, $d) => $q->where('direction', $d))
            ->when($f['code'] ?? null, fn ($q, $c) => $q->where('tax_code', $c))
            ->when(($f['exceptions'] ?? null) === '1', fn ($q) => $q->where('has_exception', true))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('document_number', 'ilike', "%{$s}%")->orWhere('party_name', 'ilike', "%{$s}%")));
    }

    private function row(TaxTransaction $t): array
    {
        return [
            'uuid' => $t->uuid, 'period' => $t->period?->label, 'period_status' => $t->period?->status,
            'document_date' => $t->document_date->toDateString(), 'document_type' => $t->document_type, 'document_number' => $t->document_number,
            'direction' => $t->direction, 'party' => $t->party_name, 'party_vat_number' => $t->party_vat_number,
            'tax_code' => $t->tax_code, 'tax_code_source' => $t->tax_code_source, 'treatment' => $t->treatment, 'return_field' => $t->return_field,
            'taxable' => (string) $t->taxable_amount, 'tax' => (string) $t->tax_amount,
            'calculated' => $t->calculated_tax === null ? null : (string) $t->calculated_tax, 'difference' => (string) $t->difference,
            'currency' => $t->currency, 'fx_rate' => (string) $t->fx_rate, 'fx_source' => $t->fx_source,
            'status' => $t->status, 'version' => $t->version, 'is_current' => $t->is_current,
            'exceptions' => $t->exceptions ?? [], 'has_exception' => $t->has_exception,
            'source' => $this->sourceLink($t), 'source_key' => $t->source_key, 'calculation' => $t->calculation,
        ];
    }

    /** Drill-down to the Finance document behind the line (§3.2). */
    private function sourceLink(TaxTransaction $t): ?array
    {
        return match ($t->source_table) {
            'fin_invoice_lines' => ['label' => $t->document_number, 'url' => url('/finance/accounting/' . ($t->direction === 'output' ? 'receivables' : 'payables') . '?search=' . urlencode((string) $t->document_number))],
            'fin_expenses' => ['label' => $t->document_number, 'url' => url('/finance/accounting/expenses')],
            default => null,
        };
    }
}
