<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(private InventoryService $inventory)
    {
    }

    public function index(): Response
    {
        $valuation = $this->inventory->valuation();

        return Inertia::render('Modules/Finance/Accounting/Inventory', [
            'items'   => $valuation,
            'methods' => FinStockItem::METHODS,
            'summary' => ['count' => count($valuation), 'value' => round(array_sum(array_column($valuation, 'value')), 2)],
            'accounts' => FinAccount::postable()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'label' => "{$a->code} · {$a->name}"]),
            'warehouses' => $this->inventory->warehouses(),
        ]);
    }

    private function warehouse(Request $request): ?\App\Modules\Finance\Accounting\Models\FinWarehouse
    {
        $id = $request->integer('warehouse_id');

        return $id ? \App\Modules\Finance\Accounting\Models\FinWarehouse::find($id) : null;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'          => 'required|string|max:60',
            'name'          => 'required|string|max:150',
            'cost_method'   => 'required|in:fifo,weighted_avg,standard',
            'uom'           => 'nullable|string|max:20',
            'standard_cost' => 'nullable|numeric|min:0',
            'reorder_level' => 'nullable|numeric|min:0',
            'inventory_account_id' => 'nullable|integer|exists:fin_accounts,id',
            'cogs_account_id'      => 'nullable|integer|exists:fin_accounts,id',
        ]);

        try {
            $this->inventory->createItem($data);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['item' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Stock item created.');
    }

    public function receive(Request $request, FinStockItem $item): RedirectResponse
    {
        $data = $request->validate([
            'qty' => 'required|numeric|min:0.0001', 'unit_cost' => 'required|numeric|min:0',
            'date' => 'nullable|date', 'contra' => 'nullable|string', 'reference' => 'nullable|string|max:120',
            'warehouse_id' => 'nullable|integer|exists:fin_warehouses,id',
        ]);

        try {
            $this->inventory->receive($item, (float) $data['qty'], (float) $data['unit_cost'], $data['date'] ?? null, $data['contra'] ?? null, $data['reference'] ?? null, $this->warehouse($request));
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['item' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Stock received.');
    }

    public function issue(Request $request, FinStockItem $item): RedirectResponse
    {
        $data = $request->validate([
            'qty' => 'required|numeric|min:0.0001', 'date' => 'nullable|date', 'reference' => 'nullable|string|max:120',
            'warehouse_id' => 'nullable|integer|exists:fin_warehouses,id',
        ]);

        try {
            $this->inventory->issue($item, (float) $data['qty'], $data['date'] ?? null, $data['reference'] ?? null, $this->warehouse($request));
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['item' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Stock issued.');
    }

    /** Manually adjust stock up/down (stock-take, write-off, correction). */
    public function adjust(Request $request, FinStockItem $item): RedirectResponse
    {
        $data = $request->validate([
            'qty_delta' => 'required|numeric|not_in:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'reason'    => 'nullable|string|max:150',
            'date'      => 'nullable|date',
            'warehouse_id' => 'nullable|integer|exists:fin_warehouses,id',
        ]);

        try {
            $unitCost = $data['unit_cost'] ?? (float) $item->avg_cost;
            $this->inventory->adjust($item, (float) $data['qty_delta'], (float) $unitCost, $data['reason'] ?? null, $data['date'] ?? null, null, $this->warehouse($request));
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['item' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Stock adjusted.');
    }

    /** Transfer stock between two warehouses. */
    public function transfer(Request $request, FinStockItem $item): RedirectResponse
    {
        $data = $request->validate([
            'qty'          => 'required|numeric|min:0.0001',
            'from_id'      => 'required|integer|exists:fin_warehouses,id',
            'to_id'        => 'required|integer|different:from_id|exists:fin_warehouses,id',
            'date'         => 'nullable|date',
            'reference'    => 'nullable|string|max:120',
        ]);

        try {
            $this->inventory->transfer(
                $item,
                \App\Modules\Finance\Accounting\Models\FinWarehouse::findOrFail($data['from_id']),
                \App\Modules\Finance\Accounting\Models\FinWarehouse::findOrFail($data['to_id']),
                (float) $data['qty'], $data['date'] ?? null, $data['reference'] ?? null,
            );
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['item' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Stock transferred.');
    }
}
