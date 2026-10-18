<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinTaxCode;
use App\Modules\Finance\Accounting\Services\TaxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function __construct(private TaxService $tax)
    {
    }

    public function index(Request $request): Response
    {
        $from = $request->date('from') ?? Carbon::now()->startOfMonth();
        $to   = $request->date('to') ?? Carbon::now()->endOfMonth();

        // Scope the VAT computation to the current tenant — the GL tables the engine reads
        // over raw DB queries have no row-level security, so an unscoped call would aggregate
        // VAT across all companies.
        $companyId = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        return Inertia::render('Modules/Finance/Accounting/Tax', [
            'vat201' => $this->tax->vat201($from->toDateString(), $to->toDateString(), $companyId),
            'range'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'codes'  => FinTaxCode::orderBy('type')->orderBy('code')->get()->map(fn (FinTaxCode $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'rate' => (float) $c->rate,
                'type' => $c->type, 'jurisdiction' => $c->jurisdiction, 'account_id' => $c->account_id, 'is_active' => $c->is_active,
            ]),
            'types'    => FinTaxCode::TYPES,
            'accounts' => FinAccount::postable()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
        ]);
    }

    public function storeCode(Request $request): RedirectResponse
    {
        FinTaxCode::create($this->rules($request));

        return back()->with('success', 'Tax code saved.');
    }

    public function updateCode(Request $request, FinTaxCode $taxCode): RedirectResponse
    {
        $taxCode->update($this->rules($request));

        return back()->with('success', 'Tax code updated.');
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'code'         => 'required|string|max:30',
            'name'         => 'required|string|max:120',
            'rate'         => 'required|numeric|min:0|max:100',
            'type'         => 'required|in:output,input,none,wht',
            'jurisdiction' => 'nullable|string|max:40',
            'account_id'   => 'nullable|integer|exists:fin_accounts,id',
            'is_active'    => 'boolean',
        ]);
    }
}
