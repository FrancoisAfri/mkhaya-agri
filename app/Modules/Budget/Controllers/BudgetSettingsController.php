<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\BudgetCategory;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\FinancialYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetSettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize('update', Budget::class);

        return Inertia::render('Modules/Budget/Settings/Index', [
            'financialYears' => FinancialYear::with('periods')->orderByDesc('start_date')->get(),
            'categories'     => BudgetCategory::orderBy('name')->get(),
        ]);
    }

    // ── Financial years ──────────────────────────────────────────────────────────

    public function yearStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Budget::class);
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after:start_date'],
            'is_current' => ['nullable', 'boolean'],
            'variance_threshold_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if ($data['is_current'] ?? false) {
            FinancialYear::query()->update(['is_current' => false]);
        }
        // A blank threshold means "use the default", which is the column's default, not null.
        if (($data['variance_threshold_pct'] ?? null) === null) {
            unset($data['variance_threshold_pct']);
        }
        FinancialYear::create($data);

        return back()->with('success', 'Financial year added.');
    }

    public function yearUpdate(Request $request, FinancialYear $financialYear): RedirectResponse
    {
        $this->authorize('update', Budget::class);
        $data = $request->validate([
            'name'       => ['sometimes', 'required', 'string', 'max:255'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date'   => ['sometimes', 'required', 'date'],
            'status'     => ['nullable', 'in:active,closed'],
            'is_current' => ['nullable', 'boolean'],
            'variance_threshold_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if ($data['is_current'] ?? false) {
            FinancialYear::query()->update(['is_current' => false]);
        }
        if (array_key_exists('variance_threshold_pct', $data) && $data['variance_threshold_pct'] === null) {
            unset($data['variance_threshold_pct']);
        }
        $financialYear->update($data);

        return back()->with('success', 'Financial year updated.');
    }

    public function yearDestroy(FinancialYear $financialYear): RedirectResponse
    {
        $this->authorize('delete', Budget::class);
        $financialYear->delete();

        return back()->with('success', 'Financial year removed.');
    }

    // ── Periods ──────────────────────────────────────────────────────────────────

    public function periodStore(Request $request, FinancialYear $financialYear): RedirectResponse
    {
        $this->authorize('update', Budget::class);
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after:start_date'],
        ]);
        $financialYear->periods()->create($data);

        return back()->with('success', 'Period added.');
    }

    /**
     * Cut a financial year into calendar months.
     *
     * Phasing needs periods to exist, and creating twelve of them by hand — twelve names, twenty-
     * four dates — is the sort of setup task people abandon halfway through and then wonder why the
     * phasing table is short. Existing periods are left alone rather than replaced: somebody may
     * have deliberately defined quarters, and overwriting that would silently discard their plan.
     */
    public function periodGenerate(Request $request, FinancialYear $financialYear): RedirectResponse
    {
        $this->authorize('update', Budget::class);

        $data = $request->validate(['granularity' => ['nullable', 'in:month,quarter']]);
        $step = ($data['granularity'] ?? 'month') === 'quarter' ? 3 : 1;

        $cursor = $financialYear->start_date?->copy()->startOfDay();
        $end = $financialYear->end_date?->copy()->endOfDay();

        if (! $cursor || ! $end) {
            return back()->withErrors(['financial_year' => __('budget_management.planning.year_needs_dates')]);
        }

        $created = 0;
        $index = 0;

        while ($cursor->lessThan($end)) {
            $index++;
            $close = $cursor->copy()->addMonths($step)->subDay()->endOfDay();

            if ($close->greaterThan($end)) {
                $close = $end->copy();
            }

            $exists = BudgetPeriod::where('financial_year_id', $financialYear->id)
                ->whereDate('start_date', $cursor->toDateString())
                ->exists();

            if (! $exists) {
                $financialYear->periods()->create([
                    'name'       => $step === 3
                        ? __('budget_management.planning.quarter_label', ['n' => $index, 'year' => $cursor->format('Y')])
                        : $cursor->format('M Y'),
                    'start_date' => $cursor->toDateString(),
                    'end_date'   => $close->toDateString(),
                ]);
                $created++;
            }

            $cursor = $cursor->copy()->addMonths($step);
        }

        return back()->with('success', __('budget_management.flash.periods_generated', ['count' => $created]));
    }

    public function periodDestroy(BudgetPeriod $period): RedirectResponse
    {
        $this->authorize('update', Budget::class);
        $period->delete();

        return back()->with('success', 'Period removed.');
    }

    // ── Categories ───────────────────────────────────────────────────────────────

    public function categoryStore(Request $request): RedirectResponse
    {
        $this->authorize('update', Budget::class);
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:255'],
            'type'   => ['nullable', 'in:capex,opex'],
            'status' => ['nullable', 'boolean'],
        ]);
        BudgetCategory::create($data);

        return back()->with('success', 'Category added.');
    }

    public function categoryUpdate(Request $request, BudgetCategory $category): RedirectResponse
    {
        $this->authorize('update', Budget::class);
        $data = $request->validate([
            'name'   => ['sometimes', 'required', 'string', 'max:255'],
            'type'   => ['nullable', 'in:capex,opex'],
            'status' => ['nullable', 'boolean'],
        ]);
        $category->update($data);

        return back()->with('success', 'Category updated.');
    }

    public function categoryDestroy(BudgetCategory $category): RedirectResponse
    {
        $this->authorize('delete', Budget::class);
        $category->delete();

        return back()->with('success', 'Category removed.');
    }
}
