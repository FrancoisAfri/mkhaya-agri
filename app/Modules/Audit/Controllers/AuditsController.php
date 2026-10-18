<?php

namespace App\Modules\Audit\Controllers;

use App\Modules\Audit\Services\GlobalHelperService;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class AuditsController extends Controller
{
    public function index(Request $request)
    {
        // Scope to the active company — a company sees only its own audit trail;
        // platform admins (no active company) see everything.
        $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        $query = Activity::select(
                'activity_log.*',
                'hr_people.first_name as firstname',
                'hr_people.surname as lastname'
            )
            ->leftJoin('hr_people', 'activity_log.causer_id', '=', 'hr_people.user_id')
            ->when($cid, fn ($q) => $q->where('activity_log.company_id', $cid));

        if ($request->filled('log_name')) {
            $query->where('activity_log.log_name', $request->log_name);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        if ($request->filled('user')) {
            $query->where(function ($q) use ($request) {
                $q->where('hr_people.first_name', 'ILIKE', "%{$request->user}%")
                  ->orWhere('hr_people.last_name', 'ILIKE', "%{$request->user}%")
                  ->orWhere('activity_log.causer_id', $request->user);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('activity_log.created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('activity_log.created_at', '<=', $request->date_to);
        }

        $query->orderBy('activity_log.created_at', 'desc');

        $paginated = $query->paginate(20);

        // Chart data — 30-day activity trend
        $chartRaw = Activity::selectRaw("DATE(created_at) as date, COUNT(*) as count")
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->when($cid, fn ($q) => $q->where('company_id', $cid))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $chartData = [
            'labels' => $chartRaw->pluck('date')->map(fn($d) => Carbon::parse($d)->format('d M'))->toArray(),
            'counts' => $chartRaw->pluck('count')->toArray(),
        ];

        // Global stats across all logs
        $statsBase = Activity::query()->when($cid, fn ($q) => $q->where('company_id', $cid));
        if ($request->filled('log_name')) $statsBase->where('log_name', $request->log_name);
        if ($request->filled('date_from')) $statsBase->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $statsBase->whereDate('created_at', '<=', $request->date_to);

        $eventCounts = (clone $statsBase)
            ->selectRaw("event, COUNT(*) as total")
            ->groupBy('event')
            ->pluck('total', 'event');

        $stats = [
            'total'   => (clone $statsBase)->count(),
            'created' => $eventCounts['created'] ?? 0,
            'updated' => $eventCounts['updated'] ?? 0,
            'deleted' => $eventCounts['deleted'] ?? 0,
        ];

        // Available events — scoped to selected module if one is chosen
        $eventsQuery = Activity::query()->whereNotNull('event')->when($cid, fn ($q) => $q->where('company_id', $cid));
        if ($request->filled('log_name')) {
            $eventsQuery->where('log_name', $request->log_name);
        }
        $availableEvents = $eventsQuery
            ->select('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event')
            ->toArray();

        return Inertia::render('Core/Audits/Index', [
            'logs'      => $paginated->items(),
            'meta'      => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
            'chartData'       => $chartData,
            'models'          => GlobalHelperService::getAvailableModels(),
            'availableEvents' => $availableEvents,
            'stats'           => $stats,
            'filters'         => $request->only(['log_name', 'event', 'user', 'date_from', 'date_to']),
        ]);
    }

    public function exportPdf(Request $request)
    {
        $cid = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();
        $query = Activity::select(
                'activity_log.*',
                'hr_people.first_name as firstname',
                'hr_people.surname as lastname'
            )
            ->leftJoin('hr_people', 'activity_log.causer_id', '=', 'hr_people.user_id')
            ->when($cid, fn ($q) => $q->where('activity_log.company_id', $cid));

        if ($request->filled('log_name'))  $query->where('activity_log.log_name', $request->log_name);
        if ($request->filled('event'))     $query->where('event', $request->event);
        if ($request->filled('date_from')) $query->whereDate('activity_log.created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate('activity_log.created_at', '<=', $request->date_to);

        $logs = $query->orderBy('activity_log.created_at', 'desc')->get();

        $pdf = Pdf::loadView('pdf.audits', [
            'logs'      => $logs,
            'date_from' => $request->date_from,
            'date_to'   => $request->date_to,
            'generated' => Carbon::now()->format('d M Y H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download('audit-report-' . Carbon::now()->format('Y-m-d') . '.pdf');
    }
}
