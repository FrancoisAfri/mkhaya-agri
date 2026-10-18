<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Disciplinary\Models\DisciplinaryAiEvidenceReport;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCaseTimeline;
use App\Modules\Disciplinary\Models\DisciplinaryEvidenceAnalysis;
use App\Modules\Disciplinary\Models\DisciplinaryIncident;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Facades\Log;

class DisciplinaryEvidenceIntelligenceService
{
    private const MODULE = 'disciplinary_evidence';

    public function __construct(protected AgentRegistry $agents) {}

    // ── Timeline generation ────────────────────────────────────────────────────

    public function buildCaseTimeline(DisciplinaryCase $case): array
    {
        $case->loadMissing(['status', 'category', 'investigator', 'chairperson']);
        $events = [];

        // Incident date
        if ($case->incident_date) {
            $events[] = $this->timelineEvent($case->id, null, 'incident',
                'Incident Occurred', 'Case incident date as reported.', $case->incident_date->startOfDay());
        }

        // Case created
        $events[] = $this->timelineEvent($case->id, null, 'case_opened',
            'Case Opened', "Case {$case->case_number} opened.",
            $case->created_at, auth()->id(), 'System');

        // Notice issued
        if ($case->notice_issued_date) {
            $events[] = $this->timelineEvent($case->id, null, 'notice_issued',
                'Notice Issued', 'Notice to attend hearing issued to employee.', $case->notice_issued_date->startOfDay());
        }

        // Investigator assigned
        if ($case->investigator_id && $case->investigator) {
            $p = $case->investigator->person;
            $name = $p ? trim("{$p->first_name} {$p->surname}") : $case->investigator->email;
            $events[] = $this->timelineEvent($case->id, null, 'investigator_assigned',
                'Investigator Assigned', "Assigned to {$name}.", $case->updated_at,
                $case->investigator_id, $name);
        }

        // Hearing scheduled
        if ($case->hearing_date) {
            $events[] = $this->timelineEvent($case->id, null, 'hearing_scheduled',
                'Hearing Scheduled', 'Disciplinary hearing scheduled.', $case->hearing_date);
        }

        // Closed
        if ($case->closed_at) {
            $events[] = $this->timelineEvent($case->id, null, 'closed',
                'Case Closed', "Case closed. Outcome: " . ($case->outcome?->name ?? 'N/A'), $case->closed_at);
        }

        // Persist to DB
        foreach ($events as $ev) {
            DisciplinaryCaseTimeline::updateOrCreate(
                ['case_id' => $ev['case_id'], 'event_type' => $ev['event_type'], 'event_date' => $ev['event_date']],
                $ev
            );
        }

        return DisciplinaryCaseTimeline::where('case_id', $case->id)
            ->orderBy('event_date')
            ->get()
            ->map(fn($e) => [
                'id'             => $e->id,
                'event_type'     => $e->event_type,
                'event_label'    => $e->event_label,
                'event_detail'   => $e->event_detail,
                'event_date'     => $e->event_date?->format('d M Y H:i'),
                'actor_name'     => $e->actor_name,
                'is_ai_inferred' => $e->is_ai_inferred,
                'icon'           => DisciplinaryCaseTimeline::eventIcon($e->event_type),
                'color'          => DisciplinaryCaseTimeline::eventColor($e->event_type),
            ])->toArray();
    }

    // ── Witness consistency analysis ───────────────────────────────────────────

    public function analyzeWitnessConsistency(DisciplinaryCase|DisciplinaryIncident $subject): DisciplinaryEvidenceAnalysis
    {
        if ($subject instanceof DisciplinaryIncident) {
            $subject->loadMissing(['witnesses']);
            $statements = $subject->witnesses->map(fn($w) => [
                'witness'   => $w->is_anonymous ? 'Anonymous' : ($w->employee?->person
                    ? trim("{$w->employee->person->first_name} {$w->employee->person->surname}")
                    : $w->name ?? 'Witness'),
                'statement' => $w->statement,
                'date'      => $w->statement_date,
            ])->filter(fn($w) => !empty($w['statement']))->values()->toArray();
        } else {
            // DisciplinaryCase: pull statements from investigation notes
            $subject->loadMissing(['investigations.notes']);
            $statements = $subject->investigations->flatMap(fn($inv) => $inv->notes
                ->where('note_type', 'witness_interview')
                ->map(fn($n) => ['witness' => 'Investigator', 'statement' => $n->content, 'date' => $n->created_at?->toDateString()])
            )->values()->toArray();
        }

        if (empty($statements)) {
            return $this->makeAnalysis($subject, 'witness_consistency',
                'No witness statements available for consistency analysis.', [], null, []);
        }

        $prompt = "Analyse these witness statements for consistency, conflicts, and credibility. " .
                  "Return JSON: {\"analysis_result\": \"...\", \"consistency_score\": \"XX%\", " .
                  "\"key_findings\": [{\"finding\": \"...\", \"confidence\": \"high|medium|low\"}], " .
                  "\"conflicts_detected\": [{\"description\": \"...\", \"source_a\": \"...\", \"source_b\": \"...\"}]}. " .
                  "Statements: " . json_encode($statements);

        try {
            $response = $this->ask($prompt, 'json');
            $data = is_array($response) ? $response : [];

            return $this->makeAnalysis($subject, 'witness_consistency',
                $data['analysis_result'] ?? 'Analysis completed.',
                $data['key_findings'] ?? [],
                $data['consistency_score'] ?? null,
                $data['conflicts_detected'] ?? []
            );
        } catch (\Throwable $e) {
            Log::warning('Disciplinary Evidence AI: witness consistency failed', ['error' => $e->getMessage()]);
            return $this->makeAnalysis($subject, 'witness_consistency',
                'AI analysis unavailable — please review statements manually.', [], null, []);
        }
    }

