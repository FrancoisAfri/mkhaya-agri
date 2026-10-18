<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierIndustryType;
use App\Modules\SupplierManagement\Models\SupplierProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierSearchController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        return Inertia::render('Modules/SupplierManagement/Search/Index', [
            'industries' => $this->industryOptions(),
            'products'   => $this->productOptions(),
        ]);
    }

    /**
     * Advanced search — ILIKE keyword filters + status + industry; JSON or Inertia view.
     */
    public function advanced(Request $request): JsonResponse|Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->validate([
            'status'         => 'nullable',
            'company_name'   => 'nullable|string',
            'registration'   => 'nullable|string',
            'email'          => 'nullable|string',
            'product_ids'    => 'nullable|array',
            'product_ids.*'  => 'integer',
            'industry_ids'   => 'nullable|array',
            'industry_ids.*' => 'integer',
        ]);

        $results = Supplier::query()
            ->with(['industries:id,industry_type_name', 'products:id,supplier_id,item_name'])
            ->when(isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null, fn ($q) =>
                $q->where('is_active', $filters['status']))
            ->when(! empty($filters['company_name']), fn ($q) =>
                $q->where('company_name', 'ilike', '%' . $filters['company_name'] . '%'))
            ->when(! empty($filters['registration']), fn ($q) =>
                $q->where('company_registration_number', 'ilike', '%' . $filters['registration'] . '%'))
            ->when(! empty($filters['email']), fn ($q) =>
                $q->where('email', 'ilike', '%' . $filters['email'] . '%'))
            ->when(! empty($filters['industry_ids']), fn ($q) =>
                $q->whereHas('industries', fn ($iq) =>
                    $iq->whereIn('supplier_industry_types.id', $filters['industry_ids'])))
            ->when(! empty($filters['product_ids']), fn ($q) =>
                $q->whereHas('products', fn ($pq) =>
                    $pq->whereIn('supplier_products.id', $filters['product_ids'])))
            ->orderBy('company_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Supplier $s) => [
                'id'           => $s->id,
                // A supplier is addressed by uuid, so the row needs one to link with.
                'uuid'         => $s->uuid,
                'company_name' => $s->company_name,
                'registration' => $s->company_registration_number,
                'email'        => $s->email,
                'phone'        => $s->phone_number,
                'address'      => $s->physical_address,
                'status'       => $s->is_active,
                'industries'   => $s->industries->pluck('industry_type_name')->values(),
                'products'     => $s->products->pluck('item_name')->values(),
            ]);

        if ($request->wantsJson()) {
            return response()->json(['results' => $results]);
        }

        return Inertia::render('Modules/SupplierManagement/Search/Index', [
            'industries' => $this->industryOptions(),
            'products'   => $this->productOptions(),
            'results'    => $results,
            'filters'    => $filters,
        ]);
    }

    private function industryOptions()
    {
        return SupplierIndustryType::where('active', true)->get(['id', 'industry_type_name']);
    }

    private function productOptions()
    {
        return SupplierProduct::where('active', true)
            ->get(['id', 'item_name'])->unique('item_name')->values();
    }
}
