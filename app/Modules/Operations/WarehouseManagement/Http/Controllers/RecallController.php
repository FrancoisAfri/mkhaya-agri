<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Operations\Manufacturing\Models\Recall;
use App\Modules\Operations\Manufacturing\Models\RecallLot;
use App\Modules\Operations\Manufacturing\Services\Recalls;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\EvidenceLocker;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseRecalls;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** WMS-083: recalls, seen and worked from the warehouse. */
class RecallController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $list = Recall::query()->with(['originLot:id,lot_number,item_id'])->withCount('lots')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))->latest('id')->paginate(25)->withQueryString();
        $items = FinStockItem::query()->whereIn('id', $list->pluck('stock_item_id')->merge($list->pluck('originLot.item_id'))->filter()->unique())->pluck('code', 'id');

        return Inertia::render('Modules/Warehouse/Recalls/Index', [
            'recalls' => $list->through(fn (Recall $r) => [
                'id' => $r->uuid, 'number' => $r->number, 'title' => $r->title, 'severity' => $r->severity, 'status' => $r->status, 'scope' => $r->scope ?? 'LOT',
                'item' => $items[$r->stock_item_id ?? $r->originLot?->item_id] ?? null, 'lot' => $r->originLot?->lot_number, 'lots' => $r->lots_count,
                'opened_at' => $r->opened_at?->toIso8601String(), 'closed_at' => $r->closed_at?->toIso8601String(),
            ]),
            'filters' => ['status' => $request->input('status')],
            'severities' => Recall::SEVERITIES,
            'can' => ['recall' => $request->user()->can(RoleCatalog::RECALLS)],
        ]);
    }

    public function lots(Request $request)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $item = FinStockItem::query()->where('uuid', $request->input('item'))->firstOrFail();

        return response()->json(FinStockLot::query()->where('item_id', $item->id)->orderByDesc('id')->limit(200)->get(['id', 'lot_number', 'expires_on'])
            ->map(fn ($l) => ['id' => $l->id, 'title' => $l->lot_number.($l->expires_on ? " (exp {$l->expires_on->toDateString()})" : '')]));
    }

    public function store(Request $request, WarehouseRecalls $recalls): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RECALLS), 403);
        $data = $request->validate([
            'scope' => ['required', Rule::in(['LOT', 'SERIALS', 'ITEM'])], 'item' => ['required', 'string'], 'lot_id' => ['required_if:scope,LOT', 'nullable', 'integer'],
            'serial_from' => ['required_if:scope,SERIALS', 'nullable', 'string', 'max:120'], 'serial_to' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'], 'reason' => ['required', 'string', 'max:2000'], 'severity' => ['required', Rule::in(Recall::SEVERITIES)],
        ]);
        $item = FinStockItem::query()->where('uuid', $data['item'])->firstOrFail();
        if (! empty($data['lot_id'])) {
            FinStockLot::query()->where('item_id', $item->id)->findOrFail($data['lot_id']);
        }
        $recall = null;
        $response = $this->attempt(function () use ($recalls, $data, $item, $request, &$recall) {
            $recall = $recalls->open(['item_id' => $item->id] + $data, $request->user());
        }, 'Recall opened.');

        return $recall ? redirect()->route('warehouse.recalls.show', $recall->uuid)->with('success', "{$recall->number} opened.") : $response;
    }

    public function show(Request $request, string $uuid, WarehouseRecalls $recalls, EvidenceLocker $evidence): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $recall = Recall::query()->where('uuid', $uuid)->with(['lots.lot:id,lot_number,expires_on', 'lots.item:id,code,name,uom', 'actions'])->firstOrFail();

        return Inertia::render('Modules/Warehouse/Recalls/Show', $this->payload($recall, $recalls, $evidence) + [
            'can' => ['recall' => $request->user()->can(RoleCatalog::RECALLS)],
        ]);
    }

    /** The evidence report: everything the recall found and did, printable as it stands. */
    public function report(Request $request, string $uuid, WarehouseRecalls $recalls, EvidenceLocker $evidence): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        abort_unless($request->user()->can(RoleCatalog::RECALLS), 403);
        $recall = Recall::query()->where('uuid', $uuid)->with(['lots.lot:id,lot_number,expires_on', 'lots.item:id,code,name,uom', 'actions'])->firstOrFail();

        return Inertia::render('Modules/Warehouse/Recalls/Report', $this->payload($recall, $recalls, $evidence) + ['printed_at' => now()->toIso8601String()]);
    }

    public function act(Request $request, string $uuid, WarehouseRecalls $recalls, Recalls $mfg, EvidenceLocker $evidence): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::RECALLS), 403);
        $recall = Recall::query()->where('uuid', $uuid)->firstOrFail();
        $data = $request->validate([
            'action' => ['required', Rule::in(['hold', 'release', 'dispose', 'record', 'close', 'evidence'])],
            'recall_lot_id' => ['nullable', 'integer'], 'qty' => ['nullable', 'numeric', 'gt:0'], 'reason' => ['nullable', 'string', 'max:1000'],
            'kind' => ['nullable', Rule::in(['RETRIEVED', 'CUSTOMER_NOTIFIED', 'REGULATOR_NOTIFIED', 'NOTE'])], 'reference' => ['nullable', 'string', 'max:200'],
            'file' => ['nullable', 'file', 'max:15360'],
        ]);
        $user = $request->user();
        $lot = fn () => RecallLot::query()->where('recall_id', $recall->id)->findOrFail($data['recall_lot_id'] ?? 0);
        $domain = function (callable $work) {
            try {
                return $work();
            } catch (DomainException $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
        };

        return $this->attempt(fn () => match ($data['action']) {
            'hold' => $recalls->holdAll($recall, $user),
            'release' => $domain(fn () => $mfg->release($recall, $lot(), (float) ($data['qty'] ?? 0), (string) ($data['reason'] ?? ''), $user)),
            'dispose' => $domain(fn () => $mfg->dispose($recall, $lot(), (float) ($data['qty'] ?? 0), (string) ($data['reason'] ?? ''), $user)),
            'record' => $domain(fn () => $mfg->record($recall, $data['kind'] ?? 'NOTE', ! empty($data['recall_lot_id']) ? $lot() : null,
                isset($data['qty']) ? (float) $data['qty'] : null, $data['reference'] ?? null, $data['reason'] ?? null, $user)),
            'close' => $recalls->close($recall, (string) ($data['reason'] ?? ''), $user),
            'evidence' => $evidence->attach('RECALL', $recall->id, (int) $recall->company_id, $request->file('file') ?? throw new RuntimeException('Choose a file.'), $user, 'REPORT', $data['reason'] ?? null),
        }, match ($data['action']) {
            'hold' => 'Affected stock put on hold.', 'release' => 'Released.', 'dispose' => 'Disposed of.', 'close' => 'Recall closed.',
            'evidence' => 'Evidence added.', default => 'Recorded.',
        });
    }

    private function payload(Recall $recall, WarehouseRecalls $recalls, EvidenceLocker $evidence): array
    {
        $item = $recall->stock_item_id ? FinStockItem::query()->find($recall->stock_item_id) : null;

        return [
            'recall' => ['id' => $recall->uuid, 'number' => $recall->number, 'title' => $recall->title, 'reason' => $recall->reason, 'severity' => $recall->severity,
                'status' => $recall->status, 'scope' => $recall->scope ?? 'LOT', 'item' => $item ? "{$item->code} — {$item->name}" : null,
                'serial_from' => $recall->serial_from, 'serial_to' => $recall->serial_to, 'opened_at' => $recall->opened_at?->toIso8601String(),
                'closed_at' => $recall->closed_at?->toIso8601String(), 'closure_notes' => $recall->closure_notes],
            'lots' => $recall->lots->map(fn (RecallLot $l) => ['id' => $l->id, 'lot' => $l->lot?->lot_number, 'item' => $l->item?->code, 'uom' => $l->item?->uom,
                'depth' => $l->depth, 'in_stock_at_open' => (float) $l->in_stock_at_open, 'dispatched_at_open' => (float) $l->dispatched_at_open,
                'held' => (float) $l->held, 'released' => (float) $l->released, 'disposed' => (float) $l->disposed, 'retrieved' => (float) $l->retrieved,
                'outstanding' => round((float) $l->held - (float) $l->released - (float) $l->disposed, 4)]),
            'actions' => $recall->actions->map(fn ($a) => ['kind' => $a->kind, 'qty' => $a->qty !== null ? (float) $a->qty : null, 'reference' => $a->reference,
                'notes' => $a->notes, 'at' => $a->acted_at?->toIso8601String()]),
            'impact' => $recalls->impact($recall),
            'evidence' => $evidence->for('RECALL', $recall->id),
        ];
    }
}
