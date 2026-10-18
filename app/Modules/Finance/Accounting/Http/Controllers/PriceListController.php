<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinPriceList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PriceListController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/PriceLists', [
            'priceLists' => FinPriceList::with('items')->orderBy('name')->get()
                ->map(fn (FinPriceList $p) => [
                    'id' => $p->id, 'name' => $p->name, 'is_active' => $p->is_active,
                    'prices' => $p->items->mapWithKeys(fn ($i) => [$i->item_id => (float) $i->price]),
                    'count' => $p->items->count(),
                ]),
            'items' => FinItem::active()->orderBy('code')->get(['id', 'code', 'name', 'sales_price'])
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'default' => (float) $i->sales_price]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        FinPriceList::create(['name' => $data['name']]);

        return back()->with('success', 'Price list created.');
    }

    public function update(Request $request, FinPriceList $priceList): RedirectResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:120',
            'is_active'       => 'boolean',
            'prices'          => 'nullable|array',
            'prices.*.item_id' => 'required|integer|exists:fin_items,id',
            'prices.*.price'  => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($priceList, $data) {
            $priceList->update(['name' => $data['name'], 'is_active' => $data['is_active'] ?? true]);
            $priceList->items()->delete();
            foreach ($data['prices'] ?? [] as $p) {
                $priceList->items()->create(['item_id' => (int) $p['item_id'], 'price' => round((float) $p['price'], 4)]);
            }
        });

        return back()->with('success', 'Price list saved.');
    }
}
