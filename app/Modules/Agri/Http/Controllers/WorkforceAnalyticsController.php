<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The farm's labour analytics (Insights): attendance, hours and overtime against output, cost per
 * unit by crop, certification gaps and the work coming — from Time & Attendance's approved records.
 */
class WorkforceAnalyticsController extends AgriController
{
    /** §1.7: attendance, hours, overtime, output, cost per unit, certification gaps and coming demand. */
    public function analytics(Request $request, \App\Modules\Agri\Services\WorkforceAnalytics $analytics): Response
    {
        $this->allow($request, R::VIEW_WORKFORCE);
        $farm = $this->farmFilter($request);
        $from = \Illuminate\Support\Carbon::parse($request->query('from', today()->subDays(27)->toDateString()));
        $to = \Illuminate\Support\Carbon::parse($request->query('to', today()->toDateString()));
        abort_if($to->lt($from) || $from->diffInDays($to) > 400, 422, 'Choose a period of up to about a year.');

        return Inertia::render('Modules/Agri/Workforce/Analytics', [
            'report' => $analytics->report($farm, $from, $to),
            'filters' => ['farm' => $farm?->uuid, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'farms' => $this->farmOptions(true),
            'minPeers' => \App\Modules\Agri\Services\WorkforceAnalytics::MIN_PEERS,
        ]);
    }
}
