<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Services\FinanceAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class FinanceAnalyticsController extends Controller
{
    public function __construct(private FinanceAnalyticsService $analytics)
    {
    }

    public function index(Request $request): Response
    {
        $from = $request->date('from') ?? Carbon::now()->startOfYear();
        $to   = $request->date('to') ?? Carbon::now()->endOfMonth();

        return Inertia::render('Modules/Finance/Accounting/Analytics', [
            'data'  => $this->analytics->analytics($from->toDateString(), $to->toDateString()),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }
}
