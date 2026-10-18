<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\AI\Services\AIChatProviderChain;
use App\Modules\Operations\ProjectManagement\Models\ChangeRequest;
use App\Modules\Operations\ProjectManagement\Models\Issue;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Risk;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskAssignment;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The project assistant (§25).
 *
 * ── The design decision that matters ────────────────────────────────────────
 *
 * Most of what §25 asks for is not generation, it is *querying*: "which projects are likely to miss
 * deadlines", "show projects exceeding budget", "which engineers are overbooked next month". Those
 * have exact answers sitting in the database, and sending them to a language model would take a
 * question with one right answer and give it a plausible one instead. Worse, it would be wrong
 * occasionally and confidently, which on a schedule or a budget is the expensive kind of wrong.
 *
 * So this service answers those deterministically, from the same data every other screen uses, and
 * reserves the model for the parts that genuinely are writing: drafting a status narrative,
 * summarising a month, turning notes into minutes.
 *
 * Two consequences worth knowing:
 *
 *  - **The insights work with no AI configured at all.** A tenant with no model provider still gets
 *    every warning below. Only the prose is unavailable, and it says so.
 *  - **Every insight carries its evidence.** Each one names the projects, people or figures behind
 *    it, so a project manager can check it rather than believe it.
 */
class PmAssistantService
{
    /** Below this, a project is behind enough to say so. SPI is earned ÷ planned. */
    private const SPI_WARN = 0.9;

    /** Cost performance below this means the work done cost more than it was worth. */
    private const CPI_WARN = 0.9;

    /** Hours a person can be booked in a month before the plan is fiction. BCEA ordinary month. */
    private const MONTH_HOURS = 195.0;

    /** Memoised: both the schedule and the cost warning read the same figures. */
    private $indices = null;

    public function __construct(
        private readonly EarnedValueService $evm,
        private readonly AIChatProviderChain $ai,
    ) {
    }

    /**
     * Everything worth telling somebody, worst first.
     *
     * @return list<array{key:string, severity:string, title:string, detail:string, evidence:list<string>}>
     */
    public function insights(): array
    {
        $out = [];

        foreach ($this->slippingProjects() as $row) {
            $out[] = [
                'key'      => 'schedule',
                'severity' => $row['spi'] < 0.75 ? 'high' : 'medium',
                'title'    => __('project_management.assistant.slipping_title', ['code' => $row['code']]),
                'detail'   => __('project_management.assistant.slipping_detail', [
                    'name' => $row['name'], 'spi' => number_format($row['spi'], 2),
                ]),
                'evidence' => [$row['code']],
            ];
        }

        foreach ($this->overspendingProjects() as $row) {
            $out[] = [
                'key'      => 'cost',
                'severity' => $row['cpi'] < 0.75 ? 'high' : 'medium',
                'title'    => __('project_management.assistant.overspend_title', ['code' => $row['code']]),
                'detail'   => __('project_management.assistant.overspend_detail', [
                    'name' => $row['name'], 'cpi' => number_format($row['cpi'], 2),
                ]),
                'evidence' => [$row['code']],
            ];
        }

        foreach ($this->overloadedPeople() as $row) {
            $out[] = [
                'key'      => 'workload',
                'severity' => $row['hours'] > self::MONTH_HOURS * 1.25 ? 'high' : 'medium',
                'title'    => __('project_management.assistant.overloaded_title', ['name' => $row['name']]),
                'detail'   => __('project_management.assistant.overloaded_detail', [
                    'hours'    => number_format($row['hours'], 0),
                    'capacity' => number_format(self::MONTH_HOURS, 0),
                    'projects' => $row['projects'],
                ]),
                'evidence' => [$row['name']],
            ];
        }

        if ($stale = $this->silentProjects()) {
            $out[] = [
                'key'      => 'evidence',
                'severity' => 'medium',
                'title'    => __('project_management.assistant.silent_title', ['count' => count($stale)]),
                // The most useful warning in the module: a project reporting nothing is not a
                // project going well, it is a project nobody can say anything about.
                'detail'   => __('project_management.assistant.silent_detail'),
                'evidence' => $stale,
            ];
        }

        if ($unmitigated = $this->unmitigatedRisks()) {
            $out[] = [
                'key'      => 'risk',
                'severity' => 'high',
                'title'    => __('project_management.assistant.risk_title', ['count' => count($unmitigated)]),
                'detail'   => __('project_management.assistant.risk_detail'),
                'evidence' => $unmitigated,
            ];
        }

        if ($stuck = $this->stalledChanges()) {
            $out[] = [
                'key'      => 'change',
                'severity' => 'medium',
                'title'    => __('project_management.assistant.change_title', ['count' => count($stuck)]),
                'detail'   => __('project_management.assistant.change_detail'),
                'evidence' => $stuck,
            ];
        }

        if ($overdue = $this->overdueIssues()) {
            $out[] = [
                'key'      => 'issue',
                'severity' => 'medium',
                'title'    => __('project_management.assistant.issue_title', ['count' => count($overdue)]),
                'detail'   => __('project_management.assistant.issue_detail'),
                'evidence' => $overdue,
            ];
        }

        usort($out, fn ($a, $b) => ($b['severity'] === 'high' ? 1 : 0) <=> ($a['severity'] === 'high' ? 1 : 0));

        return $out;
    }

