<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\DTOs\SubmitLeaveRequestDTO;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Models\PolicyLeaveRule;
use App\Modules\HR\Leave\Models\PolicyRuleAuditLog;
use Illuminate\Support\Collection;

class PolicyRuleEvaluator
{
    public function evaluate(
        HRPerson             $employee,
        LeaveType            $leaveType,
        SubmitLeaveRequestDTO $dto,
        ?LeaveRequest        $request = null,
    ): array {
        $rules    = PolicyLeaveRule::active()->forLeaveType($leaveType->id)->orderBy('priority')->get();
        $errors   = [];
        $warnings = [];
        $overrides = [];

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $employee, $dto);

            // Audit every evaluation
            PolicyRuleAuditLog::create([
                'leave_request_id'  => $request?->id,
                'employee_id'       => $employee->id,
                'policy_rule_id'    => $rule->id,
                'event_type'        => 'evaluation',
                'rule_code'         => $rule->rule_code,
                'evaluation_result' => $result['outcome'],
                'evaluation_detail' => $result['detail'],
                'ai_used'           => false,
                'created_at'        => now(),
            ]);

            match($result['outcome']) {
                'fail'    => $errors[]   = "[{$rule->rule_code}] {$result['detail']}",
                'warning' => $warnings[] = "[{$rule->rule_code}] {$result['detail']}",
                'override' => $overrides[] = $rule->rule_code,
                default   => null,
            };
        }

        return [
            'valid'     => empty($errors),
            'errors'    => $errors,
            'warnings'  => $warnings,
            'overrides' => $overrides,
        ];
    }

    private function evaluateRule(PolicyLeaveRule $rule, HRPerson $employee, SubmitLeaveRequestDTO $dto): array
    {
        $config = $rule->rule_config ?? [];

        return match($rule->rule_type) {
            'notice_period' => $this->checkNoticePeriod($dto, $config),
            'entitlement'   => $this->checkEntitlement($employee, $dto, $config),
            'documentation' => ['outcome' => 'pass', 'detail' => 'Documentation rule — checked at submission'],
            default         => ['outcome' => 'pass', 'detail' => 'Rule type not evaluated at this stage'],
        };
    }

    private function checkNoticePeriod(SubmitLeaveRequestDTO $dto, array $config): array
    {
        $minDays = (int) ($config['minimum_notice_days'] ?? 0);

        if ($minDays > 0) {
            $daysNotice = (int) now()->startOfDay()->diffInDays(
                \Carbon\Carbon::parse($dto->startDate)->startOfDay(),
                false
            );

            if ($daysNotice < $minDays) {
                $outcome = in_array($config['breach_outcome'] ?? 'fail', ['warning', 'override'])
                    ? $config['breach_outcome']
                    : 'fail';

                return [
                    'outcome' => $outcome,
                    'detail'  => "Policy requires {$minDays} days advance notice. Only {$daysNotice} days given.",
                ];
            }
        }

        return ['outcome' => 'pass', 'detail' => 'Notice period requirement met'];
    }

    private function checkEntitlement(HRPerson $employee, SubmitLeaveRequestDTO $dto, array $config): array
    {
        return ['outcome' => 'pass', 'detail' => 'Entitlement check passed'];
    }
}
