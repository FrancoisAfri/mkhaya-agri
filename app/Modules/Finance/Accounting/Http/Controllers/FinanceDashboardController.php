<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinDashboardPref;
use App\Modules\Finance\Accounting\Services\DashboardService;
use App\Modules\Finance\Accounting\Services\FinanceAnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FinanceDashboardController extends Controller
{
    public function __construct(private DashboardService $dashboard, private FinanceAnalyticsService $analytics)
    {
    }

    public function index(): Response
    {
        $widgets = collect($this->dashboard->widgets());
        $pref = FinDashboardPref::firstWhere('user_id', Auth::id());
        $order = $pref?->order ?: [];
        $hidden = $pref?->hidden ?: [];

        // Apply the user's saved order (unknown/new widgets fall to the end), then split.
        $rank = array_flip($order);
        $sorted = $widgets->sortBy(fn ($w) => $rank[$w['key']] ?? 999)->values();

        $a = $this->analytics->analytics(Carbon::now()->startOfYear()->toDateString(), Carbon::now()->endOfMonth()->toDateString());

        return Inertia::render('Modules/Finance/Accounting/Dashboard', [
            'widgets' => $sorted->map(fn ($w) => $w + ['hidden' => in_array($w['key'], $hidden, true)])->values(),
            'charts'  => [
                'monthly'         => $a['monthly'],
                'expenses'        => array_slice($a['expenses_by_category'], 0, 6),
                'income_accounts' => array_slice($a['income_by_account'], 0, 6),
                'ar_ap'           => $a['ar_ap'],
            ],
        ]);
    }

    public function saveLayout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order'    => 'array',
            'order.*'  => 'string',
            'hidden'   => 'array',
            'hidden.*' => 'string',
        ]);

        FinDashboardPref::updateOrCreate(
            ['user_id' => Auth::id()],
            ['order' => $data['order'] ?? [], 'hidden' => $data['hidden'] ?? []],
        );

        return back()->with('success', 'Dashboard layout saved.');
    }
}
