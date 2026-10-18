<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Transfer;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Services\MultiFactory;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Several factories in one company (MFG-024): transfers between them, and the factory network. */
class MultiFactoryController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly MultiFactory $factories) {}

    public function transfers(Request $request): Response
    {
        $this->authorize('viewAny', Transfer::class);
        $state = $request->validate(['state' => ['nullable', Rule::in(['open', 'all'])]])['state'] ?? 'open';
        $restriction = app(FactoryAccess::class)->restrictionFor($request->user());

        return Inertia::render('Modules/Manufacturing/Factories/Transfers', [
            'transfers' => Transfer::query()->with(['fromFactory:id,code', 'toFactory:id,code', 'lines.stockItem:id,code'])
                ->when($state === 'open', fn ($q) => $q->whereIn('status', [Transfer::REQUESTED, Transfer::APPROVED, Transfer::SHIPPED]))
                // A person confined to some factories sees only transfers to or from them.
                ->when($restriction !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_factory_id', $restriction['factories'] ?: [0])
                    ->orWhereIn('to_factory_id', $restriction['factories'] ?: [0])))
                ->latest('id')->limit(300)->get()->map(fn (Transfer $t) => $this->row($t))->values(),
            'balances' => $this->factories->balances(),
            'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($f) => ['id' => $f->id, 'name' => "{$f->code} — {$f->name}"])->values(),
            'items' => FinStockItem::query()->where('is_active', true)->orderBy('code')->limit(3000)->get(['id', 'code', 'name'])->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->code} — {$i->name}"])->values(),
            'filters' => ['state' => $state],
            'can' => ['manage' => $request->user()->can('manage', Transfer::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', Transfer::class);
        $d = $request->validate([
            'from_factory_id' => ['required', 'integer', $this->tenantExists('mfg_factories')],
            'to_factory_id' => ['required', 'integer', 'different:from_factory_id', $this->tenantExists('mfg_factories')],
            'needed_by' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.stock_item_id' => ['required', 'integer', $this->tenantExists('fin_stock_items')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            // Asking another factory for stock means naming a factory one may not work at; the
            // validation above has already confined both to this company.
            $t = $this->factories->request(['from' => Factory::withoutGlobalScopes()->findOrFail($d['from_factory_id']), 'to' => Factory::withoutGlobalScopes()->findOrFail($d['to_factory_id']),
                'lines' => $d['lines'], 'needed_by' => $d['needed_by'] ?? null, 'reason' => $d['reason'] ?? null], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('manufacturing.factories.transfers.show', $t->uuid)->with('success', "{$t->number} requested.");
    }

    public function show(Request $request, string $uuid): Response
    {
        $this->authorize('viewAny', Transfer::class);
        $t = Transfer::query()->where('uuid', $uuid)->with(['fromFactory', 'toFactory', 'fromWarehouse', 'toWarehouse', 'lines.stockItem'])->firstOrFail();
        $access = app(FactoryAccess::class);
        abort_unless($access->canSeeFactory($request->user(), $t->from_factory_id) || $access->canSeeFactory($request->user(), $t->to_factory_id), 403);

        return Inertia::render('Modules/Manufacturing/Factories/TransferShow', [
            'transfer' => $this->row($t) + ['from_warehouse' => $t->fromWarehouse?->code, 'to_warehouse' => $t->toWarehouse?->code, 'reason' => $t->reason,
                'discrepancy' => $t->discrepancy, 'needed_by' => $t->needed_by?->toDateString(),
                'lines' => $t->lines->map(fn ($l) => ['id' => $l->id, 'item' => $l->stockItem?->code, 'name' => $l->stockItem?->name,
                    'requested' => $l->qty_requested, 'shipped' => $l->qty_shipped, 'received' => $l->qty_received])->values()],
            'can' => ['manage' => $request->user()->can('manage', Transfer::class),
                'at_source' => $access->canSeeFactory($request->user(), $t->from_factory_id), 'at_destination' => $access->canSeeFactory($request->user(), $t->to_factory_id)],
        ]);
    }

    public function act(Request $request, string $uuid, string $step): RedirectResponse
    {
        $this->authorize('manage', Transfer::class);
        $t = Transfer::query()->where('uuid', $uuid)->firstOrFail();
        $d = $request->validate(['qty' => ['nullable', 'array'], 'qty.*' => ['numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:1000'],
            'discrepancy' => ['nullable', 'string', 'max:1000']]);

        try {
            match ($step) {
                'approve' => $this->factories->approve($t, $request->user()),
                'ship' => $this->factories->ship($t, $request->user(), $d['qty'] ?? []),
                'receive' => $this->factories->receive($t, $request->user(), $d['qty'] ?? [], $d['discrepancy'] ?? null),
                'cancel' => $this->factories->cancel($t, $request->user(), (string) ($d['reason'] ?? '')),
            };
        } catch (DomainException|PostingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Saved.');
    }

    // ── The network: warehouses per factory, products per factory ───────────────────────────

    public function network(Request $request): Response
    {
        $this->authorize('network', Transfer::class);
        $factories = Factory::query()->where('is_active', true)->orderBy('code')->get();

        return Inertia::render('Modules/Manufacturing/Factories/Network', [
            'factories' => $factories->map(fn (Factory $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name,
                'default_warehouse' => FinWarehouse::query()->whereKey($f->default_warehouse_id)->value('code'),
                'warehouses' => DB::table('mfg_factory_warehouses as fw')->join('fin_warehouses as w', 'w.id', '=', 'fw.warehouse_id')->where('fw.factory_id', $f->id)
                    ->orderBy('w.code')->get(['w.id', 'w.code', 'fw.role'])->map(fn ($w) => (array) $w)->all()])->values(),
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($w) => ['id' => $w->id, 'name' => "{$w->code} — {$w->name}"])->values(),
            'restricted' => Item::query()->where('factory_scope', 'LISTED')->with('stockItem:id,code,name')->get()->map(fn (Item $i) => [
                'id' => $i->uuid, 'product' => $i->stockItem?->code, 'factories' => DB::table('mfg_item_factories')->where('item_id', $i->id)->pluck('factory_id')->all()])->values(),
            'products' => Item::query()->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)->with('stockItem:id,code,name')->limit(3000)->get()
                ->map(fn (Item $i) => ['id' => $i->uuid, 'name' => trim(($i->stockItem?->code ?? '').' — '.($i->stockItem?->name ?? '')), 'scope' => $i->factory_scope])->sortBy('name')->values(),
            'can' => ['configure' => $request->user()->can('configure', Transfer::class)],
        ]);
    }

    public function assignWarehouse(Request $request): RedirectResponse
    {
        $this->authorize('configure', Transfer::class);
        $d = $request->validate(['factory_id' => ['required', 'integer', $this->tenantExists('mfg_factories')], 'warehouse_id' => ['required', 'integer', $this->tenantExists('fin_warehouses')],
            'role' => ['required', Rule::in(['STORES', 'FINISHED', 'GENERAL'])], 'remove' => ['boolean']]);
        if (! empty($d['remove'])) {
            DB::table('mfg_factory_warehouses')->where('factory_id', $d['factory_id'])->where('warehouse_id', $d['warehouse_id'])->delete();
        } else {
            $this->factories->assignWarehouse(Factory::query()->findOrFail($d['factory_id']), FinWarehouse::query()->findOrFail($d['warehouse_id']), $d['role']);
        }

        return back()->with('success', 'Factory warehouses saved.');
    }

    public function restrictProduct(Request $request, string $uuid): RedirectResponse
    {
        $this->authorize('configure', Transfer::class);
        $item = Item::query()->where('uuid', $uuid)->firstOrFail();
        $d = $request->validate(['scope' => ['required', Rule::in(['ALL', 'LISTED'])], 'factories' => ['array'], 'factories.*' => ['integer', $this->tenantExists('mfg_factories')]]);

        try {
            $this->factories->restrict($item, $d['scope'], $d['factories'] ?? [], $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Where it may be made is saved.');
    }

    private function row(Transfer $t): array
    {
        return ['id' => $t->uuid, 'number' => $t->number, 'status' => $t->status, 'from' => $t->fromFactory?->code, 'to' => $t->toFactory?->code,
            'items' => $t->lines->map(fn ($l) => $l->stockItem?->code.' × '.rtrim(rtrim(number_format($l->qty_requested, 4, '.', ''), '0'), '.'))->implode(', '),
            'needed_by' => $t->needed_by?->toDateString(), 'shipped_at' => $t->shipped_at?->toDateString(), 'received_at' => $t->received_at?->toDateString()];
    }
}
