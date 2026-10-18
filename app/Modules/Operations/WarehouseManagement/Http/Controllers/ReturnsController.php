<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturn;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturnLine;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Services\CustomerReturns;
use App\Modules\Operations\WarehouseManagement\Services\EvidenceLocker;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-080: customer returns, from authorisation to credit. */
class ReturnsController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $list = CustomerReturn::query()->with(['warehouse:id,code', 'order:id,number', 'invoice:id,number', 'creditNote:id,number'])->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')->paginate(25)->withQueryString();

        return Inertia::render('Modules/Warehouse/Returns/Index', [
            'returns' => $list->through(fn (CustomerReturn $r) => [
                'id' => $r->uuid, 'number' => $r->number, 'status' => $r->status, 'warehouse' => $r->warehouse?->code,
                'against' => $r->order?->number ?? $r->invoice?->number ?? $r->reference, 'customer' => $r->customer_name,
                'lines' => $r->lines_count, 'credit_note' => $r->creditNote?->number, 'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'filters' => ['status' => $request->input('status')],
            'statuses' => CustomerReturn::STATUSES,
            'reasons' => CustomerReturnLine::REASONS,
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
                ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
            'can' => ['returns' => $request->user()->can(RoleCatalog::RETURNS)],
        ]);
    }

    /** Orders and invoices a return can be raised against, with what was sent. */
    public function sources(Request $request)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $q = trim((string) $request->input('q', ''));
        $orders = FulfilmentOrder::query()->where(fn ($w) => $w->where('number', 'ilike', "%{$q}%")->orWhere('external_ref', 'ilike', "%{$q}%"))
            ->whereIn('status', ['DISPATCHED', 'PARTIALLY_DISPATCHED', 'DELIVERED'])->with('lines.item:id,uuid,code,name,uom')->limit(10)->get();
        $invoices = FinInvoice::query()->where('direction', 'receivable')->where('doc_type', 'invoice')->where('number', 'ilike', "%{$q}%")
            ->with('party:id,name')->limit(10)->get(['id', 'uuid', 'number', 'party_id', 'total']);

        return response()->json([
            'orders' => $orders->map(fn ($o) => ['id' => $o->uuid, 'title' => "{$o->number} — {$o->customer_name}",
                'lines' => $o->lines->filter(fn ($l) => (float) $l->qty_dispatched > 0)->map(fn ($l) => ['item' => $l->item?->uuid,
                    'title' => "{$l->item?->code} — {$l->item?->name}", 'sent' => (float) $l->qty_dispatched, 'uom' => $l->item?->uom])->values()]),
            'invoices' => $invoices->map(fn ($i) => ['id' => $i->uuid, 'title' => "{$i->number} — {$i->party?->name} (".number_format((float) $i->total, 2).')']),
        ]);
    }

    public function store(Request $request, CustomerReturns $returns): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RETURNS), 403);
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'order' => ['nullable', 'string'], 'invoice' => ['nullable', 'string'],
            'reference' => ['nullable', 'string', 'max:120'], 'customer_name' => ['nullable', 'string', 'max:200'], 'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.item' => ['required', 'string'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.reason_code' => ['nullable', Rule::in(CustomerReturnLine::REASONS)],
        ]);
        $created = null;
        $response = $this->attempt(function () use ($returns, $data, $request, &$created) {
            $created = $returns->authorise([
                'warehouse_id' => FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail()->id,
                'order_id' => ! empty($data['order']) ? FulfilmentOrder::query()->where('uuid', $data['order'])->firstOrFail()->id : null,
                'invoice_id' => ! empty($data['invoice']) ? FinInvoice::query()->where('uuid', $data['invoice'])->firstOrFail()->id : null,
                'reference' => $data['reference'] ?? null, 'customer_name' => $data['customer_name'] ?? null, 'reason' => $data['reason'] ?? null,
                'lines' => array_map(fn ($l) => ['item_id' => FinStockItem::query()->where('uuid', $l['item'])->firstOrFail()->id, 'qty' => (float) $l['qty'],
                    'reason_code' => $l['reason_code'] ?? null], $data['lines']),
            ], $request->user());
        }, 'Return authorised.');

        return $created ? redirect()->route('warehouse.returns.show', $created->uuid)->with('success', "{$created->number} authorised.") : $response;
    }

    public function show(Request $request, string $uuid, EvidenceLocker $evidence): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $r = CustomerReturn::query()->where('uuid', $uuid)->with(['warehouse:id,uuid,code', 'order:id,uuid,number', 'invoice:id,uuid,number,total,amount_paid',
            'creditNote:id,number,total', 'lines.item:id,uuid,code,name,uom', 'lines.lot:id,lot_number', 'lines.bin:id,code', 'lines.inspection:id,uuid,number,status,result,disposition'])
            ->firstOrFail();
        $user = $request->user();

        return Inertia::render('Modules/Warehouse/Returns/Show', [
            'rma' => [
                'id' => $r->uuid, 'number' => $r->number, 'status' => $r->status, 'warehouse' => $r->warehouse?->code, 'order' => $r->order?->number,
                'order_id' => $r->order?->uuid, 'invoice' => $r->invoice?->number, 'invoice_outstanding' => $r->invoice ? round((float) $r->invoice->total - (float) $r->invoice->amount_paid, 2) : null,
                'reference' => $r->reference, 'customer' => $r->customer_name, 'reason' => $r->reason, 'credit_note' => $r->creditNote?->number,
                'credit_amount' => $r->credit_amount !== null ? (float) $r->credit_amount : null, 'cancel_reason' => $r->cancel_reason,
                'received_at' => $r->received_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
                'value' => round($r->lines->sum(fn ($l) => (float) $l->qty_received * (float) $l->unit_cost), 2),
            ],
            'lines' => $r->lines->map(fn (CustomerReturnLine $l) => [
                'id' => $l->id, 'item' => $l->item?->code, 'name' => $l->item?->name, 'uom' => $l->item?->uom, 'lot' => $l->lot?->lot_number,
                'serials' => $l->serials ?? [], 'authorised' => (float) $l->qty_authorised, 'received' => (float) $l->qty_received,
                'reason_code' => $l->reason_code, 'condition' => $l->condition, 'bin' => $l->bin?->code, 'outcome' => $l->outcome, 'notes' => $l->notes,
                'inspection' => $l->inspection ? ['id' => $l->inspection->uuid, 'number' => $l->inspection->number, 'status' => $l->inspection->status,
                    'result' => $l->inspection->result, 'disposition' => $l->inspection->disposition] : null,
                'evidence' => $evidence->for('RETURN_LINE', $l->id),
            ]),
            'bins' => FinStockBin::query()->where('warehouse_id', $r->warehouse_id)->where('level', 'BIN')->where('is_active', true)->orderBy('code')
                ->get(['id', 'code', 'kind'])->map(fn ($b) => ['id' => $b->id, 'title' => "{$b->code} ({$b->kind})"]),
            'lots' => FinStockLot::query()->whereIn('item_id', $r->lines->pluck('item_id'))->orderBy('lot_number')->limit(500)->get(['id', 'item_id', 'lot_number'])
                ->map(fn ($l) => ['id' => $l->id, 'item_id' => $l->item_id, 'title' => $l->lot_number]),
            'item_ids' => $r->lines->mapWithKeys(fn ($l) => [$l->id => $l->item_id]),
            'conditions' => CustomerReturnLine::CONDITIONS,
            'can' => ['returns' => $user->can(RoleCatalog::RETURNS), 'credit' => $user->can(RoleCatalog::CREDIT_RETURNS), 'quality' => $user->can(RoleCatalog::QUALITY)],
        ]);
    }

    public function act(Request $request, string $uuid, CustomerReturns $returns, EvidenceLocker $evidence): RedirectResponse
    {
        $r = CustomerReturn::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['receive', 'credit', 'cancel', 'evidence'])],
            'lines' => ['nullable', 'array'], 'lines.*.qty' => ['nullable', 'numeric', 'min:0'], 'lines.*.condition' => ['nullable', Rule::in(CustomerReturnLine::CONDITIONS)],
            'lines.*.lot_id' => ['nullable', 'integer'], 'lines.*.bin_id' => ['nullable', 'integer'], 'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'lines.*.serials' => ['nullable', 'array'], 'lines.*.serials.*' => ['string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:500'],
            'line_id' => ['nullable', 'integer'], 'file' => ['nullable', 'file', 'max:15360'], 'kind' => ['nullable', 'string', 'max:24'],
        ]);
        $user = $request->user();
        abort_unless($data['action'] === 'credit' ? $user->can(RoleCatalog::CREDIT_RETURNS) : $user->can(RoleCatalog::RETURNS), 403);

        return $this->attempt(fn () => match ($data['action']) {
            'receive' => $returns->receive($r, $data['lines'] ?? [], $user),
            'credit' => $returns->credit($r, (float) ($data['amount'] ?? 0), $user),
            'cancel' => $returns->cancel($r, (string) ($data['note'] ?? '')),
            'evidence' => $evidence->attach('RETURN_LINE', CustomerReturnLine::query()->where('return_id', $r->id)->findOrFail($data['line_id'] ?? 0)->id,
                (int) $r->company_id, $request->file('file') ?? throw new \RuntimeException('Choose a file.'), $user, $data['kind'] ?? 'PHOTO'),
        }, match ($data['action']) {
            'receive' => 'Return received.', 'credit' => 'Credit note raised.', 'cancel' => 'Return cancelled.', default => 'Evidence added.',
        });
    }
}
