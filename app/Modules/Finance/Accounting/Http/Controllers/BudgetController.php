<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBudget;
use App\Modules\Finance\Accounting\Services\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $budgets)
    {
    }

    public function index(Request $request): Response
    {
        $budgets = FinBudget::withCount('lines')->orderByDesc('fiscal_year')->orderBy('name')->get()
            ->map(fn (FinBudget $b) => [
                'id' => $b->id, 'name' => $b->name, 'fiscal_year' => $b->fiscal_year,
                'period_type' => $b->period_type, 'status' => $b->status, 'lines_count' => $b->lines_count,
            ]);

        $selected = $request->integer('budget') ?: ($budgets[0]['id'] ?? null);
        $variance = null;
        $detail = null;
        if ($selected && ($budget = FinBudget::with('lines')->find($selected))) {
            $variance = $this->budgets->variance($budget, $request->integer('through') ?: null);
            $detail = [
                'id' => $budget->id, 'name' => $budget->name, 'fiscal_year' => $budget->fiscal_year,
                'status' => $budget->status, 'period_type' => $budget->period_type, 'notes' => $budget->notes,
                'lines' => $budget->lines->map(fn ($l) => ['account_id' => $l->account_id, 'period_no' => $l->period_no, 'amount' => (float) $l->amount, 'dimension' => $l->dimension]),
            ];
        }

        return Inertia::render('Modules/Finance/Accounting/Budgets', [
            'budgets'  => $budgets,
            'selected' => $selected,
            'detail'   => $detail,
            'variance' => $variance,
            'through'  => $request->integer('through') ?: 12,
            'accounts' => FinAccount::postable()->whereIn('type', ['income', 'expense'])->orderBy('code')
                ->get(['id', 'code', 'name', 'type'])->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'label' => "{$a->code} · {$a->name}", 'type' => $a->type]),
        ]);
    }

    /** Full-page Sage-style editor: every income / cost-of-sales / expense account × 12 months. */
    public function create(Request $request): Response
    {
        return Inertia::render('Modules/Finance/Accounting/BudgetEditor', [
            'budget'   => null,
            'accounts' => $this->sectionedAccounts(),
            'grid'     => (object) [],
            'year'     => (int) ($request->integer('year') ?: now()->year),
        ]);
    }

    public function edit(FinBudget $budget): Response
    {
        // grid[account_id][period_no] = amount
        $grid = [];
        foreach ($budget->lines as $l) {
            $grid[$l->account_id][$l->period_no] = (float) $l->amount;
        }

        return Inertia::render('Modules/Finance/Accounting/BudgetEditor', [
            'budget'   => ['id' => $budget->id, 'name' => $budget->name, 'fiscal_year' => $budget->fiscal_year, 'status' => $budget->status],
            'accounts' => $this->sectionedAccounts(),
            'grid'     => $grid ?: (object) [],
            'year'     => $budget->fiscal_year,
        ]);
    }

    /** Income / cost-of-sales / other-income / operating-expense accounts, sectioned like Sage. */
    private function sectionedAccounts()
    {
        return FinAccount::postable()->whereIn('type', ['income', 'expense'])->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(function ($a) {
                $section = match (true) {
                    $a->type === 'income' && ($a->code === '4900' || stripos($a->name, 'other') !== false) => 'other_income',
                    $a->type === 'income'                                        => 'income',
                    $a->type === 'expense' && str_starts_with((string) $a->code, '5') => 'cost_of_sales',
                    default                                                      => 'expenses',
                };

                return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'section' => $section];
            })->values();
    }

    public function store(Request $request): RedirectResponse
    {
        $budget = $this->budgets->save($this->rules($request));

        return $request->boolean('_editor')
            ? redirect()->route('finance.accounting.budgets', ['budget' => $budget->id])->with('success', 'Budget saved.')
            : back()->with('success', 'Budget saved.');
    }

    public function update(Request $request, FinBudget $budget): RedirectResponse
    {
        if (! $budget->isEditable()) {
            return back()->with('error', 'A locked budget cannot be edited.');
        }
        $this->budgets->save($this->rules($request), $budget);

        return $request->boolean('_editor')
            ? redirect()->route('finance.accounting.budgets', ['budget' => $budget->id])->with('success', 'Budget updated.')
            : back()->with('success', 'Budget updated.');
    }

    public function status(Request $request, FinBudget $budget): RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:draft,approved,locked']);
        $this->budgets->setStatus($budget, $data['status']);

        return back()->with('success', "Budget {$data['status']}.");
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'name'               => 'required|string|max:150',
            'fiscal_year'        => 'required|integer|min:2000|max:2100',
            'period_type'        => 'required|in:monthly,annual',
            'notes'              => 'nullable|string|max:1000',
            'lines'              => 'array',
            'lines.*.account_id' => 'required|integer|exists:fin_accounts,id',
            'lines.*.period_no'  => 'nullable|integer|min:0|max:12',
            'lines.*.amount'     => 'nullable|numeric',
            'lines.*.dimension'  => 'nullable|string|max:60',
        ]);
    }
}