    // ── Evidence summary generation ────────────────────────────────────────────

    public function generateEvidenceSummary(DisciplinaryCase|DisciplinaryIncident $subject): DisciplinaryAiEvidenceReport
    {
        $isCase = $subject instanceof DisciplinaryCase;

        if ($subject instanceof DisciplinaryIncident) {
            $subject->loadMissing(['evidence', 'category']);
            $evidenceList = $subject->evidence->map(fn($e) => [
                'file'        => $e->file_name,
                'type'        => $e->file_type,
                'description' => $e->description,
                'uploaded_by' => $e->uploader?->email,
                'date'        => $e->created_at?->toDateString(),
            ])->toArray();
        } else {
            // DisciplinaryCase: pull findings from investigation notes
            $subject->loadMissing(['investigations.notes', 'category']);
            $evidenceList = $subject->investigations->flatMap(fn($inv) => $inv->notes
                ->whereIn('note_type', ['evidence_review', 'finding'])
                ->map(fn($n) => ['file' => null, 'type' => $n->note_type, 'description' => $n->content, 'date' => $n->created_at?->toDateString()])
            )->values()->toArray();
        }

        $prompt = "You are an HR investigator. Summarise the evidence for this disciplinary matter. " .
                  "Return JSON: {\"executive_summary\": \"...\", " .
                  "\"sections\": [{\"heading\": \"...\", \"content\": \"...\"}], " .
                  "\"recommendations\": \"...\"}. " .
                  "Evidence: " . json_encode($evidenceList);

        try {
            $response = $this->ask($prompt, 'json');
            $data = is_array($response) ? $response : ['executive_summary' => (string) $response];
        } catch (\Throwable $e) {
            Log::warning('Disciplinary Evidence AI: evidence summary failed', ['error' => $e->getMessage()]);
            $data = ['executive_summary' => 'AI summary unavailable.'];
        }

        return DisciplinaryAiEvidenceReport::updateOrCreate(
            [
                'case_id'     => $isCase ? $subject->id : null,
                'incident_id' => $isCase ? null : $subject->id,
                'report_type' => 'evidence_summary',
            ],
            [
                'title'             => 'Evidence Summary — ' . ($isCase ? $subject->case_number : $subject->incident_number),
                'executive_summary' => $data['executive_summary'] ?? 'See sections.',
                'sections'          => $data['sections'] ?? [],
                'recommendations'   => $data['recommendations'] ?? null,
                'ai_provider'       => config('ai.provider', 'claude'),
                'ai_model'          => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'generated_by'      => auth()->id(),
            ]
        );
    }

    // ── Hearing pack generation ────────────────────────────────────────────────

