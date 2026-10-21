<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinDeliveryNote;
use App\Modules\Finance\Accounting\Support\ChargeTo;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Services\SalesOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SalesOrderController extends Controller
{
    public function __construct(private SalesOrderService $orders)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/SalesOrders', [
            'orders' => FinSalesOrder::with('party', 'lines', 'deliveryNotes')->orderByDesc('id')->limit(200)->get()
                ->map(fn (FinSalesOrder $o) => [
                    'id' => $o->id, 'number' => $o->number, 'party' => $o->party->name, 'party_id' => $o->party_id,
                    'date' => $o->order_date?->toDateString(), 'total' => (float) $o->total, 'status' => $o->status,
                    'customer_ref' => $o->customer_ref, 'description' => $o->description, 'invoice_id' => $o->invoice_id,
                    'deliver_on' => $o->deliver_on?->toDateString(), 'delivery_address' => $o->delivery_address,
                    'charge_to' => ChargeTo::choiceOf($o->dimensions), 'source_type' => $o->source_type,
                    'deliveries' => $o->deliveryNotes->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'date' => $d->delivery_date?->toDateString(), 'invoice_id' => $d->invoice_id]),
                    'lines' => $o->lines->map(fn ($l) => [
                        'id' => $l->id, 'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description,
                        'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
                        'qty_delivered' => (float) $l->qty_delivered, 'outstanding' => $l->outstandingQty(),
                        'unit' => $l->unit, 'grade' => $l->grade, 'price_basis' => $l->price_basis,
                        'qty_accepted' => (float) $l->qty_accepted, 'qty_rejected' => (float) $l->qty_rejected, 'qty_invoiced' => (float) $l->qty_invoiced,
                    ]),
                ]),
            'customers' => FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name]),
            'incomeAccounts' => FinAccount::postable()->where('type', 'income')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
            'items' => FinItem::active()->orderBy('name')->get()
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'description' => $i->description,
                    'price' => (float) $i->sales_price, 'tax_rate' => (float) $i->tax_rate, 'account_id' => $i->sales_account_id]),
            'chargeTo' => ChargeTo::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->orders->save($this->rules($request));

        return back()->with('success', 'Sales order saved.');
    }

    public function update(Request $request, FinSalesOrder $order): RedirectResponse
    {
        if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
            throw ValidationException::withMessages(['order' => ["A {$order->status} order can't be edited."]]);
        }
        $this->orders->save($this->rules($request), $order);

        return back()->with('success', 'Sales order updated.');
    }

    public function deliver(Request $request, FinSalesOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'notes'                 => 'nullable|string|max:300',
            'deliveries'            => 'required|array|min:1',
            'deliveries.*.order_line_id' => 'required|integer',
            'deliveries.*.quantity' => 'required|numeric|min:0',
            // What the customer accepted and turned away, when they have said.
            'deliveries.*.accepted' => 'nullable|numeric|min:0',
            'deliveries.*.rejected' => 'nullable|numeric|min:0',
        ]);

        $sent = collect($data['deliveries'])->filter(fn ($d) => (float) $d['quantity'] > 0);
        $map = $sent->mapWithKeys(fn ($d) => [(int) $d['order_line_id'] => (float) $d['quantity']])->all();
        $outcomes = $sent->filter(fn ($d) => isset($d['accepted']) || isset($d['rejected']))
            ->mapWithKeys(fn ($d) => [(int) $d['order_line_id'] => ['accepted' => (float) ($d['accepted'] ?? 0), 'rejected' => (float) ($d['rejected'] ?? 0)]])->all();

        try {
            $this->orders->deliver($order, $map, $data['notes'] ?? null, $outcomes);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['order' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Delivery recorded.');
    }

    public function invoice(FinSalesOrder $order): RedirectResponse
    {
        try {
            $invoice = $this->orders->invoice($order);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['order' => [$e->getMessage()]]);
        }

        return back()->with('success', "Invoice {$invoice->number} raised from {$order->number}.");
    }

    /** Invoice what one delivery's customer accepted. */
    public function invoiceDelivery(FinDeliveryNote $deliveryNote): RedirectResponse
    {
        try {
            $invoice = $this->orders->invoiceDelivery($deliveryNote);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['order' => [$e->getMessage()]]);
        }

        return back()->with('success', $invoice ? "Invoice {$invoice->number} raised for {$deliveryNote->number}." : 'Nothing on that delivery was accepted; there is nothing to invoice.');
    }

    public function cancel(FinSalesOrder $order): RedirectResponse
    {
        try {
            $this->orders->cancel($order);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['order' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Order cancelled.');
    }

    private function rules(Request $request): array
    {
        $data = $request->validate([
            'party_id'           => 'required|exists:fin_parties,id',
            'order_date'         => 'nullable|date',
            'customer_ref'       => 'nullable|string|max:120',
            'description'        => 'nullable|string|max:500',
            'lines'              => 'required|array|min:1',
            'lines.*.account_id' => 'required|exists:fin_accounts,id',
            'lines.*.item_id'    => 'nullable|integer|exists:fin_items,id',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity'   => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'required|numeric',
            'lines.*.tax_rate'   => 'nullable|numeric|min:0|max:100',
            'lines.*.unit'       => 'nullable|string|max:20',
            'lines.*.grade'      => 'nullable|string|max:40',
            'lines.*.price_basis' => 'nullable|string|max:255',
            'deliver_on'         => 'nullable|date',
            'delivery_address'   => 'nullable|string|max:1000',
            'charge_to'          => 'nullable|string|max:80',
        ]);
        if ($request->has('charge_to')) {
            try {
                $data['dimensions'] = ChargeTo::dimensions($request->input('charge_to'));
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['charge_to' => [$e->getMessage()]]);
            }
        }

        return $data;
    }
}
