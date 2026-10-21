<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinItem;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinPriceList;
use App\Modules\Finance\Accounting\Services\PriceListService;
use Illuminate\Http\JsonResponse;
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
                    'party_id' => $p->party_id, 'currency' => $p->currency,
                    'valid_from' => $p->valid_from?->toDateString(), 'valid_to' => $p->valid_to?->toDateString(),
                    'prices' => $p->items->whereNotNull('item_id')->mapWithKeys(fn ($i) => [$i->item_id => (float) $i->price]),
                    // Products priced by name, grade and unit rather than as a catalogue item.
                    'products' => $p->items->whereNull('item_id')->map(fn ($i) => ['description' => $i->description, 'grade' => $i->grade,
                        'unit' => $i->unit, 'price' => (float) $i->price])->values(),
                    'count' => $p->items->count(),
                ]),
            'customers' => \App\Modules\Finance\Accounting\Models\FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['value' => $c->id, 'title' => $c->name]),
            'items' => FinItem::active()->orderBy('code')->get(['id', 'code', 'name', 'sales_price'])
                ->map(fn ($i) => ['id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'default' => (float) $i->sales_price]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'party_id' => 'nullable|integer|exists:fin_parties,id',
            'currency' => 'nullable|string|size:3', 'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from']);
        FinPriceList::create($data);

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
            'products'        => 'nullable|array',
            'products.*.description' => 'required|string|max:150',
            'products.*.grade' => 'nullable|string|max:40',
            'products.*.unit' => 'nullable|string|max:20',
            'products.*.price' => 'required|numeric|min:0',
            'party_id'        => 'nullable|integer|exists:fin_parties,id',
            'currency'        => 'nullable|string|size:3',
            'valid_from'      => 'nullable|date',
            'valid_to'        => 'nullable|date|after_or_equal:valid_from',
        ]);

        DB::transaction(function () use ($priceList, $data) {
            $priceList->update(['name' => $data['name'], 'is_active' => $data['is_active'] ?? true]
                + array_intersect_key($data, array_flip(['party_id', 'currency', 'valid_from', 'valid_to'])));
            $priceList->items()->delete();
            foreach ($data['prices'] ?? [] as $p) {
                $priceList->items()->create(['item_id' => (int) $p['item_id'], 'price' => round((float) $p['price'], 4)]);
            }
            foreach ($data['products'] ?? [] as $p) {
                $priceList->items()->create(['description' => $p['description'], 'grade' => $p['grade'] ?? null, 'unit' => $p['unit'] ?? null,
                    'price' => round((float) $p['price'], 4)]);
            }
        });

        return back()->with('success', 'Price list saved.');
    }

    /** The price a customer pays for an item or product on a day, and which list it came from. */
    public function price(Request $request, PriceListService $prices): JsonResponse
    {
        $data = $request->validate(['party_id' => 'nullable|integer', 'item_id' => 'nullable|integer', 'description' => 'nullable|string|max:150',
            'grade' => 'nullable|string|max:40', 'unit' => 'nullable|string|max:20', 'on' => 'nullable|date']);
        $party = ! empty($data['party_id']) ? FinParty::query()->find($data['party_id']) : null;

        return response()->json($prices->priceFor($party, isset($data['item_id']) ? (int) $data['item_id'] : null, $data['description'] ?? null,
            $data['grade'] ?? null, $data['unit'] ?? null, $data['on'] ?? null));
    }
}
