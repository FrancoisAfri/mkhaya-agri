<?php

namespace App\Modules\Disciplinary\Services;

use App\Core\AI\Registry\AgentRegistry;
use App\Core\User\Models\User;
use App\Modules\Disciplinary\Models\DisciplinaryCase;
use App\Modules\Disciplinary\Models\DisciplinaryEscalation;
use App\Modules\Disciplinary\Models\DisciplinarySuspension;
use App\Modules\Disciplinary\Models\DisciplinaryWarning;
use App\Modules\Disciplinary\Models\DisciplinaryWarningExpiry;
use App\Modules\Disciplinary\Events\EmployeeSuspended;
use App\Modules\Disciplinary\Events\EmployeeSuspensionEnded;
use App\Modules\Disciplinary\Notifications\DisciplinarySuspensionNotification;
use App\Shared\DTOs\AgentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProgressiveDisciplineService
{
    private const MODULE = 'disciplinary';

    // Warning validity in months per type (South African BCEA defaults)
    private const EXPIRY_MONTHS = [
        'verbal'        => 3,
        'written'       => 6,
        'final_written' => 12,
    ];

    public function __construct(protected AgentRegistry $agents) {}

    // ── Warning issuance ─────────────────────────────────────────────────────

    public function issueVerbalWarning(
        int $employeeId, int $issuedBy, string $description,
        ?int $caseId = null, ?int $hearingId = null,
        ?string $incidentReference = null
    ): DisciplinaryWarning {
        return $this->issueWarning(
            'verbal', $employeeId, $issuedBy,
            $description, $caseId, $hearingId, $incidentReference
        );
    }

    public function issueWrittenWarning(
        int $employeeId, int $issuedBy, string $description,
        ?int $caseId = null, ?int $hearingId = null,
        ?string $incidentReference = null
    ): DisciplinaryWarning {
        return $this->issueWarning(
            'written', $employeeId, $issuedBy,
            $description, $caseId, $hearingId, $incidentReference
        );
    }

    public function issueFinalWarning(
        int $employeeId, int $issuedBy, string $description,
        ?int $caseId = null, ?int $hearingId = null,
        ?string $incidentReference = null
    ): DisciplinaryWarning {
        return $this->issueWarning(
            'final_written', $employeeId, $issuedBy,
            $description, $caseId, $hearingId, $incidentReference
        );
    }

    private function issueWarning(
        string $type, int $employeeId, int $issuedBy,
        string $description, ?int $caseId, ?int $hearingId,
        ?string $incidentReference
    ): DisciplinaryWarning {
        $issued  = now()->toDate();
        $expiry  = now()->addMonths(self::EXPIRY_MONTHS[$type])->toDate();

        $warning = DisciplinaryWarning::create([
            'employee_id'        => $employeeId,
            'case_id'            => $caseId,
            'hearing_id'         => $hearingId,
            'warning_type'       => $type,
            'issued_by'          => $issuedBy,
            'issued_date'        => $issued,
            'expiry_date'        => $expiry,
            'description'        => $description,
            'incident_reference' => $incidentReference,
            'is_expired'         => false,
            'created_by'         => auth()->id(),
        ]);

        DisciplinaryWarningExpiry::create([
            'warning_id'   => $warning->id,
            'employee_id'  => $employeeId,
            'warning_type' => $type,
            'issued_date'  => $issued,
            'expiry_date'  => $expiry,
            'is_expired'   => false,
        ]);

        return $warning;
    }

    // ── Suspension ───────────────────────────────────────────────────────────

    public function issueSuspension(
        int $employeeId, int $issuedBy, string $type,
        string $reason, string $startDate, ?string $endDate = null,
        ?int $caseId = null, ?string $notes = null
    ): DisciplinarySuspension {
        $start = Carbon::parse($startDate);
        $end   = $endDate ? Carbon::parse($endDate) : null;
        $days  = $end ? $start->diffInDays($end) : null;

        $suspension = DisciplinarySuspension::create([
            'employee_id'          => $employeeId,
            'case_id'              => $caseId,
            'suspension_type'      => $type,
            'start_date'           => $start,
            'end_date'             => $end,
            'expected_return_date' => $end?->copy()->addDay(),
            'reason'               => $reason,
            'issued_by'            => $issuedBy,
            'status'               => 'active',
            'suspension_days'      => $days,
            'notes'                => $notes,
            'created_by'           => auth()->id(),
        ]);

        $suspension->load(['case', 'employee.person']);

        $employee = User::find($employeeId);

        // 1. Fire event — payroll module listens to apply/skip deductions
        EmployeeSuspended::dispatch($suspension);

        // 2. Lock the employee's system account
        if ($employee) {
            $employee->update(['status' => 0]);
        }

        // 3. Notify the suspended employee
        if ($employee) {
            try {
                $employee->notify(new DisciplinarySuspensionNotification($suspension, 'employee'));
            } catch (\Throwable $e) {
                Log::warning("Suspension employee notification failed [{$employeeId}]: " . $e->getMessage());
            }
        }

        // 4. Notify the issuing HR officer
        $issuer = User::find($issuedBy);
        if ($issuer && $issuer->id !== $employeeId) {
            try {
                $issuer->notify(new DisciplinarySuspensionNotification($suspension, 'hr'));
            } catch (\Throwable $e) {
                Log::warning("Suspension HR notification failed [{$issuedBy}]: " . $e->getMessage());
            }
        }

        // 5. Notify the employee's direct manager
        $managerUserId = $employee?->person?->manager?->user_id;
        if ($managerUserId && $managerUserId !== $issuedBy) {
            $manager = User::find($managerUserId);
            if ($manager) {
                try {
                    $manager->notify(new DisciplinarySuspensionNotification($suspension, 'manager'));
                } catch (\Throwable $e) {
                    Log::warning("Suspension manager notification failed [{$managerUserId}]: " . $e->getMessage());
                }
            }
        }

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($suspension)
            ->withProperties([
                'suspension_type' => $type,
                'start_date'      => $startDate,
                'end_date'        => $endDate,
                'suspension_days' => $days,
                'case_id'         => $caseId,
                'employee_id'     => $employeeId,
                'employee'        => $suspension->employee->person?->full_name ?? $suspension->employee->email,
            ])
            ->event('suspension_issued')
            ->log("Suspension issued: {$type} for " . ($suspension->employee->person?->full_name ?? $suspension->employee->email));

        return $suspension;
    }

    public function endSuspension(DisciplinarySuspension $suspension, string $actualReturnDate): void
    {
        $suspension->update([
            'status'             => 'ended',
            'actual_return_date' => Carbon::parse($actualReturnDate),
        ]);

        $suspension->load(['case', 'employee.person']);

        activity('disciplinary')
            ->causedBy(auth()->user())
            ->performedOn($suspension)
            ->withProperties([
                'actual_return_date' => $actualReturnDate,
                'employee_id'        => $suspension->employee_id,
                'employee'           => $suspension->employee->person?->full_name ?? $suspension->employee->email,
                'case_id'            => $suspension->case_id,
            ])
            ->event('suspension_ended')
            ->log("Suspension ended. Return date: {$actualReturnDate}. Employee: " . ($suspension->employee->person?->full_name ?? $suspension->employee->email));

        // 1. Fire event — payroll module listens to reverse any deductions
        EmployeeSuspensionEnded::dispatch($suspension, $actualReturnDate);

        // 2. Restore system access if no other active suspensions remain
        $employee = User::find($suspension->employee_id);
        if ($employee) {
            $hasOtherActiveSuspensions = DisciplinarySuspension::where('employee_id', $suspension->employee_id)
                ->where('id', '!=', $suspension->id)
                ->where('status', 'active')
                ->exists();

            if (!$hasOtherActiveSuspensions) {
                $employee->update(['status' => 1]);
            }

            // 3. Notify employee their suspension has ended
            try {
                $employee->notify(new \App\Modules\Disciplinary\Notifications\DisciplinarySuspensionEndedNotification($suspension, $actualReturnDate));
            } catch (\Throwable $e) {
                Log::warning("Suspension-ended notification failed [{$suspension->employee_id}]: " . $e->getMessage());
            }

            // 4. Notify manager that employee is returning
            $managerUserId = $employee->person?->manager?->user_id;
            if ($managerUserId) {
                $manager = User::find($managerUserId);
                try {
                    $manager?->notify(new \App\Modules\Disciplinary\Notifications\DisciplinarySuspensionEndedNotification($suspension, $actualReturnDate));
                } catch (\Throwable $e) {
                    Log::warning("Suspension-ended manager notification failed [{$managerUserId}]: " . $e->getMessage());
                }
            }
        }
    }

    public function cancelSuspension(DisciplinarySuspension $suspension, string $reason): void
    {
        $actor = auth()->user();
        $actorName = $actor?->person?->full_name ?? $actor?->email ?? 'System';

        $suspension->update([
            'status' => 'cancelled',
            'notes'  => ($suspension->notes ? $suspension->notes . "\n" : '')
                      . '[' . now()->format('d M Y H:i') . " — {$actorName}] Cancelled: {$reason}",
        ]);

        $suspension->load(['case', 'employee.person']);

        activity('disciplinary')
            ->causedBy($actor)
            ->performedOn($suspension)
            ->withProperties([
                'reason'      => $reason,
                'employee_id' => $suspension->employee_id,
                'employee'    => $suspension->employee->person?->full_name ?? $suspension->employee->email,
                'case_id'     => $suspension->case_id,
            ])
            ->event('suspension_cancelled')
            ->log("Suspension cancelled. Employee: " . ($suspension->employee->person?->full_name ?? $suspension->employee->email) . ". Reason: {$reason}");

        // Restore account access
        $employee = User::find($suspension->employee_id);
        if ($employee) {
            $hasOtherActive = DisciplinarySuspension::where('employee_id', $suspension->employee_id)
                ->where('id', '!=', $suspension->id)
                ->where('status', 'active')->exists();

            if (!$hasOtherActive) {
                $employee->update(['status' => 1]);
            }

            try {
                $employee->notify(new \App\Modules\Disciplinary\Notifications\DisciplinarySuspensionEndedNotification($suspension, now()->toDateString()));
            } catch (\Throwable $e) {
                Log::warning("Cancel suspension notification failed [{$suspension->employee_id}]: " . $e->getMessage());
            }
        }

        // Notify manager
        $managerUserId = $employee?->person?->manager?->user_id;
        if ($managerUserId) {
            $manager = User::find($managerUserId);
            try {
                $manager?->notify(new \App\Modules\Disciplinary\Notifications\DisciplinarySuspensionEndedNotification($suspension, now()->toDateString()));
            } catch (\Throwable $e) {
                Log::warning("Cancel suspension manager notification failed: " . $e->getMessage());
            }
        }
    }

    public function extendSuspension(DisciplinarySuspension $suspension, string $newEndDate, string $reason): void
    {
        $newEnd    = Carbon::parse($newEndDate);
        $newDays   = $suspension->start_date ? $suspension->start_date->diffInDays($newEnd) : null;
        $oldEndDate = $suspension->end_date?->format('d M Y') ?? '—';
        $actor     = auth()->user();
        $actorName = $actor?->person?->full_name ?? $actor?->email ?? 'System';

        $suspension->update([
            'end_date'            => $newEnd,
            'expected_return_date'=> $newEnd->copy()->addDay(),
            'suspension_days'     => $newDays,
            'notes'               => ($suspension->notes ? $suspension->notes . "\n" : '')
                                   . '[' . now()->format('d M Y H:i') . " — {$actorName}] Extended to {$newEnd->format('d M Y')}: {$reason}",
        ]);

        activity('disciplinary')
            ->causedBy($actor)
            ->performedOn($suspension)
            ->withProperties([
                'old_end_date'    => $oldEndDate,
                'new_end_date'    => $newEnd->format('d M Y'),
                'new_days'        => $newDays,
                'reason'          => $reason,
                'employee_id'     => $suspension->employee_id,
                'employee'        => $suspension->employee?->person?->full_name ?? $suspension->employee?->email,
                'case_id'         => $suspension->case_id,
            ])
            ->event('suspension_extended')
            ->log("Suspension extended to {$newEnd->format('d M Y')} (was {$oldEndDate}). Employee: " . ($suspension->employee?->person?->full_name ?? $suspension->employee?->email));

        $suspension->load(['case', 'employee.person']);

        // Notify employee of extended suspension
        $employee = User::find($suspension->employee_id);
        if ($employee) {
            try {
                $employee->notify(new DisciplinarySuspensionNotification($suspension, 'employee'));
            } catch (\Throwable $e) {
                Log::warning("Extend suspension notification failed [{$suspension->employee_id}]: " . $e->getMessage());
            }
        }
    }

    public function convertSuspensionType(DisciplinarySuspension $suspension, string $newType, string $reason): void
    {
        $oldType   = $suspension->suspension_type;
        $actor     = auth()->user();
        $actorName = $actor?->person?->full_name ?? $actor?->email ?? 'System';

        $suspension->update([
            'suspension_type' => $newType,
            'notes'           => ($suspension->notes ? $suspension->notes . "\n" : '')
                               . '[' . now()->format('d M Y H:i') . " — {$actorName}] Type changed: {$oldType} → {$newType}: {$reason}",
        ]);

        activity('disciplinary')
            ->causedBy($actor)
            ->performedOn($suspension)
            ->withProperties([
                'old_type'    => $oldType,
                'new_type'    => $newType,
                'reason'      => $reason,
                'employee_id' => $suspension->employee_id,
                'employee'    => $suspension->employee?->person?->full_name ?? $suspension->employee?->email,
                'case_id'     => $suspension->case_id,
            ])
            ->event('suspension_type_converted')
            ->log("Suspension type changed: {$oldType} → {$newType}. Employee: " . ($suspension->employee?->person?->full_name ?? $suspension->employee?->email));

        $suspension->load(['case', 'employee.person']);

        // Re-fire event so payroll can react to the type change
        EmployeeSuspended::dispatch($suspension);

        // Notify employee of the change
        $employee = User::find($suspension->employee_id);
        if ($employee) {
            try {
                $employee->notify(new DisciplinarySuspensionNotification($suspension, 'employee'));
            } catch (\Throwable $e) {
                Log::warning("Convert suspension notification failed [{$suspension->employee_id}]: " . $e->getMessage());
            }
        }
    }

    // ── Dismissal ────────────────────────────────────────────────────────────

    public function issueDismissal(DisciplinaryCase $case, int $issuedBy, string $reason): DisciplinaryEscalation
    {
        return $this->createEscalation($case->employee_id, $case->id, null, 'dismissal', $reason, $issuedBy);
    }

    // ── Escalation recommendation ────────────────────────────────────────────

    public function recommendDisciplineEscalation(int $employeeId, ?int $caseId, int $triggeredBy): DisciplinaryEscalation
    {
        $history = $this->buildProgressiveHistory($employeeId);

        $fromLevel = $history['current_level'];
        $toLevel   = $this->calculateNextLevel($history);

        $aiReasoning = null;
        try {
            $aiReasoning = $this->generateEscalationReasoning($employeeId, $history, $fromLevel, $toLevel);
        } catch (\Throwable $e) {
            Log::warning("Disciplinary: AI escalation reasoning failed for employee {$employeeId}", ['error' => $e->getMessage()]);
        }

        return $this->createEscalation(
            $employeeId, $caseId,
            $fromLevel, $toLevel,
            $aiReasoning ?? 'Progressive discipline escalation based on disciplinary history.',
            $triggeredBy,
            true,
            $aiReasoning,
            $history['prior_cases']
        );
    }

    private function createEscalation(
        int $employeeId, ?int $caseId,
        ?string $fromLevel, string $toLevel,
        string $reason, int $triggeredBy,
        bool $aiRecommended = false,
        ?string $aiReasoning = null,
        int $priorOffences = 0,
    ): DisciplinaryEscalation {
        return DisciplinaryEscalation::create([
            'employee_id'          => $employeeId,
            'case_id'              => $caseId,
            'from_level'           => $fromLevel,
            'to_level'             => $toLevel,
            'reason'               => $reason,
            'triggered_by'         => $triggeredBy,
            'ai_recommended'       => $aiRecommended,
            'ai_reasoning'         => $aiReasoning,
            'prior_offences_count' => $priorOffences,
            'approved'             => false,
        ]);
    }

    // ── Warning expiry tracking ──────────────────────────────────────────────

    public function trackWarningExpiry(): array
    {
        $due = DisciplinaryWarningExpiry::due()->with('warning', 'employee')->get();
        $expired = [];

        foreach ($due as $record) {
            DB::transaction(function () use ($record, &$expired) {
                $record->update(['is_expired' => true, 'expired_at' => now()]);
                $record->warning?->update(['is_expired' => true]);
                $expired[] = $record->employee_id;
            });
        }

        return array_unique($expired);
    }

    // ── Employee progressive history ─────────────────────────────────────────

    public function buildProgressiveHistory(int $employeeId): array
    {
        $warnings = DisciplinaryWarning::forEmployee($employeeId)->active()
            ->orderBy('issued_date')->get();

        $suspensions = DisciplinarySuspension::where('employee_id', $employeeId)
            ->active()->get();

        $priorCases = DisciplinaryCase::where('employee_id', $employeeId)->count();

        $hasFinal    = $warnings->where('warning_type', 'final_written')->isNotEmpty();
        $hasWritten  = $warnings->where('warning_type', 'written')->isNotEmpty();
        $hasVerbal   = $warnings->where('warning_type', 'verbal')->isNotEmpty();
        $hasSuspension = $suspensions->isNotEmpty();

        $currentLevel = match (true) {
            $hasFinal      => 'final_written',
            $hasSuspension => 'suspension',
            $hasWritten    => 'written',
            $hasVerbal     => 'verbal',
            default        => 'none',
        };

        return [
            'current_level'     => $currentLevel,
            'active_warnings'   => $warnings->count(),
            'verbal_count'      => $warnings->where('warning_type', 'verbal')->count(),
            'written_count'     => $warnings->where('warning_type', 'written')->count(),
            'final_count'       => $warnings->where('warning_type', 'final_written')->count(),
            'suspension_count'  => $suspensions->count(),
            'prior_cases'       => $priorCases,
            'is_first_offence'  => $priorCases <= 1,
        ];
    }

    private function calculateNextLevel(array $history): string
    {
        return match ($history['current_level']) {
            'none'          => 'verbal',
            'verbal'        => 'written',
            'written'       => 'final_written',
            'final_written' => 'suspension',
            'suspension'    => 'dismissal',
            default         => 'dismissal',
        };
    }

    private function generateEscalationReasoning(int $employeeId, array $history, ?string $from, string $to): string
    {
        $prompt = "You are an HR specialist in South African labour law. Provide a brief professional justification " .
                  "for escalating discipline from '{$from}' to '{$to}' based on this employee's history. " .
                  "Keep it under 100 words, factual and fair. History: " . json_encode($history);

        $request = new AgentRequest(
            prompt       : $prompt,
            module       : self::MODULE,
            systemPrompt : 'You are an expert HR and labour relations specialist.',
            temperature  : 0.2,
            maxTokens    : 200,
        );

        $response = $this->agents->for(self::MODULE)->ask($request);
        return is_string($response) ? $response : ($response->content ?? '');
    }
}
