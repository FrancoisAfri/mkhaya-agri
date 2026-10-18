<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\AgeingService;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use App\Modules\Operations\DocumentManagement\Concerns\PicksTenantSubjects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinanceArApController extends Controller
{
    /*
     * The sales rep is a member of staff, so the field offers staff.
     *
     * It was free text, which meant "Francois Charles Keou ngamen" in one record and "F. Keou" in
     * the next, and nothing that groups a book of customers by who looks after them. The trait is
     * the module-wide people picker: employed, active, and scoped to this tenant — the last part
     * mattering because a platform administrator's User scope steps aside, and without it this
     * dropdown would list every tenant's staff.
     */
    use PicksTenantSubjects;

    public function __construct(
        private ArApService $arap,
        private AgeingService $ageing,
        private PartySyncService $sync,
    ) {
    }

    // ── Customers & Suppliers (separate) ────────────────────────────────────

    public function customers(): Response
    {
        if ($this->sync->crmActive()) {
            $this->sync->syncCustomersFromCrm();
        }

        return $this->partiesPage('customer', $this->sync->crmActive(), 'CRM Accounts');
    }

    public function suppliers(): Response
    {
        if ($this->sync->supplierMgmtActive()) {
            $this->sync->syncSuppliersFromModule();
        }

        return $this->partiesPage('supplier', $this->sync->supplierMgmtActive(), 'Supplier Management');
    }

    private function partiesPage(string $kind, bool $synced, string $sourceName): Response
    {
        $parties = FinParty::query()
            ->when($kind === 'customer', fn ($q) => $q->customers(), fn ($q) => $q->suppliers())
            ->orderBy('name')->get()
            ->map(fn (FinParty $p) => [
                'id' => $p->id, 'kind' => $p->kind, 'category' => $p->category, 'price_list_id' => $p->price_list_id, 'source' => $p->source, 'code' => $p->code, 'name' => $p->name,
                'contact_name' => $p->contact_name,
                'email' => $p->email, 'website' => $p->website, 'phone' => $p->phone, 'fax' => $p->fax,
                'vat_number' => $p->vat_number, 'tax_exempt' => $p->tax_exempt, 'registration_number' => $p->registration_number,
                // The supplier account this customer opened for us on their own purchasing
                // system. Held here so it is typed once and carried onto every document.
                'vendor_number' => $p->vendor_number,
                'address' => $p->address, 'city' => $p->city, 'country' => $p->country, 'postal_address' => $p->postal_address,
                'bank_name' => $p->bank_name, 'bank_account' => $p->bank_account,
                'credit_terms_days' => $p->credit_terms_days, 'sales_rep' => $p->sales_rep,
                'credit_limit' => $p->credit_limit !== null ? (float) $p->credit_limit : null,
                'default_discount_percent' => $p->default_discount_percent !== null ? (float) $p->default_discount_percent : null,
                'on_hold' => $p->on_hold, 'is_active' => $p->is_active, 'synced' => $p->isSynced(),
                // Which fields the source system rewrites, so the form can show them as its own
                // rather than letting somebody type into a box that reverts at the next sync.
                'locked' => $p->isSynced() ? PartySyncService::sourceOwnedColumns($p->source) : [],
            ]);

        return Inertia::render('Modules/Finance/Accounting/Parties', [
            'kind'        => $kind,
            'parties'     => $parties,
            'synced'      => $synced,          // this kind is owned by another module
            'source_name' => $sourceName,      // where it comes from
            'categories'  => FinParty::query()
                ->when($kind === 'customer', fn ($q) => $q->customers(), fn ($q) => $q->suppliers())
                ->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            // Free text is still accepted on save, so a rep who has left, or an agent who was
            // never on staff, is not erased by a list that cannot contain them.
            'people'      => $this->tenantPeople(),
            'priceLists'  => $kind === 'customer'
                ? \App\Modules\Finance\Accounting\Models\FinPriceList::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    /** Merge a duplicate party into a survivor: move its documents over, delete the duplicate. */
    public function mergeParties(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'survivor_id'  => 'required|integer|exists:fin_parties,id',
            'duplicate_id' => 'required|integer|different:survivor_id|exists:fin_parties,id',
        ]);

        $survivor  = FinParty::findOrFail($data['survivor_id']);
        $duplicate = FinParty::findOrFail($data['duplicate_id']);

        if ($duplicate->isSynced()) {
            throw ValidationException::withMessages(['merge' => ['The duplicate is managed by another module and would re-sync — merge the manual record into it instead.']]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($survivor, $duplicate) {
            foreach (['fin_invoices', 'fin_payments', 'fin_collection_activities', 'fin_recurring_invoices', 'fin_payment_batch_lines', 'fin_quotes'] as $table) {
                \Illuminate\Support\Facades\DB::table($table)->where('party_id', $duplicate->id)->update(['party_id' => $survivor->id]);
            }
            $duplicate->delete();
        });

        return back()->with('success', "Merged “{$duplicate->name}” into “{$survivor->name}”.");
    }

    public function syncParties(Request $request): RedirectResponse
    {
        $kind = $request->validate(['kind' => 'required|in:customer,supplier'])['kind'];

        $n = $kind === 'customer' ? $this->sync->syncCustomersFromCrm() : $this->sync->syncSuppliersFromModule();

        return back()->with('success', "Synced {$n} record(s).");
    }

    public function storeParty(Request $request): RedirectResponse
    {
        FinParty::create($this->partyRules($request) + ['source' => 'manual']);

        return back()->with('success', 'Saved.');
    }

    public function updateParty(Request $request, FinParty $party): RedirectResponse
    {
        $data = $this->partyRules($request, $party->id);

        /*
         * A synced record is editable — just not in the columns its source owns.
         *
         * Refusing the whole record meant a CRM customer could never be given a VAT number, credit
         * terms, a credit limit, a sales rep or a postal address, because none of those exist in
         * CRM to sync down. Every customer on the page comes from CRM, so in practice none of them
         * could be completed at all — while the invoice that prints those fields expects them.
         *
         * What the source does own is dropped rather than refused: it is rewritten on the next sync
         * either way, and a change that saves and then quietly reverts is worse than one that was
         * never offered.
         */
        if ($party->isSynced()) {
            $data = collect($data)->except(PartySyncService::sourceOwnedColumns($party->source))->all();
        }

        $party->update($data);

        return back()->with('success', 'Saved.');
    }

    // ── Receivables / Payables (shared ledger view) ─────────────────────────

    public function receivables(Request $request): Response
    {
        return $this->ledgerPage('receivable', $request);
    }

    public function payables(Request $request): Response
    {
        return $this->ledgerPage('payable', $request);
    }

    private function ledgerPage(string $direction, Request $request): Response
    {
        $asOf = $request->date('as_of')?->toDateString() ?? now()->toDateString();
        $lineType = $direction === 'receivable' ? 'income' : 'expense';

        $invoices = FinInvoice::with(['party', 'lines'])->where('direction', $direction)
            ->orderByDesc('invoice_date')->orderByDesc('id')->limit(200)->get()
            ->map(fn (FinInvoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'party' => $i->party->name, 'party_id' => $i->party_id,
                'party_ref' => $i->party_ref, 'vendor_number' => $i->vendor_number, 'date' => $i->invoice_date?->toDateString(), 'due' => $i->due_date?->toDateString(),
                'total' => (float) $i->total, 'paid' => (float) $i->amount_paid, 'outstanding' => $i->outstanding(),
                'status' => $i->status, 'description' => $i->description, 'doc_type' => $i->doc_type,
                'terms' => $i->terms, 'template_id' => $i->template_id,
                'editable' => $i->isAmendable(),
                /*
                 * Lines ride along only for the ones that can still be changed. An edit form has
                 * to open on what the invoice actually says — retyping it is how an amendment
                 * ends up differing from the original in a way nobody intended — but sending the
                 * lines of two hundred settled invoices to render a list is payload nobody reads.
                 */
                'lines' => $i->isAmendable()
                    ? $i->lines->map(fn ($l) => [
                        'account_id' => $l->account_id, 'item_id' => $l->item_id,
                        'description' => $l->description, 'note' => $l->note,
                        'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price,
                        'discount_rate' => (float) $l->discount_rate, 'tax_rate' => (float) $l->tax_rate,
                    ])->values()
                    : [],
            ]);

        return Inertia::render('Modules/Finance/Accounting/ArApLedger', [
            'direction' => $direction,
            'as_of'     => $asOf,
            'invoices'  => $invoices,
            'parties'   => FinParty::query()
                ->where('is_active', true)
                ->when($direction === 'receivable', fn ($q) => $q->customers(), fn ($q) => $q->suppliers())
                ->orderBy('name')->get(['id', 'name', 'code', 'on_hold', 'credit_terms_days', 'vendor_number'])
                // The vendor number rides along so choosing a customer fills it in: it is the same
                // number on every invoice to them, and retyping it is how it ends up wrong on one.
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code,
                    'on_hold' => $p->on_hold, 'vendor_number' => $p->vendor_number]),
            'lineAccounts' => FinAccount::postable()->where('type', $lineType)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
            'banks' => FinAccount::postable()->where('type', 'asset')->orderBy('code')->get(['code', 'name'])
                ->map(fn ($a) => ['code' => $a->code, 'label' => "{$a->code} · {$a->name}"]),
            'items' => \App\Modules\Finance\Accounting\Models\FinItem::active()->orderBy('name')->get()
                ->map(fn ($i) => [
                    'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'description' => $i->description, 'unit' => $i->unit,
                    'price' => $direction === 'receivable' ? (float) $i->sales_price : (float) $i->cost_price,
                    'tax_rate' => (float) $i->tax_rate,
                    'account_id' => $direction === 'receivable' ? $i->sales_account_id : $i->purchase_account_id,
                ]),
            'ageing' => $this->ageing->ageing($direction, $asOf),
            'can_writeoff' => (bool) $request->user()?->can('finance.writeoff'),
            'templates' => \App\Modules\Finance\Accounting\Models\FinDocumentTemplate::query()
                ->whereIn('doc_type', ['invoice', 'both'])->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'terms', 'is_default']),
            'defaultTerms' => \App\Modules\Finance\Accounting\Models\FinSetting::current()->default_terms,
        ]);
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'party_id'            => 'required|exists:fin_parties,id',
            'direction'           => 'required|in:receivable,payable',
            'invoice_date'        => 'nullable|date',
            'due_date'            => 'nullable|date',
            'party_ref'           => 'nullable|string|max:80',
            'vendor_number'       => 'nullable|string|max:60',
            'description'         => 'nullable|string|max:500',
            'terms'               => 'nullable|string|max:8000',
            'template_id'         => 'nullable|integer|exists:fin_document_templates,id',
            'lines'               => 'required|array|min:1',
            'lines.*.account_id'  => 'required|exists:fin_accounts,id',
            'lines.*.item_id'     => 'nullable|integer|exists:fin_items,id',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.note'        => 'nullable|string|max:1000',
            'lines.*.quantity'    => 'nullable|numeric|min:0',
            'lines.*.unit_price'  => 'required|numeric',
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.tax_rate'    => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $this->arap->createInvoice($data);
        } catch (PostingException|\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['invoice' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Posted.');
    }

    /**
     * Amend an invoice that has not been settled.
     *
     * The service decides whether it may be amended at all — money allocated against it, stock
     * already relieved, a voided document — and says why in terms the person can act on. The
     * controller only carries the answer back.
     */
    public function updateInvoice(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'party_id'            => 'nullable|exists:fin_parties,id',
            'invoice_date'        => 'nullable|date',
            'due_date'            => 'nullable|date',
            'party_ref'           => 'nullable|string|max:80',
            'vendor_number'       => 'nullable|string|max:60',
            'description'         => 'nullable|string|max:500',
            'terms'               => 'nullable|string|max:8000',
            'template_id'         => 'nullable|integer|exists:fin_document_templates,id',
            'lines'               => 'required|array|min:1',
            'lines.*.account_id'  => 'required|exists:fin_accounts,id',
            'lines.*.item_id'     => 'nullable|integer|exists:fin_items,id',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.note'        => 'nullable|string|max:1000',
            'lines.*.quantity'    => 'nullable|numeric|min:0',
            'lines.*.unit_price'  => 'required|numeric',
            'lines.*.discount_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.tax_rate'    => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $this->arap->updateInvoice($invoice, $data);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['invoice' => [$e->getMessage()]]);
        }

        return back()->with('success', "Invoice {$invoice->number} amended.");
    }

    public function storePayment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'party_id'                => 'required|exists:fin_parties,id',
            'direction'               => 'required|in:receipt,payment',
            'payment_date'            => 'nullable|date',
            'bank_account_id'         => 'nullable|string|max:40',
            'reference'               => 'nullable|string|max:120',
            'allocations'             => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|integer',
            'allocations.*.amount'    => 'required|numeric|min:0.01',
        ]);

        try {
            $this->arap->recordPayment($data);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['payment' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Recorded.');
    }

    public function voidInvoice(FinInvoice $invoice): RedirectResponse
    {
        try {
            $this->arap->voidInvoice($invoice);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['invoice' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Voided.');
    }

    public function storeCreditNote(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:300',
        ]);

        try {
            $this->arap->createCreditNote($invoice, (float) $data['amount'], $data['reason']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['creditnote' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Credit note posted.');
    }

    public function storeDebitNote(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:300',
        ]);

        try {
            $this->arap->createDebitNote($invoice, (float) $data['amount'], $data['reason']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['creditnote' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Debit note posted.');
    }

    public function writeOffInvoice(Request $request, FinInvoice $invoice): RedirectResponse
    {
        if (! $request->user()?->can('finance.writeoff')) {
            throw ValidationException::withMessages(['writeoff' => ['You are not permitted to write off bad debts.']]);
        }
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:300',
        ]);

        try {
            $this->arap->writeOff($invoice, (float) $data['amount'], $data['reason']);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['writeoff' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Invoice written off to bad debts.');
    }

    public function statement(Request $request, FinParty $party): Response
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfYear()->toDateString();
        $to   = $request->date('to')?->toDateString() ?? now()->toDateString();

        return Inertia::render('Modules/Finance/Accounting/Statement', [
            'statement' => $this->ageing->statement($party->id, $from, $to),
        ]);
    }

    private function partyRules(Request $request, ?int $ignore = null): array
    {
        return $request->validate([
            'kind'                => 'required|in:customer,supplier,both',
            'category'            => 'nullable|string|max:80',
            'price_list_id'       => 'nullable|integer|exists:fin_price_lists,id',
            'code'                => 'nullable|string|max:40', // auto-generated when blank
            'name'                => 'required|string|max:255',
            'contact_name'        => 'nullable|string|max:255',
            'email'               => 'nullable|email|max:255',
            'website'             => 'nullable|string|max:255',
            'phone'               => 'nullable|string|max:40',
            'fax'                 => 'nullable|string|max:40',
            'address'             => 'nullable|string|max:1000',
            'city'                => 'nullable|string|max:120',
            'country'             => 'nullable|string|max:120',
            'postal_address'      => 'nullable|string|max:1000',
            'vat_number'          => 'nullable|string|max:40',
            'tax_exempt'          => 'boolean',
            'registration_number' => 'nullable|string|max:40',
            'vendor_number'       => 'nullable|string|max:60',
            'bank_name'           => 'nullable|string|max:80',
            'bank_account'        => 'nullable|string|max:40',
            'credit_terms_days'   => 'nullable|integer|min:0|max:365',
            'credit_limit'        => 'nullable|numeric|min:0',
            'sales_rep'           => 'nullable|string|max:120',
            'default_discount_percent' => 'nullable|numeric|min:0|max:100',
            'on_hold'             => 'boolean',
            'is_active'           => 'boolean',
        ]);
    }
}