    /**
     * Earned value per active project, computed once and reused by both warnings.
     *
     * `EarnedValueService::portfolio()` returns one aggregate row for the whole portfolio, which
     * answers a different question — a portfolio can look healthy with three projects failing
     * inside it. Naming the individual projects is the entire value of these warnings.
     *
     * @return \Illuminate\Support\Collection<int, array<string,mixed>>
     */
    private function projectIndices()
    {
        return $this->indices ??= Project::query()->active()->get()
            ->map(fn (Project $p) => $this->evm->forProject($p) + ['code' => $p->code, 'name' => $p->name]);
    }

    /** Behind schedule: earned value below planned by more than the warning band. */
    private function slippingProjects(): array
    {
        return $this->projectIndices()
            ->filter(fn ($r) => ($r['spi'] ?? null) !== null && $r['spi'] < self::SPI_WARN)
            ->sortBy('spi')
            ->take(10)
            ->values()
            ->all();
    }

    private function overspendingProjects(): array
    {
        return $this->projectIndices()
            ->filter(fn ($r) => ($r['cpi'] ?? null) !== null && $r['cpi'] < self::CPI_WARN)
            ->sortBy('cpi')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * People booked past a sensible month.
     *
     * Measured from *assignments on live tasks*, not from hours already worked: the question is
     * whether the plan is achievable, and hours worked can only ever tell you about the past.
     *
     * @return list<array{name:string, hours:float, projects:int}>
     */
    private function overloadedPeople(): array
    {
        $rows = DB::table('pm_task_assignments as a')
            ->join('pm_tasks as t', 't.id', '=', 'a.task_id')
            ->join('pm_project_members as m', 'm.id', '=', 'a.member_id')
            ->whereNull('a.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', ['done', 'cancelled'])
            ->whereNotNull('t.planned_end')
            ->whereBetween('t.planned_end', [now()->startOfMonth(), now()->addMonth()->endOfMonth()])
            ->groupBy('m.display_name')
            ->selectRaw('m.display_name as name, sum(coalesce(t.estimated_hours, 0)) as hours, count(distinct t.project_id) as projects')
            ->havingRaw('sum(coalesce(t.estimated_hours, 0)) > ?', [self::MONTH_HOURS])
            ->orderByDesc('hours')
            ->limit(10)
            ->get();

        return $rows->map(fn ($r) => [
            'name'     => (string) $r->name,
            'hours'    => (float) $r->hours,
            'projects' => (int) $r->projects,
        ])->all();
    }

    /** Active projects with no evidence for a fortnight. @return list<string> */
    private function silentProjects(): array
    {
        return Project::query()
            ->where('status', 'active')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('pm_evidence_events')
                ->whereColumn('pm_evidence_events.project_id', 'pm_projects.id')
                ->where('pm_evidence_events.occurred_at', '>=', now()->subDays(14)))
            ->limit(10)
            ->pluck('code')
            ->all();
    }

