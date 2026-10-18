<?php

namespace App\Modules\HR\Offboarding\Http\Controllers;

use App\Modules\HR\Offboarding\Models\OffboardingExitInterview;
use App\Modules\HR\Offboarding\Models\OffboardingPlan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OffboardingAnalyticsController
{
    public function index(): Response
    {
        // Termination type breakdown
        $byType = OffboardingPlan::select('termination_type', DB::raw('count(*) as total'))
            ->groupBy('termination_type')
            ->get()
            ->map(fn ($r) => ['label' => $r->termination_type, 'value' => (int) $r->total]);

        // Voluntary vs involuntary
        $voluntary   = OffboardingPlan::whereIn('termination_type', ['resignation', 'retirement'])->count();
        $involuntary = OffboardingPlan::whereIn('termination_type', ['retrenchment', 'dismissal', 'contract_end', 'death'])->count();

        // Reasons for leaving (from exit interviews)
        $byReason = OffboardingExitInterview::select('reason_for_leaving', DB::raw('count(*) as total'))
            ->groupBy('reason_for_leaving')
            ->get()
            ->map(fn ($r) => ['label' => $r->reason_for_leaving, 'value' => (int) $r->total]);

        // Average satisfaction score by month (last 12 months)
        $satisfaction = OffboardingExitInterview::select(
                DB::raw("to_char(created_at, 'YYYY-MM') as month"),
                DB::raw('round(avg(satisfaction_score), 1) as avg_score'),
                DB::raw('count(*) as count')
            )
            ->whereNotNull('satisfaction_score')
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy(DB::raw("to_char(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'avg' => (float) $r->avg_score, 'count' => (int) $r->count]);

        // Monthly offboarding volume (last 12 months)
        $monthlyVolume = OffboardingPlan::select(
                DB::raw("to_char(created_at, 'YYYY-MM') as month"),
                DB::raw('count(*) as total')
            )
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy(DB::raw("to_char(created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => ['month' => $r->month, 'total' => (int) $r->total]);

        // Rehire eligibility
        $rehireYes = OffboardingPlan::where('rehire_eligible', true)->count();
        $rehireNo  = OffboardingPlan::where('rehire_eligible', false)->count();

        // Counteroffer outcomes (resignations only)
        $counterofferAccepted = OffboardingPlan::where('termination_type', 'resignation')
            ->where('counteroffer_outcome', 'accepted')->count();
        $counterofferDeclined = OffboardingPlan::where('termination_type', 'resignation')
            ->where('counteroffer_outcome', 'declined')->count();

        // Would recommend
        $wouldRecommend    = OffboardingExitInterview::where('would_recommend', true)->count();
        $wouldNotRecommend = OffboardingExitInterview::where('would_recommend', false)->count();

        // Summary KPIs
        $totalPlans       = OffboardingPlan::count();
        $completedPlans   = OffboardingPlan::where('status', 'completed')->count();
        $activePlans      = OffboardingPlan::where('status', 'active')->count();
        $avgSatisfaction  = round((float) OffboardingExitInterview::avg('satisfaction_score'), 1);
        $interviewCount   = OffboardingExitInterview::count();

        return Inertia::render('Modules/Offboarding/Analytics', [
            'kpis' => [
                'total_plans'      => $totalPlans,
                'completed_plans'  => $completedPlans,
                'active_plans'     => $activePlans,
                'avg_satisfaction' => $avgSatisfaction,
                'interview_count'  => $interviewCount,
                'voluntary'        => $voluntary,
                'involuntary'      => $involuntary,
            ],
            'by_type'             => $byType,
            'by_reason'           => $byReason,
            'satisfaction_trend'  => $satisfaction,
            'monthly_volume'      => $monthlyVolume,
            'rehire'              => ['eligible' => $rehireYes, 'not_eligible' => $rehireNo],
            'counteroffer'        => ['accepted' => $counterofferAccepted, 'declined' => $counterofferDeclined],
            'would_recommend'     => ['yes' => $wouldRecommend, 'no' => $wouldNotRecommend],
        ]);
    }
}
