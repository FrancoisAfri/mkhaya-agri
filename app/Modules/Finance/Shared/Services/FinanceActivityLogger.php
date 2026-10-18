<?php

namespace App\Modules\Finance\Shared\Services;

use App\Modules\Finance\Shared\Models\FinanceActivityLog;
use Illuminate\Http\Request;

class FinanceActivityLogger
{
    public function log(
        string  $module,
        string  $action,
        int     $performedBy,
        string  $description,
        ?string $entityType  = null,
        ?int    $entityId    = null,
        ?int    $targetUserId = null,
        array   $metadata    = [],
        string  $riskLevel   = 'none',
        ?Request $request    = null
    ): FinanceActivityLog {
        return FinanceActivityLog::create([
            'module'        => $module,
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'performed_by'  => $performedBy,
            'target_user_id'=> $targetUserId,
            'description'   => $description,
            'ip_address'    => $request?->ip(),
            'device'        => $request ? substr($request->header('User-Agent', ''), 0, 255) : null,
            'browser'       => null,
            'metadata'      => $metadata,
            'risk_level'    => $riskLevel,
            'created_at'    => now(),
        ]);
    }

    public function logLoanActivity(string $action, int $performedBy, int $loanId, ?int $targetUserId = null, array $metadata = [], ?Request $request = null): FinanceActivityLog
    {
        return $this->log('loans', $action, $performedBy, $this->describe('loans', $action), 'StaffLoan', $loanId, $targetUserId, $metadata, $this->riskLevel('loans', $action), $request);
    }

    public function logAdvanceActivity(string $action, int $performedBy, int $advanceId, ?int $targetUserId = null, array $metadata = [], ?Request $request = null): FinanceActivityLog
    {
        return $this->log('advances', $action, $performedBy, $this->describe('advances', $action), 'SalaryAdvance', $advanceId, $targetUserId, $metadata, $this->riskLevel('advances', $action), $request);
    }

    public function logPettyCashActivity(string $action, int $performedBy, int $entityId, string $entityType = 'PettyCashRequest', array $metadata = [], ?Request $request = null): FinanceActivityLog
    {
        return $this->log('petty_cash', $action, $performedBy, $this->describe('petty_cash', $action), $entityType, $entityId, null, $metadata, 'none', $request);
    }

    public function logClaimActivity(string $action, int $performedBy, int $claimId, array $metadata = [], ?Request $request = null): FinanceActivityLog
    {
        return $this->log('claims', $action, $performedBy, $this->describe('claims', $action), 'Claim', $claimId, null, $metadata, 'none', $request);
    }

    public function logPolicyViolation(string $module, int $performedBy, int $entityId, string $entityType, array $metadata = [], ?Request $request = null): FinanceActivityLog
    {
        return $this->log('policy', 'policy_violation_detected', $performedBy, 'Policy violation detected.', $entityType, $entityId, null, $metadata, 'high', $request);
    }

    public function logAIRecommendation(string $module, int $performedBy, int $entityId, string $entityType, array $metadata = [], ?Request $request = null): FinanceActivityLog
    {
        return $this->log($module, 'ai_recommendation_generated', $performedBy, 'AI risk recommendation generated.', $entityType, $entityId, null, $metadata, 'none', $request);
    }

    private function describe(string $module, string $action): string
    {
        return ucwords(str_replace('_', ' ', $module)) . ': ' . ucwords(str_replace('_', ' ', $action));
    }

    private function riskLevel(string $module, string $action): string
    {
        if (str_contains($action, 'reject') || str_contains($action, 'default') || str_contains($action, 'violation')) {
            return 'high';
        }
        if (str_contains($action, 'approve') || str_contains($action, 'disburse')) {
            return 'medium';
        }
        return 'none';
    }
}
