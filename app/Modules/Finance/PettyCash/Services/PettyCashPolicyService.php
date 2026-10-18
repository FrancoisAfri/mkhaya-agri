<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashPolicyViolation;
use App\Modules\Finance\PettyCash\Models\PettyCashReconciliation;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;

class PettyCashPolicyService
{
    private const MAX_REQUEST_AMOUNT    = 1000.00;  // R1,000 single request cap
    private const RECEIPT_DEADLINE_HOURS = 48;
    private const REPEATED_REQUESTS_WINDOW_DAYS = 7;
    private const REPEATED_REQUESTS_THRESHOLD   = 3;

    private const PROHIBITED_PURPOSES = [
        'salary', 'salaries', 'loan', 'loans', 'wage', 'wages',
        'payroll', 'bonus', 'allowance', 'reimbursement',
    ];

    public function validatePettyCashPolicy(PettyCashRequest $request): array
    {
        $violations = [];

        $limitViolation   = $this->checkPettyCashLimitPolicy($request);
        $purposeViolation = $this->checkPettyCashPurposePolicy($request);
        $repeatedViolation = $this->checkRepeatedSmallRequestsPolicy($request);

        if ($limitViolation) {
            $violations[] = $limitViolation;
        }
        if ($purposeViolation) {
            $violations[] = $purposeViolation;
        }
        if ($repeatedViolation) {
            $violations[] = $repeatedViolation;
        }

        return $violations;
    }

    public function checkPettyCashLimitPolicy(PettyCashRequest $request): ?array
    {
        if ($request->amount_requested > self::MAX_REQUEST_AMOUNT) {
            return [
                'violation_type'        => 'limit_exceeded',
                'violation_description' => "Request of R{$request->amount_requested} exceeds the single petty cash limit of R" . self::MAX_REQUEST_AMOUNT . '.',
                'severity'              => 'high',
                'policy_rule'           => 'Single petty cash request cannot exceed R' . self::MAX_REQUEST_AMOUNT,
            ];
        }

        return null;
    }

    public function checkPettyCashPurposePolicy(PettyCashRequest $request): ?array
    {
        $purposeLower = strtolower($request->purpose);

        foreach (self::PROHIBITED_PURPOSES as $prohibited) {
            if (str_contains($purposeLower, $prohibited)) {
                return [
                    'violation_type'        => 'prohibited_purpose',
                    'violation_description' => "Petty cash cannot be used for: {$request->purpose}. Prohibited keyword detected: '{$prohibited}'.",
                    'severity'              => 'critical',
                    'policy_rule'           => 'Petty cash cannot be used for salaries, loans, or payroll-related payments.',
                ];
            }
        }

        return null;
    }

    public function checkPettyCashReceiptPolicy(PettyCashRequest $request): ?array
    {
        if ($request->status === 'issued' && !$request->receipt_submitted && $request->isReceiptOverdue()) {
            return [
                'violation_type'        => 'missing_receipt',
                'violation_description' => "Receipt for request {$request->reference_number} is overdue. Due: {$request->receipt_due_at?->format('d M Y H:i')}.",
                'severity'              => 'medium',
                'policy_rule'           => 'Receipts must be uploaded within ' . self::RECEIPT_DEADLINE_HOURS . ' hours.',
            ];
        }

        return null;
    }

    public function checkCustodianControlPolicy(PettyCashReconciliation $reconciliation): ?array
    {
        if ($reconciliation->reconciled_by === $reconciliation->float->custodian_id) {
            return [
                'violation_type'        => 'self_approval',
                'violation_description' => 'Custodian is attempting to reconcile their own float without a separate approver.',
                'severity'              => 'high',
                'policy_rule'           => 'Custodian cannot approve their own reconciliation.',
            ];
        }

        return null;
    }

    public function checkFloatShortagePolicy(PettyCashFloat $float): ?array
    {
        if ($float->needsReplenishment()) {
            $threshold = $float->replenishment_threshold;
            return [
                'violation_type'        => 'float_shortage',
                'violation_description' => "Float '{$float->name}' balance of R{$float->current_balance} is at or below replenishment threshold of R{$threshold}.",
                'severity'              => 'high',
                'policy_rule'           => 'Float shortages require finance escalation.',
            ];
        }

        return null;
    }

    public function checkRepeatedSmallRequestsPolicy(PettyCashRequest $request): ?array
    {
        $recentCount = PettyCashRequest::where('requestor_id', $request->requestor_id)
            ->where('float_id', $request->float_id)
            ->where('created_at', '>=', now()->subDays(self::REPEATED_REQUESTS_WINDOW_DAYS))
            ->whereIn('status', ['pending', 'approved', 'issued', 'settled'])
            ->count();

        if ($recentCount >= self::REPEATED_REQUESTS_THRESHOLD) {
            return [
                'violation_type'        => 'repeated_requests',
                'violation_description' => "Requestor has submitted {$recentCount} requests in the past " . self::REPEATED_REQUESTS_WINDOW_DAYS . " days — pattern may indicate spending abuse.",
                'severity'              => 'medium',
                'policy_rule'           => 'Repeated small cash requests within a short period trigger investigation.',
                'metadata'              => ['request_count' => $recentCount, 'window_days' => self::REPEATED_REQUESTS_WINDOW_DAYS],
            ];
        }

        return null;
    }

    public function checkReconciliationMismatchPolicy(PettyCashReconciliation $reconciliation): ?array
    {
        if (abs($reconciliation->variance) > 0.01) {
            $direction = $reconciliation->variance < 0 ? 'shortage' : 'surplus';
            return [
                'violation_type'        => 'reconciliation_mismatch',
                'violation_description' => "Reconciliation has a {$direction} of R" . abs($reconciliation->variance) . ".",
                'severity'              => abs($reconciliation->variance) > 100 ? 'critical' : 'medium',
                'policy_rule'           => 'Reconciliation mismatches must be investigated.',
            ];
        }

        return null;
    }

    public function logViolation(array $violationData, ?int $requestId = null, ?int $reconId = null, ?int $floatId = null): PettyCashPolicyViolation
    {
        return PettyCashPolicyViolation::create([
            'request_id'            => $requestId,
            'reconciliation_id'     => $reconId,
            'float_id'              => $floatId,
            'violation_type'        => $violationData['violation_type'],
            'violation_description' => $violationData['violation_description'],
            'severity'              => $violationData['severity'],
            'policy_rule'           => $violationData['policy_rule'] ?? null,
            'metadata'              => $violationData['metadata'] ?? null,
            'status'                => 'open',
            'detected_at'           => now(),
        ]);
    }

    public function blockPettyCashPolicyViolation(PettyCashRequest $request): void
    {
        $violations = $this->validatePettyCashPolicy($request);

        $blocking = array_filter($violations, fn($v) => in_array($v['severity'], ['high', 'critical']));

        foreach ($blocking as $v) {
            $this->logViolation($v, $request->id, null, $request->float_id);
        }

        if (!empty($blocking)) {
            $messages = array_column($blocking, 'violation_description');
            abort(422, 'Request blocked by policy: ' . implode(' | ', $messages));
        }
    }

    public function requestPettyCashPolicyException(PettyCashPolicyViolation $violation, string $justification, int $requestedBy): PettyCashPolicyViolation
    {
        $violation->update([
            'status'           => 'escalated',
            'resolution_notes' => "Exception requested: {$justification}",
        ]);

        return $violation->fresh();
    }
}
