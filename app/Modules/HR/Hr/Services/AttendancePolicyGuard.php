<?php

namespace App\Modules\HR\Hr\Services;

use App\Modules\HR\Hr\Models\Attendance\AttendanceClocking;
use App\Modules\HR\Hr\Models\Attendance\AttendanceRecord;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Time & Attendance module actions and the global Policy rule engine.
 *
 * Every clock-in, clock-out, overtime request, schedule deviation and manual
 * attendance override passes through this guard before hitting the database.
 *
 * Outcomes:
 *  block     → ValidationException (action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Attendance rules:
 *  module = 'Attendance'
 *  action_pattern examples: 'attendance.*', 'attendance.clock_in', 'attendance.overtime.*'
 */
class AttendancePolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against an event.
     *
     * Throws ValidationException if any rule outcome is 'block'.
     */
    public function check(PolicyEventDTO $event): array
    {
        $results = $this->ruleEngine->evaluate($event);

        $warnings           = [];
        $violationIds       = [];
        $requiresEscalation = false;

        foreach ($results as $result) {
            /** @var EvaluationResultDTO $result */
            if ($result->passed) {
                continue;
            }

            $enforced = $this->enforcementHandler->handle($result);

            if ($enforced['is_blocked'] ?? false) {
                throw ValidationException::withMessages([
                    'policy' => [$enforced['blocked_message']
                        ?? "This action is blocked by policy: {$result->rule->name}"],
                ]);
            }

            if ($enforced['requires_approval'] ?? false) {
                $requiresEscalation = true;
            }

            if (isset($enforced['violation_id'])) {
                $violationIds[] = $enforced['violation_id'];
            }

            $explanation = $enforced['explanation'] ?? null;
            if ($explanation) {
                $warnings[] = is_array($explanation)
                    ? ($explanation['summary'] ?? $result->rule->name)
                    : (string) $explanation;
            }
        }

        return [
            'warnings'            => $warnings,
            'violation_ids'       => $violationIds,
            'requires_escalation' => $requiresEscalation,
        ];
    }

    // ── Action-specific helpers ────────────────────────────────────────────────

    /**
     * Check policies when an employee clocks in.
     */
    public function forClockIn(
        int    $employeeId,
        string $method,
        string $workType,
        string $clockedAt,
        ?float $latitude = null,
        ?float $longitude = null,
        int    $actorId = 0,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Attendance',
            action:      'attendance.clock_in',
            payload:     [
                'employee_id' => $employeeId,
                'method'      => $method,
                'work_type'   => $workType,
                'clocked_at'  => $clockedAt,
                'latitude'    => $latitude,
                'longitude'   => $longitude,
            ],
            actorId:     $actorId ?: $employeeId,
            subjectId:   $employeeId,
            subjectType: AttendanceClocking::class,
        ));
    }

    /**
     * Check policies when an employee clocks out.
     */
    public function forClockOut(
        int    $employeeId,
        string $clockedAt,
        ?int   $totalMinutes = null,
        int    $actorId = 0,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Attendance',
            action:      'attendance.clock_out',
            payload:     [
                'employee_id'   => $employeeId,
                'clocked_at'    => $clockedAt,
                'total_minutes' => $totalMinutes,
            ],
            actorId:     $actorId ?: $employeeId,
            subjectId:   $employeeId,
            subjectType: AttendanceClocking::class,
        ));
    }

    /**
     * Check policies when an overtime request is submitted.
     */
    public function forOvertimeRequested(
        int    $employeeId,
        string $date,
        int    $overtimeMinutes,
        string $reason,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Attendance',
            action:      'attendance.overtime.requested',
            payload:     [
                'employee_id'      => $employeeId,
                'date'             => $date,
                'overtime_minutes' => $overtimeMinutes,
                'reason'           => $reason,
            ],
            actorId:     $actorId,
            subjectId:   $employeeId,
        ));
    }

    /**
     * Check policies when overtime is approved.
     */
    public function forOvertimeApproved(
        int    $employeeId,
        string $date,
        int    $overtimeMinutes,
        int    $approverUserId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Attendance',
            action:      'attendance.overtime.approved',
            payload:     [
                'employee_id'      => $employeeId,
                'date'             => $date,
                'overtime_minutes' => $overtimeMinutes,
            ],
            actorId:     $approverUserId,
            subjectId:   $employeeId,
        ));
    }

    /**
     * Check policies when an attendance record is manually corrected.
     */
    public function forManualCorrection(
        AttendanceRecord $record,
        array            $changes,
        int              $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Attendance',
            action:      'attendance.manual_correction',
            payload:     [
                'employee_id' => $record->employee_id,
                'record_id'   => $record->id,
                'record_date' => $record->attendance_date ?? null,
                'changes'     => $changes,
                'corrected_by' => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $record->employee_id,
            subjectType: AttendanceRecord::class,
        ));
    }

    /**
     * Check policies when an absence is recorded for an employee.
     */
    public function forAbsenceRecorded(
        int    $employeeId,
        string $date,
        string $absenceType,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Attendance',
            action:      'attendance.absence.recorded',
            payload:     [
                'employee_id'  => $employeeId,
                'date'         => $date,
                'absence_type' => $absenceType,
            ],
            actorId:     $actorId,
            subjectId:   $employeeId,
        ));
    }

    /**
     * Check policies when a schedule deviation is flagged (late arrival, early departure).
     */
    public function forScheduleDeviation(
        int    $employeeId,
        string $deviationType,
        int    $deviationMinutes,
        string $date,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Attendance',
            action:      'attendance.schedule_deviation',
            payload:     [
                'employee_id'       => $employeeId,
                'deviation_type'    => $deviationType,
                'deviation_minutes' => $deviationMinutes,
                'date'              => $date,
            ],
            actorId:     $actorId,
            subjectId:   $employeeId,
        ));
    }
}
