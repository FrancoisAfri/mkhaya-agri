<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmReportService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM BI analytics (mirrors the leave-management Analytics page) — time-series
 * trends and breakdowns rendered as charts. Scoped to the viewer's visibility.
 */
class CrmAnalyticsController extends Controller
{
    public function index(Request $request, CrmVisibilityService $visibility): Response
    {
        $svc = (new CrmReportService())->forOwners($visibility->visibleOwnerIds());

        return Inertia::render('Modules/CRM/Analytics', [
            'summary'          => $svc->summary(),
            'dealsCreated'     => $svc->dealsCreatedTrend(12),
            'wonTrend'         => $svc->wonTrend(12),
            'leadsTrend'       => $svc->leadsTrend(12),
            'funnel'           => $svc->funnel(),
            'leadSourceMix'    => $svc->leadSourceMix(),
            'activityVolume'   => $svc->activityVolume(),
            'callsEmailsTrend' => $svc->callsEmailsTrend(12),
            'winLoss'          => $svc->winLoss(),
            'repMonthly'       => $svc->repMonthlyPerformance(),
            'repWeekly'        => $svc->repWeeklyPerformance(12),
            'narrative'        => Session::get('analytics_narrative'),
            'aiEnabled'        => app(CrmAiService::class)->isConfigured(),
        ]);
    }

    /** Generate a written executive narrative for the analytics figures. */
    public function narrate(Request $request, CrmVisibilityService $visibility, CrmAiService $ai): RedirectResponse
    {
        $svc = (new CrmReportService())->forOwners($visibility->visibleOwnerIds());

        $result = $ai->narrateReport('CRM Analytics', [
            'summary'        => $svc->summary(),
            'won_trend'      => $svc->wonTrend(6),
            'leads_trend'    => $svc->leadsTrend(6),
            'win_loss'       => $svc->winLoss(),
            'lead_sources'   => $svc->leadSourceMix(),
            'activity'       => $svc->activityVolume(),
        ]);

        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Narration failed.');
        }

        return back()->with('analytics_narrative', $result['narrative']);
    }
}
