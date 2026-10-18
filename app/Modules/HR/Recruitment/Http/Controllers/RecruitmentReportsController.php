<?php

namespace App\Modules\HR\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Recruitment\Models\Application;
use App\Modules\HR\Recruitment\Models\Candidate;
use App\Modules\HR\Recruitment\Models\Job;
use App\Modules\HR\Recruitment\Enums\ApplicationStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RecruitmentReportsController extends Controller
{
    public function index(): Response
    {
        // Funnel conversion: for each consecutive stage pair, calculate conversion rate
        $funnelCounts = Application::selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $stageOrder = [
            ApplicationStatus::Applied->value,
            ApplicationStatus::Screening->value,
            ApplicationStatus::Assessment->value,
            ApplicationStatus::Interview->value,
            ApplicationStatus::Shortlisted->value,
            ApplicationStatus::OfferSent->value,
            ApplicationStatus::Hired->value,
        ];

        $funnelConversion = [];
        for ($i = 0; $i < count($stageOrder) - 1; $i++) {
            $fromStatus = $stageOrder[$i];
            $toStatus   = $stageOrder[$i + 1];
            $fromCount  = (int) ($funnelCounts->get($fromStatus, 0));
            // toCount includes all stages from toStatus onwards
            $toCount = collect($stageOrder)->slice($i + 1)
                ->sum(fn($s) => (int) ($funnelCounts->get($s, 0)));

            $conversionRate = $fromCount > 0 ? round(($toCount / $fromCount) * 100, 1) : 0;

            $fromEnum = ApplicationStatus::from($fromStatus);
            $toEnum   = ApplicationStatus::from($toStatus);

            $funnelConversion[] = [
                'from_stage'      => $fromEnum->label(),
                'to_stage'        => $toEnum->label(),
                'conversion_rate' => $conversionRate,
                'from_count'      => $fromCount,
                'to_count'        => $toCount,
            ];
        }

        // Time to fill: days between job published/created_at and when application moved to Hired
        // Use a simple proxy: avg days from Application.applied_at to Application.updated_at for Hired apps
        $hiredApps = Application::where('status', ApplicationStatus::Hired->value)
            ->selectRaw('EXTRACT(EPOCH FROM (updated_at - applied_at)) / 86400 as days_to_fill, job_id')
            ->with('job:id,title')
            ->get();

        $avgDays    = $hiredApps->avg('days_to_fill');
        $medianDays = null;
        if ($hiredApps->count() > 0) {
            $sorted     = $hiredApps->pluck('days_to_fill')->sort()->values();
            $mid        = (int) floor($sorted->count() / 2);
            $medianDays = $sorted->count() % 2 === 0
                ? round(($sorted[$mid - 1] + $sorted[$mid]) / 2, 1)
                : round($sorted[$mid], 1);
        }

        // Department breakdown — group by job's department (use job title as proxy if no dept)
        $byDepartment = $hiredApps->groupBy(fn($a) => $a->job?->title ?? 'Unknown')
            ->map(fn($group) => round($group->avg('days_to_fill'), 1))
            ->map(fn($avg, $dept) => ['department' => $dept, 'avg_days' => $avg])
            ->values();

        $timeToFill = [
            'average_days'  => $avgDays ? round($avgDays, 1) : null,
            'median_days'   => $medianDays,
            'by_department' => $byDepartment,
        ];

        // Source effectiveness
        $sourceStats = Application::selectRaw('source, count(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as hired', [ApplicationStatus::Hired->value])
            ->groupBy('source')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'source'    => $r->source,
                'total'     => (int) $r->total,
                'hired'     => (int) $r->hired,
                'hire_rate' => $r->total > 0 ? round(($r->hired / $r->total) * 100, 1) : 0,
            ]);

        // Monthly hires: last 12 months
        $monthlyHires = Application::where('status', ApplicationStatus::Hired->value)
            ->where('updated_at', '>=', now()->subMonths(12)->startOfMonth())
            ->selectRaw("TO_CHAR(updated_at, 'Mon YYYY') as month, DATE_TRUNC('month', updated_at) as month_start, count(*) as count")
            ->groupByRaw("month_start, month")
            ->orderBy('month_start')
            ->get()
            ->map(fn($r) => ['month' => $r->month, 'count' => (int) $r->count]);

        return Inertia::render('Modules/Recruitment/Reports/Index', [
            'funnelConversion'    => $funnelConversion,
            'timeToFill'          => $timeToFill,
            'sourceEffectiveness' => $sourceStats,
            'monthlyHires'        => $monthlyHires,
        ]);
    }
}
