<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Models\ContractClause;
use App\Modules\ContractManagement\Models\ContractClauseAnalysis;
use App\Modules\ContractManagement\Services\ContractClauseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ContractClauseController extends Controller
{
    public function __construct(private readonly ContractClauseService $clauses) {}

    private const CATEGORIES = [
        'termination', 'liability', 'payment', 'confidentiality',
        'indemnity', 'ip', 'data_protection', 'other',
    ];

    /** Clause library list. */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Contract::class);

        $clauses = ContractClause::query()
            ->when($request->string('search')->toString(), function ($q, $search) {
                $q->where('name', 'ilike', "%{$search}%");
            })
            ->when($request->string('category')->toString(), function ($q, $category) {
                $q->where('category', $category);
            })
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Modules/ContractManagement/Clauses/Index', [
            'clauses' => $clauses,
            'categories' => self::CATEGORIES,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'category' => $request->string('category')->toString(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        ContractClause::create($this->validated($request));

        return back()->with('success', 'Clause created.');
    }

    public function update(Request $request, ContractClause $clause): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $clause->update($this->validated($request, true));

        return back()->with('success', 'Clause updated.');
    }

    public function destroy(ContractClause $clause): RedirectResponse
    {
        $this->authorize('delete', Contract::class);

        $clause->delete();

        return back()->with('success', 'Clause deleted.');
    }

    /** Run the playbook-deviation analysis for a contract. */
    public function analyze(Contract $contract): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $result = $this->clauses->analyze($contract, Auth::id());

        $redirect = redirect()->route('contracts.deviation', $contract->id);

        if ($result->status === 'failed') {
            return $redirect->withErrors(['clauses' => $result->error ?: 'Clause analysis failed.']);
        }

        return $redirect->with('success', 'Clause analysis complete.');
    }

    /** Show the deviation report for a contract. */
    public function deviation(Contract $contract): Response
    {
        $this->authorize('view', Contract::class);

        return Inertia::render('Modules/ContractManagement/Clauses/Deviation', [
            'contract' => [
                'id' => $contract->id, 'uuid' => $contract->uuid,
                'contract_number' => $contract->contract_number,
                'title' => $contract->title,
            ],
            'analysis' => ContractClauseAnalysis::where('contract_id', $contract->id)->first(),
        ]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];

        return $request->validate([
            'name' => [...$required, 'string', 'max:255'],
            'category' => [...$required, 'string', 'in:'.implode(',', self::CATEGORIES)],
            'standard_text' => [...$required, 'string'],
            'fallback_text' => ['nullable', 'string'],
            'is_required' => ['nullable', 'boolean'],
            'risk_guidance' => ['nullable', 'string'],
            'status' => ['nullable', 'boolean'],
        ]);
    }
}