    /** High-scoring risks with nothing written in the mitigation. @return list<string> */
    private function unmitigatedRisks(): array
    {
        return Risk::query()
            ->live()
            ->where('score', '>=', 15)
            ->where(fn ($q) => $q->whereNull('mitigation')->orWhere('mitigation', ''))
            ->limit(10)
            ->pluck('code')
            ->all();
    }

    /** Submitted more than a week ago and still undecided. @return list<string> */
    private function stalledChanges(): array
    {
        return ChangeRequest::query()
            ->where('status', 'submitted')
            ->where('updated_at', '<', now()->subWeek())
            ->limit(10)
            ->pluck('code')
            ->all();
    }

    /** @return list<string> */
    private function overdueIssues(): array
    {
        return Issue::query()
            ->open()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->limit(10)
            ->pluck('code')
            ->all();
    }

    // ── The part that genuinely is writing ──────────────────────────────────

    public function canWrite(): bool
    {
        return ! empty(config('ai.chat.chain'));
    }

    /**
     * Draft a status report for a project.
     *
     * The model is given *facts this service assembled*, never a database handle and never free
     * rein: it is being asked to write a paragraph around numbers that are already correct. That
     * is the difference between a drafting tool and a system that invents a percentage.
     *
     * Returns null when no provider is configured, and the caller says so rather than pretending.
     */
    public function draftStatusReport(Project $project): ?string
    {
        if (! $this->canWrite()) {
            return null;
        }

        $evm = $this->evm->forProject($project);

        $facts = [
            'project'     => $project->code . ' — ' . $project->name,
            'status'      => $project->status,
            'stage'       => $project->stage,
            'planned_end' => optional($project->planned_end)->toDateString(),
            'spi'         => $evm['spi'] ?? null,
            'cpi'         => $evm['cpi'] ?? null,
            'open_issues' => Issue::where('project_id', $project->id)->open()->count(),
            'high_risks'  => Risk::where('project_id', $project->id)->live()->where('score', '>=', 15)->count(),
            'overdue_tasks' => Task::where('project_id', $project->id)
                ->whereNotIn('status', ['done', 'cancelled'])
                ->whereDate('planned_end', '<', now())->count(),
            'milestones_due' => Task::where('project_id', $project->id)
                ->where('is_milestone', true)->whereNotIn('status', ['done', 'cancelled'])
                ->whereBetween('planned_end', [now(), now()->addMonth()])
                ->pluck('name')->all(),
        ];

        return $this->ai->text(
            'You are writing a project status report for a construction and engineering firm. '
            . 'Use only the facts given. Do not invent figures, dates or names. If a figure is null, '
            . 'say it is not being measured rather than guessing. Write four short paragraphs: where '
            . 'the project stands, what is going well, what is at risk, and what you need from the '
            . 'reader. Plain English, no marketing language.',
            json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ['max_tokens' => 700],
        );
    }

    /**
     * Turn rough notes into minutes.
     *
     * Deliberately conservative in its prompt: minutes that invent an action item are worse than no
     * minutes, because somebody will act on them.
     */
    public function draftMinutes(string $notes, string $agenda = ''): ?string
    {
        if (! $this->canWrite()) {
            return null;
        }

        return $this->ai->text(
            'Turn these rough meeting notes into minutes. Keep every decision and action exactly as '
            . 'written — do not add actions, owners or dates that are not in the notes. Where an '
            . 'action has no owner or date, write "owner not recorded". Structure: attendees if '
            . 'mentioned, discussion, decisions, actions.',
            trim($agenda . "\n\n" . $notes),
            ['max_tokens' => 900],
        );
    }
}
