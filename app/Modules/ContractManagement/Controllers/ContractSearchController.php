<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractCategory;
use App\Modules\ContractManagement\Models\ContractType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractSearchController extends Controller
{
    /**
     * Keyword search across contracts with optional structured filters.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contract::class);

        $filters = $request->validate([
            'q' => 'nullable|string',
            'status' => 'nullable|string',
            'type' => 'nullable|integer',
            'category' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
        ]);

        $results = Contract::query()
            ->with(['type:id,name', 'category:id,name'])
            ->when(! empty($filters['q']), function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($q) use ($term) {
                    $q->where('contract_number', 'ilike', $term)
                        ->orWhere('title', 'ilike', $term)
                        ->orWhere('description', 'ilike', $term);
                });
            })
            ->when(! empty($filters['status']), fn ($q) => $q->where('contract_status', $filters['status']))
            ->when(! empty($filters['type']), fn ($q) => $q->where('contract_type_id', $filters['type']))
            ->when(! empty($filters['category']), fn ($q) => $q->where('contract_category_id', $filters['category']))
            ->when(! empty($filters['supplier_id']), fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Contract $c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'title' => $c->title,
                'contract_status' => $c->contract_status,
                'approval_status' => $c->approval_status,
                'contract_value' => $c->contract_value,
                'start_date' => $c->start_date?->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'type' => $c->type?->name,
                'category' => $c->category?->name,
            ]);

        return Inertia::render('Modules/ContractManagement/Search/Index', [
            'results' => $results,
            'filters' => $filters,
            'types' => ContractType::where('status', true)->get(['id', 'name']),
            'categories' => ContractCategory::where('status', true)->get(['id', 'name']),
        ]);
    }
}
