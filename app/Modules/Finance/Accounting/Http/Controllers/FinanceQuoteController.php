<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceQuoteController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    public function __construct(
        private QuoteService $quotes,
        private \App\Modules\Finance\Accounting\Services\PartySyncService $sync,
    ) {
    }

    public function index(): Response
    {
        $crmActive = $this->sync->crmActive();
        if ($crmActive) {
            $this->sync->syncCustomersFromCrm(); // customers list mirrors CRM accounts
        }

        return Inertia::render('Modules/Finance/Accounting/Quotes', [
            'quotes' => FinQuote::with('party')->orderByDesc('quote_date')->orderByDesc('id')->limit(200)->get()
                ->map(fn (FinQuote $q) => [
                    'id' => $q->id, 'number' => $q->number, 'party' => $q->party->name, 'party_id' => $q->party_id,
                    'date' => $q->quote_date?->toDateString(), 'valid_until' => $q->valid_until?->toDateString(),
                    'subtotal' => (float) $q->subtotal, 'discount_percent' => (float) $q->discount_percent,
                    'discount_amount' => (float) $q->discount_amount, 'tax_total' => (float) $q->tax_total,
                    'total' => (float) $q->total, 'status' => $q->status, 'description' => $q->description,
                    'customer_ref' => $q->customer_ref, 'vendor_number' => $q->vendor_number, 'sales_rep' => $q->sales_rep,
                    'delivery_address' => $q->delivery_address, 'postal_address' => $q->postal_address,
                    'invoice_id' => $q->invoice_id, 'sales_order_id' => $q->sales_order_id, 'crm_deal_id' => $q->crm_deal_id,
                    'terms' => $q->terms, 'template_id' => $q->template_id,
                    'charge_to' => \App\Modules\Finance\Accounting\Support\ChargeTo::choiceOf($q->dimensions),
                    'editable' => $q->isEditable(),
                ]),
            'quoteLines' => FinQuote::with('lines')->orderByDesc('id')->limit(200)->get()
                ->mapWithKeys(fn ($q) => [$q->id => $q->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description, 'note' => $l->note,
                    'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
                    'unit' => $l->unit, 'grade' => $l->grade, 'price_basis' => $l->price_basis,
                ])]),
            'customers' => FinParty::customers()->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'price_list_id' => $p->price_list_id, 'tax_exempt' => $p->tax_exempt,
                    'address' => $p->address, 'city' => $p->city, 'country' => $p->country, 'postal_address' => $p->postal_address,
                    // Fills the quote in when the customer is chosen; the same number every time.
                    'vendor_number' => $p->vendor_number,
                ]),
            'priceLists' => \App\Modules\Finance\Accounting\Models\FinPriceList::with('items')->where('is_active', true)->get()
                ->mapWithKeys(fn ($pl) => [$pl->id => $pl->items->mapWithKeys(fn ($i) => [$i->item_id => (float) $i->price])]),
            // Names only, which is the shape this screen's picker expects. Dropping anyone
            // without a personnel record meant a freshly created account could not be chosen.
            'employees' => array_values(array_unique(array_column($this->tenantPeople(), 'name'))),
            'incomeAccounts' => FinAccount::postable()->where('type', 'income')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
            'items' => FinItem::active()->orderBy('name')->get()
                ->map(fn (FinItem $i) => [
                    'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'description' => $i->description,
                    'unit' => $i->unit, 'price' => (float) $i->sales_price, 'tax_rate' => (float) $i->tax_rate,
                    'account_id' => $i->sales_account_id,
                ]),
            'crm_active' => $crmActive,
            'deals' => $crmActive ? $this->deals() : [],
            'templates' => \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::query()
                ->whereIn('doc_type', ['quote', 'both'])->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'terms', 'is_default']),
            'defaultTerms' => \App\Modules\Finance\Accounting\Models\FinSetting::current()->default_terms,
            // Where the income can be charged (a farm, …), offered by the modules that own them.
            'chargeTo' => \App\Modules\Finance\Accounting\Support\ChargeTo::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateQuote($request);

        try {
            $this->quotes->createQuote($data);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quote' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Quote saved.');
    }

    public function update(Request $request, FinQuote $quote): RedirectResponse
    {
        $data = $this->validateQuote($request);

        try {
            $this->quotes->updateQuote($quote, $data);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quote' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Quote updated.');
    }

    public function changeStatus(Request $request, FinQuote $quote): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:draft,sent,accepted,declined,expired']);

        try {
            $this->quotes->changeStatus($quote, $data['status']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quote' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Quote updated.');
    }

    public function convert(FinQuote $quote): RedirectResponse
    {
        try {
            $this->quotes->convertToInvoice($quote);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quote' => [$e->getMessage()]]);
        }

        return back()->with('success', "Quote {$quote->number} converted to an invoice.");
    }

    public function toOrder(FinQuote $quote, \App\Modules\Finance\Accounting\Services\SalesOrderService $orders): RedirectResponse
    {
        try {
            $order = $orders->createFromQuote($quote);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quote' => [$e->getMessage()]]);
        }

        return back()->with('success', "Sales order {$order->number} created from {$quote->number}.");
    }

    private function validateQuote(Request $request): array
    {
        return $request->validate([
            'party_id'            => 'required|exists:fin_parties,id',
            'quote_date'          => 'nullable|date',
            'valid_until'         => 'nullable|date',
            'description'         => 'nullable|string|max:500',
            'customer_ref'        => 'nullable|string|max:120',
            'vendor_number'       => 'nullable|string|max:60',
            'sales_rep'           => 'nullable|string|max:120',
            'discount_percent'    => 'nullable|numeric|min:0|max:100',
            'delivery_address'    => 'nullable|string|max:1000',
            'postal_address'      => 'nullable|string|max:1000',
            'crm_deal_id'         => 'nullable|integer',
            'terms'               => 'nullable|string|max:8000',
            'template_id'         => 'nullable|integer|exists:fin_document_templates,id',
            'lines'               => 'required|array|min:1',
            'lines.*.account_id'  => 'required|exists:fin_accounts,id',
            'lines.*.item_id'     => 'nullable|integer|exists:fin_items,id',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.note'        => 'nullable|string|max:1000',
            'lines.*.quantity'    => 'nullable|numeric|min:0',
            'lines.*.unit_price'  => 'required|numeric',
            'lines.*.tax_rate'    => 'nullable|numeric|min:0|max:100',
            'lines.*.unit'        => 'nullable|string|max:20',
            'lines.*.grade'       => 'nullable|string|max:40',
            'lines.*.price_basis' => 'nullable|string|max:255',
            'charge_to'           => 'nullable|string|max:80',
        ]) + $this->chargeTo($request);
    }

    /** The chosen "charge to" as the dimensions the quote carries. */
    private function chargeTo(Request $request): array
    {
        if (! $request->has('charge_to')) {
            return [];
        }
        try {
            return ['dimensions' => \App\Modules\Finance\Accounting\Support\ChargeTo::dimensions($request->input('charge_to'))];
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['charge_to' => [$e->getMessage()]]);
        }
    }

    /**
     * Recent CRM deals for optional linking. Each carries the customer party
     * (created on the fly from the deal's account) so the quote form can
     * auto-select the customer when a deal is chosen.
     */
    private function deals(): array
    {
        if (! Schema::hasTable('crm_deals')) {
            return [];
        }

        return DB::table('crm_deals')->whereNull('deleted_at')
            ->orderByDesc('id')->limit(200)
            ->get(['id', 'title', 'amount', 'account_id'])
            ->map(function ($d) {
                $party = $d->account_id ? $this->sync->partyForCrmAccount($d->account_id) : null;

                return [
                    'id' => $d->id,
                    'label' => $d->title . ($d->amount ? ' · ' . number_format((float) $d->amount, 2) : ''),
                    'party_id' => $party?->id,
                ];
            })->all();
    }
}
