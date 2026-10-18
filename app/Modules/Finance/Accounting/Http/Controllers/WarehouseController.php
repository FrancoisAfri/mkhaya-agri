<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBalance;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage stock-holding warehouses/locations. Tenant-scoped; exactly one default per company.
 */
class WarehouseController extends Controller
{
    public function __construct(private CurrentCompany $currentCompany)
    {
    }

    public function index(): Response
    {
        $rows = FinWarehouse::query()->orderByDesc('is_default')->orderBy('name')->get();
        // Per-warehouse: how many items hold stock + total units on hand.
        $stats = FinStockBalance::query()
            ->selectRaw('warehouse_id, COUNT(*) FILTER (WHERE qty <> 0) AS lines, COALESCE(SUM(qty),0) AS units')
            ->groupBy('warehouse_id')->get()->keyBy('warehouse_id');

        return Inertia::render('Modules/Finance/Accounting/Warehouses', [
            'warehouses' => $rows->map(fn (FinWarehouse $w) => [
                'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'address' => $w->address,
                'is_default' => $w->is_default, 'is_active' => $w->is_active,
                'lines' => (int) ($stats[$w->id]->lines ?? 0),
                'units' => (float) ($stats[$w->id]->units ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cid = $this->currentCompany->id();
        $data = $request->validate([
            'code'    => 'required|string|max:40',
            'name'    => 'required|string|max:150',
            'address' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ]);

        $first = ! FinWarehouse::query()->when($cid, fn ($q) => $q->where('company_id', $cid))->exists();
        $warehouse = FinWarehouse::create($data + ['company_id' => $cid, 'is_active' => true]);
        if ($first || ! empty($data['is_default'])) {
            $this->makeDefault($warehouse);
        }

        return back()->with('success', 'Warehouse created.');
    }

    public function update(Request $request, FinWarehouse $warehouse): RedirectResponse
    {
        $data = $request->validate([
            'code'    => 'required|string|max:40',
            'name'    => 'required|string|max:150',
            'address' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);
        // Don't let the default be deactivated (it must always exist).
        if ($warehouse->is_default) {
            $data['is_active'] = true;
        }
        $warehouse->update($data);

        return back()->with('success', 'Warehouse updated.');
    }

    public function setDefault(FinWarehouse $warehouse): RedirectResponse
    {
        $this->makeDefault($warehouse);

        return back()->with('success', "{$warehouse->name} is now the default warehouse.");
    }

    public function destroy(FinWarehouse $warehouse): RedirectResponse
    {
        if ($warehouse->is_default) {
            return back()->withErrors(['warehouse' => 'The default warehouse cannot be deleted. Make another warehouse the default first.']);
        }
        if (FinStockBalance::where('warehouse_id', $warehouse->id)->where('qty', '<>', 0)->exists()) {
            return back()->withErrors(['warehouse' => 'This warehouse still holds stock. Transfer it out first.']);
        }
        FinStockBalance::where('warehouse_id', $warehouse->id)->delete();
        $warehouse->delete();

        return back()->with('success', 'Warehouse deleted.');
    }

    private function makeDefault(FinWarehouse $warehouse): void
    {
        FinWarehouse::where('company_id', $warehouse->company_id)->where('id', '!=', $warehouse->id)->update(['is_default' => false]);
        $warehouse->update(['is_default' => true, 'is_active' => true]);
    }
}
