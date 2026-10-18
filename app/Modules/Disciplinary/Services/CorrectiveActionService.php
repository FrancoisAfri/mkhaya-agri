<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryCoachingSession;
use App\Modules\Disciplinary\Models\DisciplinaryCorrectiveAction;
use App\Modules\Disciplinary\Models\DisciplinaryRehabilitationProgress;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CorrectiveActionService
{
    private const MODULE = 'disciplinary';

    public function __construct(
        protected AgentRegistry $agents,
        protected DisciplinaryNotificationService $notifications,
    ) {}

    // ── Corrective actions ────────────────────────────────────────────────────

    public function createCorrectiveAction(array $data): DisciplinaryCorrectiveAction
    {
        $action = DisciplinaryCorrectiveAction::create(array_merge($data, [
            'action_number' => $this->generateActionNumber(),
            'created_by'    => auth()->id(),
        ]));

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($action)
            ->withProperties(['action_number' => $action->action_number, 'employee_id' => $action->employee_id])
            ->event('corrective_action_created')
            ->log("Corrective action {$action->action_number} created");

        $this->notifications->notifyCorrectiveActionAssigned($action);

        return $action;
    }

    public function updateProgress(DisciplinaryCorrectiveAction $action, int $percent, string $notes): void
    {
        $status = match (true) {
            $percent >= 100 => 'completed',
            $percent > 0    => 'in_progress',
            default         => $action->status,
        };

        $action->update([
            'progress_percent' => $percent,
            'progress_notes'   => $notes,
            'status'           => $status,
            'completed_date'   => $percent >= 100 ? now()->toDateString() : $action->completed_date,
        ]);
    }

    public function recommendCorrectiveActions(DisciplinaryCase $case): array
    {
        $caseContext = [
            'case_number'    => $case->case_number,
            'title'          => $case->title,
            'severity'       => $case->category?->severity_level,
            'category'       => $case->category?->name,
            'employee_id'    => $case->employee_id,
        ];

        try {
            $prompt = "You are an HR specialist. Recommend 2-4 corrective actions for this disciplinary case. " .
                      "Consider the severity and category. Return JSON array: " .
                      "[{\"action_type\": \"training|counseling|coaching|performance_plan|work_shadowing|mentoring\", " .
                      "\"title\": \"...\", \"description\": \"...\", \"duration_weeks\": 4, \"reasoning\": \"...\"}]. " .
                      "Case: " . json_encode($caseContext);

            $request = new AgentRequest(
                prompt       : $prompt,
                module       : self::MODULE,
                systemPrompt : 'You are an expert HR and rehabilitation specialist.',
                temperature  : 0.3,
                maxTokens    : 800,
                responseFormat: 'json',
            );

            $response = $this->agents->for(self::MODULE)->ask($request);
            $text     = is_string($response) ? $response : ($response->content ?? '');
            $text     = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
            $text     = preg_replace('/\s*```$/', '', $text);
            $decoded  = json_decode($text, true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::warning("CorrectiveAction: AI recommendation failed for case {$case->id}", ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ── Coaching sessions ─────────────────────────────────────────────────────

    public function scheduleCoachingSession(array $data): DisciplinaryCoachingSession
    {
        $session = DisciplinaryCoachingSession::create(array_merge($data, [
            'created_by' => auth()->id(),
        ]));

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($session)
            ->withProperties([
                'session_type' => $session->session_type,
                'scheduled_at' => $session->scheduled_at?->format('d M Y H:i'),
                'employee_id'  => $session->employee_id,
                'coach_id'     => $session->coach_id,
            ])
            ->event('coaching_session_scheduled')
            ->log("Coaching session scheduled for {$session->scheduled_at?->format('d M Y H:i')}");

        $this->notifications->notifyCoachingSessionScheduled($session);

        return $session;
    }

    public function completeSession(DisciplinaryCoachingSession $session, string $notes, string $outcome): void
    {
        $session->update([
            'status'        => 'completed',
            'session_notes' => $notes,
            'outcome'       => $outcome,
        ]);
    }

    public function rescheduleSession(DisciplinaryCoachingSession $session, string $newDate): void
    {
        $session->update([
            'status'          => 'rescheduled',
            'rescheduled_to'  => Carbon::parse($newDate),
        ]);
    }

    // ── Rehabilitation progress ───────────────────────────────────────────────

    public function recordRehabilitationProgress(
        int $employeeId, ?int $caseId,
        string $periodStart, string $periodEnd,
        array $scores, ?string $notes, int $assessorId
    ): DisciplinaryRehabilitationProgress {
        $overall = array_sum($scores) / max(1, count($scores));

        $prev = DisciplinaryRehabilitationProgress::where('employee_id', $employeeId)
            ->orderByDesc('period_end')
            ->first();

        $trend = 'stable';
        if ($prev) {
            $trend = $overall > $prev->overall_score + 0.5 ? 'improving'
                : ($overall < $prev->overall_score - 0.5  ? 'declining' : 'stable');
        }

        $aiAnalysis = null;
        try {
            $aiAnalysis = $this->generateProgressNarrative($employeeId, $scores, $trend, $prev);
        } catch (\Throwable $e) {
            Log::warning("Rehabilitation: AI narrative failed for employee {$employeeId}", ['error' => $e->getMessage()]);
        }

        return DisciplinaryRehabilitationProgress::create([
            'employee_id'        => $employeeId,
            'case_id'            => $caseId,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'behavior_score'     => $scores['behavior']    ?? 0,
            'attendance_score'   => $scores['attendance']  ?? 0,
            'performance_score'  => $scores['performance'] ?? 0,
            'compliance_score'   => $scores['compliance']  ?? 0,
            'overall_score'      => round($overall, 1),
            'trend'              => $trend,
            'assessor_id'        => $assessorId,
            'notes'              => $aiAnalysis ?? $notes,
            'is_ai_generated'    => $aiAnalysis !== null,
            'ai_provider'        => $aiAnalysis ? config('ai.provider', 'claude') : null,
            'ai_model'           => $aiAnalysis ? config('ai.claude.model', 'claude-3-5-sonnet-20241022') : null,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateActionNumber(): string
    {
        $year = now()->format('Y');
        $last = DisciplinaryCorrectiveAction::whereYear('created_at', $year)
            ->orderByDesc('id')->first();
        $seq  = $last ? ((int) substr($last->action_number, -5)) + 1 : 1;
        return "CA-{$year}-" . str_pad($seq, 5, '0', STR_PAD_LEFT);
    }

    private function generateProgressNarrative(
        int $employeeId, array $scores, string $trend,
        ?DisciplinaryRehabilitationProgress $prev
    ): string {
        $prompt = "You are an HR rehabilitation specialist. Write a brief (max 80 words) professional assessment " .
                  "of this employee's rehabilitation progress. Trend: {$trend}. " .
                  "Scores: " . json_encode($scores) . ". " .
                  ($prev ? "Previous overall: {$prev->overall_score}." : "First assessment.");

        $request = new AgentRequest(
            prompt       : $prompt,
            module       : self::MODULE,
            systemPrompt : 'You are an expert HR rehabilitation specialist.',
            temperature  : 0.2,
            maxTokens    : 200,
        );

        $response = $this->agents->for(self::MODULE)->ask($request);
        return is_string($response) ? $response : ($response->content ?? '');
    }
}
