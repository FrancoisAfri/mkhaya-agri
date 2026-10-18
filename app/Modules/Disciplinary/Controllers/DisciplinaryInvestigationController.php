<?php

namespace App\Modules\Disciplinary\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigation;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigationNote;
use App\Modules\Disciplinary\Models\DisciplinaryInvestigationReport;
use App\Modules\Disciplinary\Services\DisciplinaryEvidenceIntelligenceService;
use App\Modules\Disciplinary\Traits\DisciplinaryAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DisciplinaryInvestigationController extends Controller
{
    use DisciplinaryAuthTrait, FileStorage;

    public function __construct(protected DisciplinaryEvidenceIntelligenceService $evidenceAi) {}

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index(): Response
    {
        abort_unless($this->isDiscAdmin(), 403);

        $investigations = DisciplinaryInvestigation::with(['case.employee.person', 'investigator.person'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn($i) => $this->summarise($i));

        $cases = DisciplinaryCase::with(['employee.person', 'status'])
            ->where(function ($q) {
                $q->whereDoesntHave('status')
                  ->orWhereHas('status', fn($q2) => $q2->where('is_closed', false));
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($c) => [
                'id'    => $c->id,
                'label' => "{$c->case_number} — {$c->employeeName()} ({$c->title})",
            ]);

        return Inertia::render('Modules/Disciplinary/Investigations/Index', [
            'investigations' => $investigations,
            'employees'      => $this->employeeList(),
            'cases'          => $cases,
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(DisciplinaryInvestigation $investigation): Response
    {
        $userId = auth()->id();
        $isInvestigator = $investigation->investigator_id === $userId;

        abort_unless($this->isDiscAdmin() || $isInvestigator, 403);

        $investigation->load(['case.employee.person', 'case.category', 'investigator.person', 'notes.author.person', 'reports.generatedBy.person']);

        $notes = $investigation->notes->map(fn($n) => [
            'id'              => $n->id,
            'note_type'       => $n->note_type,
            'title'           => $n->title,
            'content'         => $n->content,
            'is_confidential' => $n->is_confidential,
            'icon'            => $n->noteTypeIcon(),
            'author'          => $this->personName($n->author),
            'created_at'      => $n->created_at?->format('d M Y H:i'),
        ]);

        $reports = $investigation->reports->map(fn($r) => [
            'id'               => $r->id,
            'report_type'      => $r->report_type,
            'title'            => $r->title,
            'executive_summary'=> $r->executive_summary,
            'findings'         => $r->findings,
            'conclusion'       => $r->conclusion,
            'recommendations'  => $r->recommendations,
            'is_ai_generated'  => $r->is_ai_generated,
            'has_file'         => $r->hasBytes(),
            'download_url'     => $r->hasBytes()
                ? route('disciplinary.investigations.report.download', [$investigation->id, $r->id])
                : null,
            'generated_by'     => $this->personName($r->generatedBy),
            'created_at'       => $r->created_at?->format('d M Y H:i'),
        ]);

        $progress = $investigation->checklistProgress();

        return Inertia::render('Modules/Disciplinary/Investigations/Show', [
            'investigation' => [
                'id'                 => $investigation->id,
                'investigation_number'=> $investigation->investigation_number,
                'case_id'            => $investigation->case_id,
                'case_number'        => $investigation->case?->case_number,
                'case_title'         => $investigation->case?->title,
                'employee_name'      => $investigation->case?->employeeName(),
                'category'           => $investigation->case?->category?->name,
                'investigator_id'    => $investigation->investigator_id,
                'investigator_name'  => $this->personName($investigation->investigator),
                'status'             => $investigation->status,
                'status_color'       => $investigation->statusColor(),
                'objective'          => $investigation->objective,
                'scope'              => $investigation->scope,
                'checklist'          => $investigation->checklist ?? [],
                'start_date'         => $investigation->start_date?->format('Y-m-d'),
                'due_date'           => $investigation->due_date?->format('Y-m-d'),
                'completed_date'     => $investigation->completed_date?->format('Y-m-d'),
                'findings'           => $investigation->findings,
                'recommended_action' => $investigation->recommended_action,
                'progress'           => $progress,
                'created_at'         => $investigation->created_at?->format('d M Y'),
            ],
            'notes'         => $notes,
            'reports'       => $reports,
            'employees'     => $this->isDiscAdmin() ? $this->employeeList() : collect(),
            'canEdit'       => $this->isDiscAdmin(),
            'isInvestigator'=> $isInvestigator,
        ]);
    }

    // ── Create investigation for a case ───────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate([
            'case_id'        => 'required|integer|exists:disciplinary_cases,id',
            'investigator_id'=> 'nullable|integer|exists:users,id',
            'objective'     => 'nullable|string',
            'scope'         => 'nullable|string',
            'start_date'    => 'nullable|date',
            'due_date'      => 'nullable|date',
        ]);

        $investigation = DisciplinaryInvestigation::create(array_merge($data, [
            'status'     => 'pending',
            'created_by' => auth()->id(),
            'updated_by' => auth()->id(),
        ]));

        activity('disciplinary')
            ->performedOn($investigation->case)
            ->causedBy(auth()->user())
            ->withProperties(['action' => 'investigation_opened', 'investigation_number' => $investigation->investigation_number])
            ->log("Investigation {$investigation->investigation_number} opened.");

        return redirect()->route('disciplinary.investigations.show', $investigation)
            ->with('success', "Investigation {$investigation->investigation_number} created.");
    }

    // ── Update investigation details ───────────────────────────────────────────

    public function update(Request $request, DisciplinaryInvestigation $investigation): RedirectResponse
    {
        $userId = auth()->id();
        abort_unless($this->isDiscAdmin() || $investigation->investigator_id === $userId, 403);

        $data = $request->validate([
            'status'            => 'nullable|in:pending,in_progress,completed,suspended',
            'objective'         => 'nullable|string',
            'scope'             => 'nullable|string',
            'start_date'        => 'nullable|date',
            'due_date'          => 'nullable|date',
            'completed_date'    => 'nullable|date',
            'findings'          => 'nullable|string',
            'recommended_action'=> 'nullable|string',
            'checklist'         => 'nullable|array',
            'checklist.*.item'  => 'required|string',
            'checklist.*.done'  => 'boolean',
        ]);

        $investigation->update(array_merge($data, ['updated_by' => $userId]));

        return back()->with('success', 'Investigation updated.');
    }

    // ── Add a note ────────────────────────────────────────────────────────────

    public function addNote(Request $request, DisciplinaryInvestigation $investigation): RedirectResponse
    {
        $userId = auth()->id();
        abort_unless($this->isDiscAdmin() || $investigation->investigator_id === $userId, 403);

        $data = $request->validate([
            'note_type'      => 'required|in:general,witness_interview,evidence_review,finding,recommendation',
            'title'          => 'nullable|string|max:200',
            'content'        => 'required|string',
            'is_confidential'=> 'boolean',
        ]);

        DisciplinaryInvestigationNote::create(array_merge($data, [
            'investigation_id' => $investigation->id,
            'author_id'        => $userId,
        ]));

        return back()->with('success', 'Note added.');
    }

    // ── Generate AI report ────────────────────────────────────────────────────

    public function generateReport(DisciplinaryInvestigation $investigation): RedirectResponse
    {
        abort_unless($this->isDiscAdmin() || $investigation->investigator_id === auth()->id(), 403);

        $investigation->load(['case.category', 'notes']);

        $noteSummary = $investigation->notes->map(fn($n) => "## {$n->note_type}\n{$n->content}")->implode("\n\n");
        $caseTitle   = $investigation->case?->title ?? 'Unknown';
        $title       = "Investigation Report — {$investigation->investigation_number}";
        $summary     = "Investigation into: {$caseTitle}";
        $findings    = $investigation->findings ?? $noteSummary;
        $recommendations = $investigation->recommended_action ?? '';

        $html = $this->buildInvestigationHtml($title, $summary, $findings, $recommendations);

        $report = DisciplinaryInvestigationReport::create([
            'investigation_id'  => $investigation->id,
            'report_type'       => 'final',
            'title'             => $title,
            'executive_summary' => $summary,
            'findings'          => $findings,
            'conclusion'        => null,
            'recommendations'   => $investigation->recommended_action,
            'is_ai_generated'   => false,
            'generated_by'      => auth()->id(),
            'file_uuid'         => Str::uuid(),
            'original_name'     => $investigation->investigation_number . '_report.html',
            'mime'              => 'text/html',
        ]);

        // A generated report is a file like any other: to the shared disk, not into the row.
        $report->writeBytes($html, 'html');
        $report->save();

        return back()->with('success', 'Investigation report generated.');
    }

    // ── Download investigation report file ────────────────────────────────────

    public function downloadReport(DisciplinaryInvestigation $investigation, DisciplinaryInvestigationReport $report): \Illuminate\Http\Response
    {
        abort_unless($this->isDiscAdmin() || $investigation->investigator_id === auth()->id(), 403);
        abort_unless($report->investigation_id === $investigation->id, 404);
        abort_unless($report->hasBytes(), 404);

        /*
         * These reports are HTML, and this used to serve them as text/html with no nosniff. That is
         * the textbook stored-XSS shape — the browser renders the file same-origin with the
         * application, where script in it can read the session of whoever opened it. The trait
         * serves it as an inert download instead.
         */
        return $report->fileResponse($report->original_name ?? 'report.html', $report->mime);
    }

    // ── Assign investigator ───────────────────────────────────────────────────

    public function assignInvestigator(Request $request, DisciplinaryInvestigation $investigation): RedirectResponse
    {
        abort_unless($this->isDiscAdmin(), 403);

        $data = $request->validate(['investigator_id' => 'required|exists:users,id']);
        $investigation->update(['investigator_id' => $data['investigator_id'], 'updated_by' => auth()->id()]);

        return back()->with('success', 'Investigator assigned.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function summarise(DisciplinaryInvestigation $i): array
    {
        return [
            'id'                   => $i->id,
            'investigation_number' => $i->investigation_number,
            'case_number'          => $i->case?->case_number,
            'case_title'           => $i->case?->title,
            'employee_name'        => $i->case?->employeeName() ?? '—',
            'investigator'         => $this->personName($i->investigator),
            'status'               => $i->status,
            'status_color'         => $i->statusColor(),
            'due_date'             => $i->due_date?->format('d M Y'),
            'progress'             => $i->checklistProgress(),
            'created_at'           => $i->created_at?->format('d M Y'),
        ];
    }

    private function personName(?User $user): string
    {
        if (! $user) return '—';
        $p = $user->person;
        return $p ? trim("{$p->first_name} {$p->surname}") : ($user->email ?? '—');
    }

    private function employeeList(): \Illuminate\Support\Collection
    {
        return User::join('hr_people as p', 'p.user_id', '=', 'users.id')
            ->where('users.status', 1)
            ->orderByRaw("p.first_name || ' ' || p.surname")
            ->get(['users.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'users.email']);
    }

    private function buildInvestigationHtml(string $title, string $summary, string $findings, string $recommendations): string
    {
        $findingsHtml = '<h2 style="color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:4px">Findings</h2>'
            . '<p style="white-space:pre-wrap">' . htmlspecialchars($findings) . '</p>';

        $recoHtml = $recommendations
            ? '<h2 style="color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:4px">Recommendations</h2>'
              . '<p style="white-space:pre-wrap">' . htmlspecialchars($recommendations) . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>{$title}</title>
<style>body{font-family:Arial,sans-serif;max-width:800px;margin:40px auto;color:#111827;line-height:1.6}
h1{color:#1f2937}p{margin:0 0 12px}</style></head><body>
<h1>{$title}</h1>
<p style="background:#f3f4f6;padding:12px;border-radius:4px;white-space:pre-wrap">{$summary}</p>
{$findingsHtml}{$recoHtml}
<hr style="margin-top:40px"><p style="color:#9ca3af;font-size:12px">Generated by Mkhaya Agri · {$title}</p>
</body></html>
HTML;
    }
}