    public function generateHearingPack(DisciplinaryCase $case): DisciplinaryAiEvidenceReport
    {
        $case->loadMissing(['employee.person', 'category', 'status', 'investigations.notes', 'hearings']);

        $context = [
            'case_number'    => $case->case_number,
            'employee'       => $case->employeeName(),
            'charges'        => $case->description,
            'category'       => $case->category?->name,
            'severity'       => $case->category?->severity_level,
            'incident_date'  => $case->incident_date?->toDateString(),
            'policy_ref'     => $case->policy_reference,
            'internal_notes' => $case->internal_notes,
            'investigations' => $case->investigations->count(),
            'hearings'       => $case->hearings->count(),
        ];

        $prompt = "Generate a comprehensive disciplinary hearing preparation pack. " .
                  "Include sections: (1) Case Overview, (2) Charges, (3) Evidence Summary, " .
                  "(4) Witnesses, (5) Relevant Policy References, (6) Procedural Checklist. " .
                  "Return JSON: {\"executive_summary\": \"...\", " .
                  "\"sections\": [{\"heading\": \"...\", \"content\": \"...\"}], " .
                  "\"recommendations\": \"...\"}. " .
                  "Case: " . json_encode($context);

        try {
            $response = $this->ask($prompt, 'json');
            $data = is_array($response) ? $response : ['executive_summary' => (string) $response];
        } catch (\Throwable $e) {
            Log::warning('Disciplinary Evidence AI: hearing pack generation failed', ['error' => $e->getMessage()]);
            $data = ['executive_summary' => 'AI generation unavailable. Please prepare manually.'];
        }

        $title    = 'Hearing Pack — ' . $case->case_number;
        $sections = $data['sections'] ?? [];
        $html     = $this->buildHtmlDocument($title, $data['executive_summary'] ?? '', $sections, $data['recommendations'] ?? '');

        $report = DisciplinaryAiEvidenceReport::updateOrCreate(
            ['case_id' => $case->id, 'report_type' => 'hearing_pack'],
            [
                'title'             => $title,
                'executive_summary' => $data['executive_summary'] ?? 'See sections.',
                'sections'          => $sections,
                'recommendations'   => $data['recommendations'] ?? null,
                'ai_provider'       => config('ai.provider', 'claude'),
                'ai_model'          => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'generated_by'      => auth()->id(),
                'file_uuid'         => \Illuminate\Support\Str::uuid(),
                'original_name'     => $case->case_number . '_hearing_pack.html',
                'mime'              => 'text/html',
            ]
        );

        // Regenerating writes a new file rather than overwriting, so anything still reading the
        // previous pack resolves.
        $report->writeBytes($html, 'html');
        $report->save();

        return $report;
    }

    // ── HTML document builder ─────────────────────────────────────────────────

    private function buildHtmlDocument(string $title, string $summary, array $sections, string $recommendations): string
    {
        $sectionsHtml = implode('', array_map(fn($s) =>
            '<h2 style="color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:4px">' . htmlspecialchars($s['heading'] ?? '') . '</h2>' .
            '<p style="white-space:pre-wrap">' . htmlspecialchars($s['content'] ?? '') . '</p>',
            $sections
        ));

        $recoHtml = $recommendations
            ? '<h2 style="color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:4px">Recommendations</h2><p style="white-space:pre-wrap">' . htmlspecialchars($recommendations) . '</p>'
            : '';

        return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>{$title}</title>
<style>body{font-family:Arial,sans-serif;max-width:800px;margin:40px auto;color:#111827;line-height:1.6}
h1{color:#1f2937}p{margin:0 0 12px}</style></head><body>
<h1>{$title}</h1>
<p style="background:#f3f4f6;padding:12px;border-radius:4px;white-space:pre-wrap">{$summary}</p>
{$sectionsHtml}{$recoHtml}
<hr style="margin-top:40px"><p style="color:#9ca3af;font-size:12px">Generated by Mkhaya Hub · {$title}</p>
</body></html>
HTML;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function timelineEvent(
        ?int $caseId, ?int $incidentId, string $type, string $label,
        string $detail, mixed $date, ?int $actorId = null, ?string $actorName = null
    ): array {
        return [
            'case_id'        => $caseId,
            'incident_id'    => $incidentId,
            'event_type'     => $type,
            'event_label'    => $label,
            'event_detail'   => $detail,
            'event_date'     => $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date),
            'actor_id'       => $actorId,
            'actor_name'     => $actorName,
            'is_ai_inferred' => false,
        ];
    }

    private function makeAnalysis(
        DisciplinaryCase|DisciplinaryIncident $subject,
        string $type, string $result, array $findings,
        ?string $consistencyScore, array $conflicts
    ): DisciplinaryEvidenceAnalysis {
        $isCase = $subject instanceof DisciplinaryCase;
        return DisciplinaryEvidenceAnalysis::updateOrCreate(
            [
                'case_id'       => $isCase ? $subject->id : null,
                'incident_id'   => $isCase ? null : $subject->id,
                'analysis_type' => $type,
            ],
            [
                'analysis_result'    => $result,
                'key_findings'       => $findings,
                'consistency_score'  => $consistencyScore,
                'conflicts_detected' => $conflicts,
                'ai_provider'        => config('ai.provider', 'claude'),
                'ai_model'           => config('ai.claude.model', 'claude-3-5-sonnet-20241022'),
                'generated_by'       => auth()->id(),
            ]
        );
    }

    private function ask(string $prompt, string $format = 'text'): mixed
    {
        $request = new AgentRequest(
            prompt        : $prompt,
            module        : self::MODULE,
            systemPrompt  : 'You are an expert HR evidence analyst and labour law specialist.',
            temperature   : 0.2,
            maxTokens     : 2048,
            responseFormat: $format,
        );

        /** @var \App\Shared\DTOs\AgentResponse $response */
        $response = $this->agents->for(self::MODULE)->ask($request);

        $text = $response->content;

        if ($format === 'json') {
            $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
            $text = preg_replace('/\s*```$/', '', $text);
            $decoded = json_decode($text, true);
            return $decoded ?? ['executive_summary' => $text];
        }

        return $text;
    }
}
