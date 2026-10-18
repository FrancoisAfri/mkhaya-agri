<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinRecurringInvoice;
use App\Modules\Finance\Accounting\Services\RecurringInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceRecurringController extends Controller
{
    public function __construct(private RecurringInvoiceService $service)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/Recurring', [
            'schedules' => FinRecurringInvoice::with('party', 'lines')->orderByDesc('id')->get()
                ->map(fn (FinRecurringInvoice $r) => [
                    'id' => $r->id, 'name' => $r->name, 'party' => $r->party->name, 'party_id' => $r->party_id,
                    'frequency' => $r->frequency, 'interval' => $r->interval,
                    'start_date' => $r->start_date?->toDateString(), 'next_run_date' => $r->next_run_date?->toDateString(),
                    'end_date' => $r->end_date?->toDateString(), 'status' => $r->status,
                    'auto_email' => $r->auto_email, 'description' => $r->description, 'generated_count' => $r->generated_count,
                    'total' => round($r->lines->sum(fn ($l) => (float) $l->quantity * (float) $l->unit_price * (1 + (float) $l->tax_rate / 100)), 2),
                    'lines' => $r->lines->map(fn ($l) => [
                        'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description,
                        'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
                    ]),
                ]),
            'customers' => FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'incomeAccounts' => FinAccount::postable()->where('type', 'income')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
            'items' => FinItem::active()->orderBy('name')->get()
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'description' => $i->description,
                    'price' => (float) $i->sales_price, 'tax_rate' => (float) $i->tax_rate, 'account_id' => $i->sales_account_id]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->save($this->rules($request));

        return back()->with('success', 'Recurring schedule saved.');
    }

    public function update(Request $request, FinRecurringInvoice $recurring): RedirectResponse
    {
        $this->service->save($this->rules($request), $recurring);

        return back()->with('success', 'Recurring schedule updated.');
    }

    public function changeStatus(Request $request, FinRecurringInvoice $recurring): RedirectResponse
    {
        $status = $request->validate(['status' => 'required|in:active,paused,ended'])['status'];
        $recurring->update(['status' => $status]);

        return back()->with('success', 'Updated.');
    }

    public function runNow(FinRecurringInvoice $recurring): RedirectResponse
    {
        $invoice = $this->service->generateOne($recurring);

        return back()->with('success', "Invoice {$invoice->number} generated.");
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'party_id'           => 'required|exists:fin_parties,id',
            'name'               => 'nullable|string|max:255',
            'frequency'          => 'required|in:weekly,monthly,quarterly,yearly',
            'interval'           => 'nullable|integer|min:1|max:52',
            'start_date'         => 'required|date',
            'end_date'           => 'nullable|date|after:start_date',
            'auto_email'         => 'boolean',
            'description'        => 'nullable|string|max:500',
            'lines'              => 'required|array|min:1',
            'lines.*.account_id' => 'required|exists:fin_accounts,id',
            'lines.*.item_id'    => 'nullable|integer|exists:fin_items,id',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity'   => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'required|numeric',
            'lines.*.tax_rate'   => 'nullable|numeric|min:0|max:100',
        ]);
    }
}
