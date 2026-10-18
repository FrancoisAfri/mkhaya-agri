<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage the CRM Products & Services catalog.
 */
class CrmProductController extends Controller
{
    public function index(): Response
    {
        $products = CrmProduct::orderBy('name')
            ->get()
            ->map(fn (CrmProduct $p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'type'        => $p->type,
                'code'        => $p->code,
                'description' => $p->description,
                'price'       => $p->price,
                'currency'    => $p->currency,
                'is_active'   => $p->is_active,
            ]);

        return Inertia::render('Modules/CRM/Products', [
            'products' => $products,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        CrmProduct::create($this->validated($request) + ['created_by' => Auth::id()]);

        return back()->with('success', 'Product / service added.');
    }

    public function update(Request $request, CrmProduct $product): RedirectResponse
    {
        $product->update($this->validated($request));

        return back()->with('success', 'Product / service updated.');
    }

    public function destroy(CrmProduct $product): RedirectResponse
    {
        $product->delete();

        return back()->with('success', 'Product / service removed.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'        => 'required|string|max:200',
            'type'        => 'required|in:product,service',
            'code'        => 'nullable|string|max:60',
            'description' => 'nullable|string|max:2000',
            'price'       => 'nullable|numeric|min:0',
            'currency'    => 'nullable|string|max:8',
            'is_active'   => 'boolean',
        ]);
    }
}
