<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinExpense;
use App\Modules\Finance\Accounting\Services\ExpenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses)
    {
    }

    public function index(): Response
    {
        $expenses = FinExpense::orderByDesc('id')->limit(300)->get()->map(fn (FinExpense $e) => [
            'id' => $e->id, 'category' => $e->category, 'description' => $e->description,
            'amount' => (float) $e->amount, 'tax_amount' => (float) $e->tax_amount, 'total' => (float) $e->total,
            'payment_method' => $e->payment_method, 'status' => $e->status, 'expense_date' => $e->expense_date?->toDateString(),
            'is_mileage' => $e->is_mileage, 'km' => $e->km ? (float) $e->km : null, 'employee_id' => $e->employee_id,
        ]);

        return Inertia::render('Modules/Finance/Accounting/Expenses', [
            'expenses' => $expenses,
            'methods'  => FinExpense::METHODS,
            'summary'  => [
                'pending' => $expenses->where('status', 'draft')->count(),
                'pending_value' => round($expenses->where('status', 'draft')->sum('total'), 2),
                'posted_value'  => round($expenses->where('status', 'posted')->sum('total'), 2),
            ],
            'accounts' => FinAccount::postable()->where('type', 'expense')->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} · {$a->name}"]),
            'employees' => DB::table('hr_people')->where('status', 1)
                ->selectRaw("id, TRIM(CONCAT(first_name,' ',surname)) as name")->orderBy('name')->limit(1000)->get(),
            'mileageRate' => (float) config('finance.mileage_rate', 4.84),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id'        => 'nullable|integer',
            'category'           => 'nullable|string|max:80',
            'description'        => 'nullable|string|max:255',
            'expense_account_id' => 'required|integer|exists:fin_accounts,id',
            'amount'             => 'required_if:is_mileage,false|nullable|numeric|min:0',
            'tax_amount'         => 'nullable|numeric|min:0',
            'payment_method'     => 'required|in:reimbursable,petty_cash,company_card',
            'expense_date'       => 'required|date',
            'is_mileage'         => 'boolean',
            'km'                 => 'required_if:is_mileage,true|nullable|numeric|min:0',
            'rate'               => 'nullable|numeric|min:0',
        ]);

        $this->expenses->create($data);

        return back()->with('success', 'Expense captured.');
    }

    public function approve(FinExpense $expense): RedirectResponse
    {
        try {
            $this->expenses->approve($expense);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['expense' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Expense approved and posted.');
    }

    public function reject(FinExpense $expense): RedirectResponse
    {
        try {
            $this->expenses->reject($expense);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['expense' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Expense rejected.');
    }
}
