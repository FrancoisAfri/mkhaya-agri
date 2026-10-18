<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Trait\FileStorage;
use App\Modules\Disciplinary\Models\DisciplinaryAppeal;
use App\Modules\Disciplinary\Models\DisciplinaryAppealEvidence;
use App\Modules\Disciplinary\Models\DisciplinaryHearing;
use App\Modules\Disciplinary\Models\DisciplinaryAppealOutcome;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppealsService
{
    use FileStorage;

    private const MODULE = 'disciplinary';

    public function __construct(protected AgentRegistry $agents) {}

    // ── Appeal submission ─────────────────────────────────────────────────────

    public function submitAppeal(
        int $caseId, int $employeeId, int $submittedBy,
        string $grounds, ?string $supportingArgument,
        ?string $deadlineDate, ?int $hearingId
    ): DisciplinaryAppeal {
        return DisciplinaryAppeal::create([
            'appeal_number'      => $this->generateAppealNumber(),
            'case_id'            => $caseId,
            'employee_id'        => $employeeId,
            'hearing_id'         => $hearingId,
            'grounds'            => $grounds,
            'supporting_argument'=> $supportingArgument,
            'deadline_date'      => $deadlineDate,
            'submitted_by'       => $submittedBy,
            'status'             => 'submitted',
            'created_by'         => auth()->id(),
        ]);
    }

    // ── Panel assignment ──────────────────────────────────────────────────────

    public function assignAppealChairperson(DisciplinaryAppeal $appeal, int $chairpersonId, ?int $hrRepId): void
    {
        $appeal->update([
            'chairperson_id' => $chairpersonId,
            'hr_rep_id'      => $hrRepId,
            'status'         => 'under_review',
        ]);
    }

    public function scheduleAppealHearing(DisciplinaryAppeal $appeal, string $hearingDate): DisciplinaryAppeal
    {
        $hearing = DisciplinaryHearing::create([
            'case_id'      => $appeal->case_id,
            'hearing_type' => 'appeal',
            'status'       => 'scheduled',
            'scheduled_at' => $hearingDate,
            'created_by'   => auth()->id(),
        ]);

        $appeal->update([
            'appeal_hearing_id'   => $hearing->id,
            'appeal_hearing_date' => $hearingDate,
            'status'              => 'hearing_scheduled',
        ]);

        return $appeal;
    }

    // ── Evidence ──────────────────────────────────────────────────────────────

    public function captureAppealEvidence(
        DisciplinaryAppeal $appeal,
        UploadedFile $file,
        string $description,
        int $uploadedBy
    ): DisciplinaryAppealEvidence {
        $stored = $this->saveFileToDatabase($file);

        $evidence = new DisciplinaryAppealEvidence([
            'appeal_id'     => $appeal->id,
            'uploaded_by'   => $uploadedBy,
            'uuid'          => $stored['uuid'],
            'file_name'     => $stored['uuid'] . '.' . $file->getClientOriginalExtension(),
            'original_name' => $stored['original_name'],
            'mime'          => $stored['mime'],
            'file_type'     => $stored['type'],
            'file_size'     => $stored['size'],
            'description'   => $description,
        ]);

        $evidence->writeBytes(base64_decode($stored['data']), $file->getClientOriginalExtension());
        $evidence->save();

        return $evidence;
    }

    // ── Appeal pack ───────────────────────────────────────────────────────────

    public function generateAppealPack(DisciplinaryAppeal $appeal): string
    {
        $appeal->loadMissing(['case', 'employee', 'hearing', 'evidence', 'chairperson', 'hrRep']);
        $case = $appeal->case;
        $case?->loadMissing(['category', 'hearings', 'sanction']);

        $summary   = null;
        $aiWarning = null;

        try {
            $prompt = "You are an HR specialist. Generate a structured appeal hearing pack summary for this disciplinary appeal. " .
                      "Include: (1) grounds summary, (2) original case facts, (3) procedural review notes, " .
                      "(4) recommended considerations for the appeal panel. Keep it professional and objective (max 400 words). " .
                      "Appeal: " . json_encode([
                          'appeal_number'       => $appeal->appeal_number,
                          'grounds'             => $appeal->grounds,
                          'supporting_argument' => $appeal->supporting_argument,
                          'case_title'          => $case?->title,
                          'case_severity'       => $case?->category?->severity_level,
                          'evidence_count'      => $appeal->evidence->count(),
                      ]);

            $request = new AgentRequest(
                prompt       : $prompt,
                module       : self::MODULE,
                systemPrompt : 'You are an expert HR appeals specialist with knowledge of South African labour law.',
                temperature  : 0.2,
                maxTokens    : 1000,
            );

            $response = $this->agents->for(self::MODULE)->ask($request);
            $summary  = is_string($response) ? $response : ($response->content ?? null);
        } catch (\Throwable $e) {
            Log::warning("Appeals: AI pack generation failed for appeal {$appeal->id}", ['error' => $e->getMessage()]);
            $aiWarning = 'AI summary unavailable. Please review manually.';
        }

        return $this->buildAppealPackHtml($appeal, $case, $summary, $aiWarning);
    }

    // ── Outcome ───────────────────────────────────────────────────────────────

    public function captureAppealOutcome(
        DisciplinaryAppeal $appeal,
        int $decidedBy,
        string $verdict,
        string $reasoning,
        ?int $newSanctionId,
        ?string $outcomeNotes,
        bool $sanctionChanged,
        bool $caseOverturned,
        bool $notifyEmployee
    ): DisciplinaryAppealOutcome {
        $outcome = DisciplinaryAppealOutcome::create([
            'appeal_id'      => $appeal->id,
            'decided_by'     => $decidedBy,
            'decided_at'     => now(),
            'verdict'        => $verdict,
            'reasoning'      => $reasoning,
            'new_sanction_id'=> $newSanctionId,
            'outcome_notes'  => $outcomeNotes,
            'sanction_changed'=> $sanctionChanged,
            'case_overturned' => $caseOverturned,
            'notify_employee' => $notifyEmployee,
        ]);

        $appeal->update(['status' => 'decided']);

        if ($caseOverturned && $appeal->case) {
            $appeal->case->update(['internal_notes' =>
                ($appeal->case->internal_notes ? $appeal->case->internal_notes . "\n\n" : '') .
                "APPEAL {$appeal->appeal_number} OVERTURNED CASE — {$verdict} verdict on " . now()->toDateString()
            ]);
        }

        return $outcome;
    }

    public function withdrawAppeal(DisciplinaryAppeal $appeal, string $reason): void
    {
        $appeal->update([
            'status' => 'withdrawn',
            'notes'  => $reason,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateAppealNumber(): string
    {
        $year = now()->format('Y');
        $last = DisciplinaryAppeal::whereYear('created_at', $year)->orderByDesc('id')->first();
        $seq  = $last ? ((int) substr($last->appeal_number, -5)) + 1 : 1;
        return "AP-{$year}-" . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    private function buildAppealPackHtml(
        DisciplinaryAppeal $appeal,
        ?DisciplinaryCase $case,
        ?string $aiSummary,
        ?string $aiWarning
    ): string {
        $number = htmlspecialchars($appeal->appeal_number);
        $date   = now()->format('d M Y');
        $grounds= nl2br(htmlspecialchars($appeal->grounds));
        $arg    = nl2br(htmlspecialchars($appeal->supporting_argument ?? ''));
        $aiBlock= $aiWarning
            ? "<p style='color:#b45309;font-style:italic;'>{$aiWarning}</p>"
            : nl2br(htmlspecialchars($aiSummary ?? ''));

        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Appeal Pack - {$number}</title>
<style>
  body{font-family:Arial,sans-serif;margin:40px;color:#1a202c;line-height:1.6}
  h1{color:#1e3a5f;border-bottom:2px solid #1e3a5f;padding-bottom:8px}
  h2{color:#2d3748;margin-top:24px}
  .meta{background:#f7fafc;border:1px solid #e2e8f0;padding:16px;border-radius:4px;margin:16px 0}
  .section{margin:20px 0;padding:16px;border-left:4px solid #4299e1}
  .ai-summary{background:#fffbeb;border:1px solid #fde68a;padding:16px;border-radius:4px}
  .footer{margin-top:40px;font-size:12px;color:#718096;border-top:1px solid #e2e8f0;padding-top:8px}
</style></head><body>
<h1>Appeal Hearing Pack</h1>
<div class="meta">
  <strong>Appeal Number:</strong> {$number}<br>
  <strong>Generated:</strong> {$date}<br>
  <strong>Case:</strong> {$case?->case_number}<br>
  <strong>Status:</strong> {$appeal->status}
</div>
<div class="section">
  <h2>Grounds for Appeal</h2>
  <p>{$grounds}</p>
</div>
<div class="section">
  <h2>Supporting Argument</h2>
  <p>{$arg}</p>
</div>
<div class="ai-summary">
  <h2>AI Analysis</h2>
  {$aiBlock}
</div>
<div class="footer">Generated by Mkhaya HR · Disciplinary Appeals Module</div>
</body></html>
HTML;
    }
}
