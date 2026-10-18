<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Models\WorkflowDefinition;
use App\Modules\Workflow\Services\ReportService;
use App\Modules\Workflow\Services\WorkflowAudit;
use App\Modules\Workflow\Support\WorkflowPermissions;
use App\Modules\Workflow\Support\WorkflowSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Management and executive dashboards and exports (PRS §14). */
class WorkflowDashboardController extends WorkflowController
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(WorkflowPermissions::VIEW);
        abort_unless($this->can(WorkflowPermissions::REPORTS) || $this->can(WorkflowPermissions::MANAGE), 403, 'You do not have access to workflow reports.');
        $f = $this->filters($request);
        $user = $request->user();

        return Inertia::render('Modules/Workflow/Dashboard', [
            'filters' => $f,
            'summary' => $this->reports->summary($user, $f),
            'bottlenecks' => $this->reports->bottlenecks($user, $f),
            'workflows' => $this->reports->byWorkflow($user, $f),
            'workload' => $this->reports->workload($user, $f),
            'departments' => $this->reports->byDepartment($user, $f),
            'priorities' => $this->reports->byPriority($user, $f),
            'trend' => $this->reports->trend($user, $f),
            'options' => [
                'workflows' => WorkflowDefinition::query()->orderBy('name')->get(['uuid', 'name']),
                'departments' => $this->departments(),
            ],
            'can' => ['ask' => (bool) app(WorkflowSettings::class)->get('ai_enabled')],
        ]);
    }

    public function export(Request $request)
    {
        $this->allow(WorkflowPermissions::VIEW);
        abort_unless($this->can(WorkflowPermissions::REPORTS) || $this->can(WorkflowPermissions::MANAGE), 403);
        $f = $this->filters($request);
        $report = $request->validate(['report' => ['required', 'in:summary,bottlenecks,workflows,workload,instances'], 'format' => ['nullable', 'in:csv,pdf']]);
        $user = $request->user();
        app(WorkflowAudit::class)->record('report.exported', 'WorkflowReport', ['context' => $f + $report]);

        [$headers, $rows] = match ($report['report']) {
            'summary' => [['Measure', 'Value'], collect($this->reports->summary($user, $f))->map(fn ($v, $k) => [ucfirst(str_replace('_', ' ', $k)), $v ?? '—'])->values()->all()],
            'bottlenecks' => [['Workflow', 'Step', 'Tasks', 'Average hours', 'Median hours', 'SLA breaches', 'Returned'], array_map('array_values', $this->reports->bottlenecks($user, $f, 500))],
            'workflows' => [['Workflow', 'Category', 'Started', 'Completed', 'Rejected', 'Running', 'Average hours'], array_map('array_values', $this->reports->byWorkflow($user, $f))],
            'workload' => [['Person', 'Open tasks', 'Overdue', 'High priority'], array_map('array_values', $this->reports->workload($user, $f))],
            'instances' => [['Reference', 'Workflow', 'Title', 'Status', 'Outcome', 'Requester', 'Started', 'Finished', 'Hours'],
                $this->reports->instances($user, $f)->with('definition:id,name')->orderBy('id')->limit(10000)->get()->map(fn ($i) => [$i->reference, $i->definition?->name,
                    $i->definition?->is_confidential ? '(confidential)' : $i->title, $i->status, $i->outcome, app(\App\Modules\Workflow\Services\Presenter::class)->name($i->requester_id),
                    $i->started_at?->toDateTimeString(), $i->completed_at?->toDateTimeString(),
                    $i->completed_at ? round(($i->completed_at->getTimestamp() - $i->started_at->getTimestamp()) / 3600, 1) : null])->all()],
        };
        $title = 'Workflow report — ' . ucfirst($report['report']) . ' (' . ($f['from'] ?? '') . ' to ' . ($f['to'] ?? '') . ')';

        if (($report['format'] ?? 'csv') === 'pdf') {
            $html = view('workflow.report', compact('title', 'headers', 'rows'))->render();

            return response(\Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output(), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="workflow-' . $report['report'] . '.pdf"']);
        }

        return response()->streamDownload(function () use ($title, $headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$title, 'Generated ' . now()->toDateTimeString()]);
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $r));
            }
            fclose($out);
        }, 'workflow-' . $report['report'] . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function filters(Request $request): array
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'workflow' => ['nullable', 'uuid'], 'department' => ['nullable', 'integer'], 'priority' => ['nullable', 'in:low,normal,high']]);

        return array_merge(['from' => now()->subDays(90)->toDateString(), 'to' => now()->toDateString()], array_filter($f, fn ($v) => $v !== null && $v !== ''));
    }
}
