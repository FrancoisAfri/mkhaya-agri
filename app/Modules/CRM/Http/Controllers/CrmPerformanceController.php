<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Services\CrmPerformanceService;
use App\Modules\CRM\Services\CrmReportService;
use App\Modules\CRM\Services\CrmTeamService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sales performance dashboards — personal (rep), team (manager) and executive
 * (org-wide). Each measures actuals against targets via CrmPerformanceService.
 */
class CrmPerformanceController extends Controller
{
    public function __construct(
        private CrmPerformanceService $perf,
        private CrmTeamService $teams,
    ) {
    }

    /** "My Dashboard" — the signed-in rep's stats vs both weekly & monthly goals. */
    public function me(): Response
    {
        $userId = (int) Auth::id();

        return Inertia::render('Modules/CRM/MyDashboard', [
            'scorecard'  => $this->perf->dualScorecard($userId),
            'tasks'      => $this->openTasks($userId),
            'repMonthly' => $this->report([$userId])->repMonthlyPerformance(),
            'repWeekly'  => $this->report([$userId])->repWeeklyPerformance(12),
        ]);
    }

    /** A report service scoped to the given owner ids (null = all). */
    private function report(?array $owners): CrmReportService
    {
        return (new CrmReportService())->forOwners($owners);
    }

    /** Team dashboard — a manager's direct reports vs their targets. */
    public function team(Request $request): Response
    {
        $period  = $request->query('period', 'monthly');
        $manager = (int) Auth::id();

        $members = $this->teams->teamOf($manager);
        $rows    = array_map(fn ($m) => $this->memberSummary($m, $period), $members);

        $ownerIds = $members ? array_column($members, 'id') : [$manager];

        return Inertia::render('Modules/CRM/TeamDashboard', [
            'period'     => $period,
            'members'    => $rows,
            'totals'     => $this->totals($rows),
            'repMonthly' => $this->report($ownerIds)->repMonthlyPerformance(),
            'repWeekly'  => $this->report($ownerIds)->repWeeklyPerformance(12),
        ]);
    }

    /** Executive dashboard — whole sales force + org finances. */
    public function executive(Request $request): Response
    {
        $period = $request->query('period', 'monthly');
        [$from, $to] = $this->perf->window($period);

        $force = $this->teams->salesForce();
        $rows  = array_map(fn ($m) => $this->memberSummary($m, $period), $force);

        // Org-wide deal/lead figures for the period.
        $wonValue   = (float) CrmDeal::where('status', 'won')->whereBetween('closed_at', [$from, $to])->sum('amount');
        $wonCount   = CrmDeal::where('status', 'won')->whereBetween('closed_at', [$from, $to])->count();
        $lostCount  = CrmDeal::where('status', 'lost')->whereBetween('closed_at', [$from, $to])->count();
        $openValue  = (float) CrmDeal::where('status', 'open')->sum('amount');
        $openCount  = CrmDeal::where('status', 'open')->count();
        $weighted   = (float) CrmDeal::where('status', 'open')->sum(DB::raw('amount * probability / 100'));
        $newLeads   = CrmLead::whereBetween('created_at', [$from, $to])->count();
        $convLeads  = CrmLead::where('status', 'converted')->whereBetween('updated_at', [$from, $to])->count();

        return Inertia::render('Modules/CRM/ExecutiveDashboard', [
            'period'  => $period,
            'members' => $rows,
            'totals'  => $this->totals($rows),
            'org'     => [
                'won_value'       => $wonValue,
                'won_count'       => $wonCount,
                'lost_count'      => $lostCount,
                'win_rate'        => ($wonCount + $lostCount) > 0 ? round($wonCount / ($wonCount + $lostCount) * 100) : 0,
                'open_value'      => $openValue,
                'open_count'      => $openCount,
                'forecast'        => round($weighted, 2),
                'new_leads'       => $newLeads,
                'converted_leads' => $convLeads,
                'lead_conv_rate'  => $newLeads > 0 ? round($convLeads / max($newLeads, 1) * 100) : 0,
            ],
            'leaderboard' => $this->leaderboard($rows),
            'repMonthly'  => $this->report(null)->repMonthlyPerformance(),
            'repWeekly'   => $this->report(null)->repWeeklyPerformance(12),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function memberSummary(array $member, string $period): array
    {
        $weekly  = $this->perf->scorecard($member['id'], 'weekly');
        $monthly = $this->perf->scorecard($member['id'], 'monthly');
        $card    = $period === 'weekly' ? $weekly : $monthly;
        $a       = $card['actuals'];

        $att = fn ($c) => collect($c['metrics'])->firstWhere('metric', 'revenue')['percent'] ?? null;

        return [
            'id'        => $member['id'],
            'name'      => $member['name'],
            'metrics'   => $card['metrics'],
            'calls'     => $a['calls'],
            'emails'    => $a['emails'],
            'meetings'  => $a['meetings'],
            'leads'     => $a['leads'],
            'deals_won' => $a['deals_won'],
            'revenue'   => $a['revenue'],
            'open_value'=> $a['open_value'],
            'attainment'        => $att($card),
            'weekly_attainment' => $att($weekly),
            'monthly_attainment'=> $att($monthly),
        ];
    }

    private function totals(array $rows): array
    {
        return [
            'calls'     => array_sum(array_column($rows, 'calls')),
            'emails'    => array_sum(array_column($rows, 'emails')),
            'meetings'  => array_sum(array_column($rows, 'meetings')),
            'leads'     => array_sum(array_column($rows, 'leads')),
            'deals_won' => array_sum(array_column($rows, 'deals_won')),
            'revenue'   => array_sum(array_column($rows, 'revenue')),
            'open_value'=> array_sum(array_column($rows, 'open_value')),
            'reps'      => count($rows),
        ];
    }

    private function leaderboard(array $rows): array
    {
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        return array_slice(array_map(fn ($r) => [
            'name' => $r['name'], 'revenue' => $r['revenue'], 'deals_won' => $r['deals_won'],
            'attainment' => $r['attainment'],
            'weekly_attainment' => $r['weekly_attainment'], 'monthly_attainment' => $r['monthly_attainment'],
        ], $rows), 0, 10);
    }

    private function openTasks(int $userId): array
    {
        return \App\Modules\CRM\Models\CrmActivity::where('owner_id', $userId)
            ->where('status', 'pending')
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->limit(15)
            ->get()
            ->map(fn ($a) => [
                'id'      => $a->id,
                'subject' => $a->subject,
                'type'    => $a->type,
                'due_at'  => $a->due_at?->toDayDateTimeString(),
                'overdue' => $a->due_at && $a->due_at->isPast(),
            ])->all();
    }
}
