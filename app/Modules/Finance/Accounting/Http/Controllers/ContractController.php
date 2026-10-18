<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinContract;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\ContractService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ContractController extends Controller
{
    public function __construct(private ContractService $contracts)
    {
    }

    public function index(): Response
    {
        $claims = FinInvoice::whereNotNull('contract_id')->get()->groupBy('contract_id');

        return Inertia::render('Modules/Finance/Accounting/Contracts', [
            'contracts' => FinContract::with('party')->orderByDesc('id')->get()
                ->map(fn (FinContract $c) => [
                    'id' => $c->id, 'name' => $c->name, 'party' => $c->party->name, 'party_id' => $c->party_id,
                    'income_account_id' => $c->income_account_id,
                    'contract_value' => (float) $c->contract_value, 'retention_percent' => (float) $c->retention_percent,
                    'billed_net' => (float) $c->billed_net, 'remaining' => $c->remaining(),
                    'retention_held' => (float) $c->retention_held, 'status' => $c->status, 'description' => $c->description,
                    'claims' => ($claims[$c->id] ?? collect())->map(fn ($i) => [
                        'number' => $i->number, 'date' => $i->invoice_date?->toDateString(),
                        'total' => (float) $i->total, 'outstanding' => $i->outstanding(), 'status' => $i->status,
                    ])->values(),
                ]),
            'customers' => FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'incomeAccounts' => FinAccount::postable()->where('type', 'income')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->contracts->save($this->rules($request));

        return back()->with('success', 'Contract saved.');
    }

    public function update(Request $request, FinContract $contract): RedirectResponse
    {
        $this->contracts->save($this->rules($request), $contract);

        return back()->with('success', 'Contract updated.');
    }

    public function claim(Request $request, FinContract $contract): RedirectResponse
    {
        $data = $request->validate([
            'net'         => 'required|numeric|min:0.01',
            'tax_rate'    => 'nullable|numeric|min:0|max:100',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $this->contracts->progressClaim($contract, (float) $data['net'], (float) ($data['tax_rate'] ?? 15), $data['description'] ?? 'Progress claim');
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['contract' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Progress claim raised.');
    }

    public function release(Request $request, FinContract $contract): RedirectResponse
    {
        $data = $request->validate(['amount' => 'nullable|numeric|min:0.01']);

        try {
            $this->contracts->releaseRetention($contract, isset($data['amount']) ? (float) $data['amount'] : null);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['contract' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Retention released.');
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'party_id'          => 'required|exists:fin_parties,id',
            'income_account_id' => 'required|exists:fin_accounts,id',
            'name'              => 'required|string|max:255',
            'contract_value'    => 'required|numeric|min:0',
            'retention_percent' => 'nullable|numeric|min:0|max:100',
            'status'            => 'nullable|in:active,completed',
            'description'       => 'nullable|string|max:500',
        ]);
    }
}
