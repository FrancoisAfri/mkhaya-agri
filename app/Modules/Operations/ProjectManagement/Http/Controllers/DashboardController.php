<?php

namespace App\Modules\Operations\ProjectManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Services\ModuleRegistry;
use App\Modules\Operations\ProjectManagement\Services\PmAnalyticsService;
use App\Modules\Operations\ProjectManagement\Support\ProjectPresenter;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The module landing page: portfolio state at a glance, plus the most recent evidence.
 *
 * Counts are aggregated in the database rather than by loading rows — the performance budget is
 * measured against 5,000 tasks, and a dashboard that hydrates models to count them stops meeting
 * it well before that.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly PmAnalyticsService $analytics,
    ) {
    }

    public function index(\Illuminate\Http\Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $byStatus = Project::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $taskCounts = Task::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $recentEvidence = EvidenceEvent::query()
            ->live()
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return Inertia::render('Modules/ProjectManagement/Dashboard', [
            'kpis' => [
                'projects_total'    => (int) $byStatus->sum(),
                'projects_active'   => (int) ($byStatus['active'] ?? 0),
                'projects_on_hold'  => (int) ($byStatus['on_hold'] ?? 0),
                'projects_complete' => (int) ($byStatus['completed'] ?? 0),
                'tasks_open'        => (int) ($taskCounts->sum() - ($taskCounts['done'] ?? 0) - ($taskCounts['cancelled'] ?? 0)),
                'tasks_overdue'     => Task::open()->whereNotNull('planned_end')->where('planned_end', '<', now())->count(),
                // Evidence recorded in the last 7 days: the signal that the field loop is alive.
                'evidence_7d'       => EvidenceEvent::where('occurred_at', '>=', now()->subDays(7))->count(),
            ],
            'byStatus'       => $byStatus,
            'recentProjects' => Project::active()->orderByDesc('updated_at')->limit(5)->get()
                ->map(fn (Project $p) => ProjectPresenter::summary($p))->all(),
            'recentEvidence' => $recentEvidence->map(fn (EvidenceEvent $e) => ProjectPresenter::evidence($e))->all(),
            // Charts. Budget is withheld from anyone without the cost grant — a subcontractor may
            // see the programme and not the margin, and that has to hold in the payload rather
            // than by not drawing the chart (PM-1022).
            'charts'         => $this->chartsFor($request),
            // Whether the money charts were withheld, which the payload alone cannot say: the
            // redaction empties them to `[]`, and a project with no budget captured yet arrives
            // as `[]` too. The page needs to tell those apart — one is "not for you", the other
            // is "nothing entered yet" and should say so rather than leaving a hole in the grid.
            'canViewCosts'   => $request->user()->can('viewCosts', Project::class),
            'capabilities'   => $this->registry->forResponse(),
        ]);
    }

    /** @return array<string, mixed> */
    private function chartsFor(\Illuminate\Http\Request $request): array
    {
        // The cost redaction lives in the service, so this page and the Analytics page cannot
        // disagree about which charts carry money.
        return $this->analytics->dashboard(
            withCosts: $request->user()->can('viewCosts', Project::class),
        );
    }
}
