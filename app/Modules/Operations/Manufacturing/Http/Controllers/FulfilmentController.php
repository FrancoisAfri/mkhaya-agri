<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinSalesOrderLine;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\CustomerCommitment;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\SubcontractMaterial;
use App\Modules\Operations\Manufacturing\Models\SubcontractOrder;
use App\Modules\Operations\Manufacturing\Services\CustomerOrders;
use App\Modules\Operations\Manufacturing\Services\Subcontracting;
use App\Modules\SupplierManagement\Models\Supplier;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer orders and subcontracting (MFG-017): production committed to sales order lines, and
 * work sent out to suppliers. Sales orders are read here and never written.
 */
class FulfilmentController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly CustomerOrders $customers, private readonly Subcontracting $subcontracting) {}

    // ── Customer orders ──────────────────────────────────────────────────────────────────────

    public function customerOrders(Request $request): Response
    {
        $this->authorize('viewAny', CustomerCommitment::class);
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'all'])]])['status'] ?? 'open';
        $canCommit = $request->user()->can('commit', CustomerCommitment::class);

        return Inertia::render('Modules/Manufacturing/Fulfilment/CustomerOrders', [
            'commitments' => $this->customers->progress($status),
            'eligible' => $canCommit ? $this->customers->eligible() : [],
            'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => "{$f->code} — {$f->name}"])->values(),
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($w) => ['id' => $w->id, 'name' => "{$w->code} — {$w->name}"])->values(),
            'filters' => ['status' => $status],
            'can' => ['commit' => $canCommit],
        ]);
    }

    /** The lead time for a line before committing to it: what is short, and when it could be ready. */
    public function quote(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerCommitment::class);
        $data = $request->validate([
            'line_id' => ['required', 'integer', $this->tenantExists('fin_sales_order_lines')],
            'factory_id' => ['nullable', 'integer', $this->tenantExists('mfg_factories')],
        ]);
        $row = $this->customers->eligible()->firstWhere('line_id', (int) $data['line_id']);
        abort_unless($row, 422, 'That line is not for a product this factory makes, or nothing is left to deliver.');
        $item = Item::query()->whereHas('stockItem', fn ($q) => $q->where('code', $row['item']))->firstOrFail();
        $qty = max(0.0, $row['outstanding'] - $row['free_stock']);

        return response()->json(['to_make' => $qty, 'free_stock' => $row['free_stock'], 'strategy' => $row['strategy']]
            + $this->customers->leadTime($item, $qty, isset($data['factory_id']) ? Factory::query()->find($data['factory_id']) : null));
    }

    public function commit(Request $request): RedirectResponse
    {
        $this->authorize('commit', CustomerCommitment::class);
        $data = $request->validate([
            'line_id' => ['required', 'integer', $this->tenantExists('fin_sales_order_lines')],
            'factory_id' => ['required', 'integer', $this->tenantExists('mfg_factories')],
            'warehouse_id' => ['required', 'integer', $this->tenantExists('fin_warehouses')],
            'required_date' => ['required', 'date'],
            'configuration' => ['nullable', 'string', 'max:2000'],
            'make' => ['boolean'],
        ]);

        return $this->attempt(fn () => $this->customers->commit(FinSalesOrderLine::query()->findOrFail($data['line_id']), [
            'factory' => Factory::query()->findOrFail($data['factory_id']), 'warehouse' => FinWarehouse::query()->findOrFail($data['warehouse_id']),
            'required_date' => $data['required_date'], 'configuration' => $data['configuration'] ?? null, 'make' => (bool) ($data['make'] ?? false),
        ], $request->user()), fn (CustomerCommitment $c) => 'Committed. Promised for '.$c->promised_date?->toDateString().'.');
    }

    public function cancelCommitment(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('commit', CustomerCommitment::class);
        $c = CustomerCommitment::query()->where('uuid', $uuid)->firstOrFail();

        return $this->attempt(fn () => $this->customers->cancel($c, $request->user()), fn () => 'Commitment cancelled; its stock is free again.');
    }

    // ── Subcontracting ───────────────────────────────────────────────────────────────────────

    public function subcontracts(Request $request): Response
    {
        $this->authorize('viewAny', SubcontractOrder::class);
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'all'])]])['status'] ?? 'open';
        $canManage = $request->user()->can('manage', SubcontractOrder::class);

        return Inertia::render('Modules/Manufacturing/Fulfilment/Subcontracts', [
            'subcontracts' => SubcontractOrder::query()->with(['order:id,uuid,number', 'operation:id,sequence,name', 'supplier:id,company_name'])
                ->when($status === 'open', fn ($q) => $q->whereIn('status', SubcontractOrder::OPEN))
                ->orderByDesc('id')->limit(500)->get()->map(fn (SubcontractOrder $s) => $this->row($s))->values(),
            'candidates' => $canManage ? $this->candidates() : [],
            'suppliers' => $canManage ? Supplier::query()->where('is_active', true)->orderBy('company_name')->limit(2000)->get(['id', 'company_name'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->company_name])->values() : [],
            'filters' => ['status' => $status],
            'can' => ['manage' => $canManage],
        ]);
    }

    public function storeSubcontract(Request $request): RedirectResponse
    {
        $this->authorize('manage', SubcontractOrder::class);
        $data = $request->validate([
            'production_order' => ['required', 'string'],
            'operation' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers')],
            'qty' => ['nullable', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $order = PO::query()->where('uuid', $data['production_order'])->firstOrFail();
        $op = ! empty($data['operation']) ? Op::query()->where('uuid', $data['operation'])->where('order_id', $order->id)->firstOrFail() : null;

        try {
            $sco = $this->subcontracting->create($order, $op, $data, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.fulfilment.subcontracts.show', $sco->uuid)->with('success', "{$sco->number} raised.");
    }

    public function showSubcontract(Request $request, string $uuid): Response
    {
        $sco = $this->findSubcontract($uuid);
        $this->authorize('viewAny', SubcontractOrder::class);
        $sco->load(['order.item.stockItem:id,code,name', 'operation', 'supplier:id,company_name', 'materials.stockItem:id,code,name', 'receipts.inspection:id,uuid,number,result,status,disposition']);

        return Inertia::render('Modules/Manufacturing/Fulfilment/SubcontractShow', [
            // MFG-023: the record's documents, through Document Management's own visibility rules.
            'documents_panel' => \App\Modules\Operations\DocumentManagement\Support\DocumentModule::panelFor($sco, $request->user()),
            'subcontract' => $this->row($sco) + [
                'item' => $sco->order?->item?->stockItem?->code, 'item_name' => $sco->order?->item?->stockItem?->name,
                'notes' => $sco->notes, 'dispatch_reference' => $sco->dispatch_reference, 'sent_at' => $sco->sent_at?->toIso8601String(),
                'purchase_request' => $sco->purchase_request_id ? DB::table('purchase_requests')->where('id', $sco->purchase_request_id)->value('request_number') : null,
                'cost' => $sco->cost(), 'currency' => $sco->currency,
            ],
            'materials' => $sco->materials->map(fn (SubcontractMaterial $m) => [
                'id' => $m->id, 'item' => $m->stockItem?->code, 'name' => $m->stockItem?->name, 'planned' => (float) $m->qty_planned,
                'sent' => (float) $m->qty_sent, 'returned' => (float) $m->qty_returned,
                'at_supplier' => round((float) $m->qty_sent - (float) $m->qty_returned, 6),
            ])->values(),
            'receipts' => $sco->receipts->map(fn ($r) => [
                'qty' => (float) $r->qty, 'supplier_reference' => $r->supplier_reference, 'received_at' => $r->received_at?->toIso8601String(),
                'inspection' => $r->inspection ? ['id' => $r->inspection->uuid, 'number' => $r->inspection->number, 'result' => $r->inspection->result,
                    'status' => $r->inspection->status, 'disposition' => $r->inspection->disposition] : null,
            ])->values(),
            'can' => ['manage' => $request->user()->can('manage', SubcontractOrder::class)],
        ]);
    }

    public function send(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', SubcontractOrder::class);
        $sco = $this->findSubcontract($uuid);
        $data = $request->validate(['dispatch_reference' => ['nullable', 'string', 'max:120'], 'materials' => ['nullable', 'array'], 'materials.*' => ['numeric', 'min:0']]);
        $byComponent = [];
        foreach ($data['materials'] ?? [] as $materialId => $q) {
            if ($m = $sco->materials()->whereKey($materialId)->first()) {
                $byComponent[$m->component_id] = (float) $q;
            }
        }

        return $this->attempt(fn () => $this->subcontracting->send($sco, $request->user(), $data['dispatch_reference'] ?? null, $byComponent),
            fn () => "{$sco->number} sent; a draft purchase request is with procurement.");
    }

    public function receive(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', SubcontractOrder::class);
        $sco = $this->findSubcontract($uuid);
        $data = $request->validate(['qty' => ['required', 'numeric', 'gt:0'], 'supplier_reference' => ['nullable', 'string', 'max:120'],
            'lot_number' => ['nullable', 'string', 'max:80']]);

        return $this->attempt(fn () => $this->subcontracting->receive($sco, (float) $data['qty'], $request->user(), $data['supplier_reference'] ?? null, $data['lot_number'] ?? null),
            fn () => 'Received and waiting on inspection.');
    }

    public function returnMaterial(Request $request, string $uuid, int $material): RedirectResponse
    {
        $this->authorize('manage', SubcontractOrder::class);
        $sco = $this->findSubcontract($uuid);
        $m = $sco->materials()->whereKey($material)->firstOrFail();
        $qty = (float) $request->validate(['qty' => ['required', 'numeric', 'gt:0']])['qty'];

        return $this->attempt(fn () => $this->subcontracting->returnMaterial($sco, $m, $qty, $request->user()), fn () => 'Material returned to stock.');
    }

    public function close(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', SubcontractOrder::class);
        $sco = $this->findSubcontract($uuid);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        return $this->attempt(fn () => $this->subcontracting->close($sco, $request->user(), $reason), fn () => "{$sco->number} closed.");
    }

    public function cancel(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('manage', SubcontractOrder::class);
        $sco = $this->findSubcontract($uuid);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->subcontracting->cancel($sco, $request->user(), $reason), fn () => "{$sco->number} cancelled.");
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    /** Orders that can send work out: their subcontracted operations, or the whole order. */
    private function candidates(): array
    {
        $orders = PO::query()->whereIn('status', [PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])
            ->with(['item.stockItem:id,code', 'operations'])->orderBy('due_date')->limit(300)->get();
        $busy = SubcontractOrder::query()->whereIn('status', SubcontractOrder::OPEN)->whereNotNull('order_operation_id')->pluck('order_operation_id')->all();

        return $orders->map(fn (PO $o) => [
            'id' => $o->uuid, 'name' => "{$o->number} · {$o->item?->stockItem?->code} × ".rtrim(rtrim(number_format((float) $o->planned_qty, 4, '.', ''), '0'), '.'),
            'operations' => $o->operations->filter(fn (Op $op) => $op->is_subcontracted && ! in_array($op->status, [Op::COMPLETED, Op::SKIPPED], true) && ! in_array($op->id, $busy, true))
                ->map(fn (Op $op) => ['id' => $op->uuid, 'name' => "{$op->sequence} {$op->name}"])->values(),
        ])->values()->all();
    }

    private function row(SubcontractOrder $s): array
    {
        return [
            'id' => $s->uuid, 'number' => $s->number, 'status' => $s->status,
            'order' => $s->order ? ['id' => $s->order->uuid, 'number' => $s->order->number] : null,
            'operation' => $s->operation ? "{$s->operation->sequence} {$s->operation->name}" : null,
            'supplier' => $s->supplier?->company_name, 'qty' => (float) $s->qty, 'sent' => (float) $s->qty_sent,
            'received' => (float) $s->qty_received, 'lost' => (float) $s->qty_rejected, 'outstanding' => $s->outstanding(),
            'unit_price' => (float) $s->unit_price, 'due_date' => $s->due_date?->toDateString(), 'overdue' => $s->isOverdue(),
        ];
    }

    private function findSubcontract(string $uuid): SubcontractOrder
    {
        return SubcontractOrder::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function attempt(callable $do, callable $message): RedirectResponse
    {
        try {
            $result = $do();
        } catch (DomainException|\App\Modules\Finance\Accounting\Exceptions\PostingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message($result));
    }
}
