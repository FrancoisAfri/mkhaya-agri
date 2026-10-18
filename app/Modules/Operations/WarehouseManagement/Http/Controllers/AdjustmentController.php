<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Services\ClientVisibility;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-024: stock adjustments with reasons and, where the risk calls for it, a second person. */
class AdjustmentController extends Controller
{
    use RefusesPolitely;

    public function __construct(private readonly StockAdjustments $adjustments, private readonly ClientVisibility $clients) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $cid = $this->companyId($request);
        if ($cid) {
            $this->adjustments->ensureReasonCodes($cid);
        }

        $list = StockAdjustment::query()->with(['item:id,uuid,code,name,uom', 'warehouse:id,code', 'bin:id,code', 'lot:id,lot_number', 'reason:id,code,name', 'requester.person', 'decider.person'])
            ->tap(fn ($q) => $this->clients->scope($q, 'item_id'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('number'), fn ($q) => $q->where('number', $request->input('number')))
            ->latest('id')->paginate(30)->withQueryString();

        $user = $request->user();

        return Inertia::render('Modules/Warehouse/Stock/Adjustments', [
            'adjustments' => $list->through(fn (StockAdjustment $a) => [
                'id' => $a->uuid, 'number' => $a->number, 'status' => $a->status,
                'item' => $a->item?->code, 'item_name' => $a->item?->name, 'uom' => $a->item?->uom,
                'warehouse' => $a->warehouse?->code, 'bin' => $a->bin?->code, 'lot' => $a->lot?->lot_number, 'stock_status' => $a->stock_status,
                'qty' => (float) $a->qty_delta, 'value' => (float) $a->value_estimate, 'reason' => $a->reason?->name, 'notes' => $a->notes,
                'occurred_at' => $a->occurred_at?->toIso8601String(), 'approval_reasons' => $a->approval_reasons ?? [],
                'requested_by' => $this->name($a->requester), 'decided_by' => $this->name($a->decider), 'decision_note' => $a->decision_note,
                'mine' => (int) $a->requested_by === $user->id,
                'evidence' => app(\App\Modules\Operations\WarehouseManagement\Services\EvidenceLocker::class)->for('ADJUSTMENT', $a->id),
                'recoveries' => \App\Modules\Operations\WarehouseManagement\Models\LossRecovery::query()->where('adjustment_id', $a->id)->get()
                    ->map(fn ($r) => ['id' => $r->uuid, 'kind' => $r->kind, 'claim_reference' => $r->claim_reference, 'claimed' => (float) $r->amount_claimed,
                        'recovered' => (float) $r->amount_recovered, 'status' => $r->status]),
            ]),
            'filters' => ['status' => $request->input('status'), 'number' => $request->input('number')],
            'pending' => StockAdjustment::query()->where('status', StockAdjustment::PENDING)->count(),
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'uuid', 'code', 'name'])
                ->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
            'reasons' => ReasonCode::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (ReasonCode $r) => ['id' => $r->uuid, 'title' => $r->name, 'direction' => $r->direction, 'always' => $r->always_requires_approval,
                    'evidence' => $r->requires_evidence]),
            'states' => FinStockPosition::STATUSES,
            'can' => ['adjust' => $user->can(RoleCatalog::ADJUST_STOCK), 'approve' => $user->can(RoleCatalog::APPROVE_ADJUSTMENTS),
                'losses' => $user->can(RoleCatalog::LOSSES)],
        ]);
    }

    /** Positions of one item in one warehouse, for the adjustment form's bin/lot choice. */
    public function positions(Request $request)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $item = FinStockItem::query()->where('uuid', $request->input('item'))->firstOrFail();
        $warehouse = FinWarehouse::query()->where('uuid', $request->input('warehouse'))->firstOrFail();
        abort_unless($this->clients->mayView($item), 403);

        return response()->json([
            'positions' => FinStockPosition::query()->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->where('qty', '!=', 0)
                ->with(['bin:id,uuid,code', 'lot:id,uuid,lot_number'])->get()
                ->map(fn ($p) => ['bin' => $p->bin?->uuid, 'bin_code' => $p->bin?->code, 'lot' => $p->lot?->uuid, 'lot_number' => $p->lot?->lot_number, 'status' => $p->status, 'qty' => (float) $p->qty]),
            'bins' => FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('level', 'BIN')->where('is_active', true)->orderBy('code')->get(['uuid', 'code'])
                ->map(fn ($b) => ['id' => $b->uuid, 'title' => $b->code]),
            'uom' => $item->uom,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::ADJUST_STOCK), 403);
        $data = $request->validate([
            'item' => ['required', 'string'], 'warehouse' => ['required', 'string'],
            'bin' => ['nullable', 'string'], 'lot' => ['nullable', 'string'],
            'stock_status' => ['required', Rule::in(FinStockPosition::STATUSES)],
            'qty' => ['required', 'numeric', 'not_in:0'],
            'uom_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
            'evidence' => ['nullable', 'array', 'max:10'], 'evidence.*' => ['file', 'max:15360'],
        ]);
        $item = FinStockItem::query()->where('uuid', $data['item'])->firstOrFail();
        abort_unless($this->clients->mayView($item), 403);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();

        return $this->attempt(fn () => $this->adjustments->request([
            'warehouse_id' => $warehouse->id, 'item_id' => $item->id,
            'bin_id' => ! empty($data['bin']) ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('uuid', $data['bin'])->firstOrFail()->id : null,
            'lot_id' => ! empty($data['lot']) ? FinStockLot::query()->where('item_id', $item->id)->where('uuid', $data['lot'])->firstOrFail()->id : null,
            'stock_status' => $data['stock_status'], 'qty' => (float) $data['qty'], 'uom_id' => $data['uom_id'] ?? null,
            'reason_code_id' => ReasonCode::query()->where('uuid', $data['reason'])->firstOrFail()->id,
            'notes' => $data['notes'] ?? null, 'occurred_at' => $data['occurred_at'] ?? null,
            'evidence' => $request->file('evidence', []),
        ], $request->user()), 'Adjustment recorded.');
    }

    /** The document a movement links back to: the list, opened on this adjustment. */
    public function show(string $uuid): RedirectResponse
    {
        $this->authorize('viewAny', FinStockItem::class);

        return redirect()->route('warehouse.stock.adjustments.index', ['number' => StockAdjustment::query()->where('uuid', $uuid)->firstOrFail()->number]);
    }

    public function decide(Request $request, string $uuid): RedirectResponse
    {
        $adjustment = StockAdjustment::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'cancel'])], 'note' => ['nullable', 'string', 'max:1000']]);

        if ($data['decision'] !== 'cancel') {
            abort_unless($request->user()->can(RoleCatalog::APPROVE_ADJUSTMENTS)
                || app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseApprovals::class)->mayDecide($request->user(), 'ADJUSTMENT', $adjustment->id, (int) $adjustment->requested_by), 403);
        }

        return $this->attempt(fn () => match ($data['decision']) {
            'approve' => $this->adjustments->approve($adjustment, $request->user(), $data['note'] ?? null),
            'reject' => $this->adjustments->reject($adjustment, $request->user(), (string) ($data['note'] ?? '')),
            default => $this->adjustments->cancel($adjustment, $request->user()),
        }, 'Adjustment '.($data['decision'] === 'approve' ? 'approval recorded.' : ($data['decision'] === 'reject' ? 'rejected.' : 'withdrawn.')));
    }

    private function name($user): ?string
    {
        return $user?->person ? trim($user->person->first_name.' '.$user->person->surname) : $user?->email;
    }

    private function companyId(Request $request): ?int
    {
        return app(CurrentCompany::class)->id() ?? $request->user()?->company_id;
    }
}
