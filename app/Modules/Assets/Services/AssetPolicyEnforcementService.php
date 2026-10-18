<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\AssetPolicyEnforcementLog;
use App\Modules\Assets\Models\AssetPolicyException;
use App\Modules\Assets\Models\AssetPolicyViolation;
use Illuminate\Support\Facades\DB;

/**
 * M9 — Policy Enforcement Service
 * 
 * Bridges to your existing PolicyOS module.
 * Enforces asset-specific policies and logs all checks.
 */
class AssetPolicyEnforcementService
{
    public function checkPolicy(string $actionType, array $context): array
    {
        $result = [
            'allowed'    => true,
            'violations' => [],
            'warnings'   => [],
        ];

        // Check for active exceptions first
        if ($this->hasActiveException($context['asset_id'] ?? null, $context['user_id'])) {
            $this->logEnforcement($actionType, $context, 'exception_granted');
            return $result;
        }

        // Run policy checks based on action type
        $checks = match ($actionType) {
            'assignment' => $this->checkAssignmentPolicies($context),
            'transfer'   => $this->checkTransferPolicies($context),
            'disposal'   => $this->checkDisposalPolicies($context),
            default      => ['allowed' => true, 'violations' => []],
        };

        if (!$checks['allowed']) {
            $result['allowed'] = false;
            $result['violations'] = $checks['violations'];

            // Log violation
            foreach ($checks['violations'] as $violation) {
                $this->recordViolation($context['asset_id'], $context['user_id'], $violation);
            }

            $this->logEnforcement($actionType, $context, 'failed');
        } else {
            $this->logEnforcement($actionType, $context, 'passed');
        }

        return $result;
    }

    private function checkAssignmentPolicies(array $context): array
    {
        $violations = [];

        // Rule: Max 5 assets per user
        $currentAssignments = DB::table('asset_assignments')
            ->where('assigned_to', $context['user_id'])
            ->where('is_current', true)
            ->count();

        if ($currentAssignments >= 5) {
            $violations[] = [
                'type'        => 'assignment_limit',
                'severity'    => 'high',
                'description' => "User already has {$currentAssignments} assets assigned (limit: 5)",
            ];
        }

        // Rule: High-value assets require manager approval
        if (($context['asset_value'] ?? 0) > 50000) {
            if (!($context['has_approval'] ?? false)) {
                $violations[] = [
                    'type'        => 'missing_approval',
                    'severity'    => 'critical',
                    'description' => 'Assets over R50,000 require manager approval',
                ];
            }
        }

        return [
            'allowed'    => empty($violations),
            'violations' => $violations,
        ];
    }

    private function checkTransferPolicies(array $context): array
    {
        $violations = [];

        // Rule: Transfers require approval
        if (!($context['has_approval'] ?? false)) {
            $violations[] = [
                'type'        => 'missing_approval',
                'severity'    => 'high',
                'description' => 'Asset transfers require approval',
            ];
        }

        return [
            'allowed'    => empty($violations),
            'violations' => $violations,
        ];
    }

    private function checkDisposalPolicies(array $context): array
    {
        $violations = [];

        // Rule: Cannot dispose assets with book value > 0 without approval
        if (($context['book_value'] ?? 0) > 0 && !($context['has_approval'] ?? false)) {
            $violations[] = [
                'type'        => 'value_threshold',
                'severity'    => 'critical',
                'description' => 'Cannot dispose assets with remaining book value without approval',
            ];
        }

        return [
            'allowed'    => empty($violations),
            'violations' => $violations,
        ];
    }

    private function hasActiveException(?int $assetId, int $userId): bool
    {
        if (!$assetId) {
            return false;
        }

        return AssetPolicyException::where('asset_id', $assetId)
            ->where('status', 'approved')
            ->where('valid_from', '<=', now())
            ->where('valid_until', '>=', now())
            ->exists();
    }

    private function recordViolation(int $assetId, int $userId, array $violation): void
    {
        AssetPolicyViolation::create([
            'asset_id'            => $assetId,
            'user_id'             => $userId,
            'violation_type'      => $violation['type'],
            'severity'            => $violation['severity'],
            'detected_at'         => now(),
            'description'         => $violation['description'],
            'action_blocked'      => true,
            'status'              => 'open',
        ]);
    }

    private function logEnforcement(string $actionType, array $context, string $result): void
    {
        AssetPolicyEnforcementLog::create([
            'asset_id'        => $context['asset_id'] ?? null,
            'user_id'         => $context['user_id'],
            'action_type'     => $actionType,
            'check_result'    => $result,
            'checked_at'      => now(),
            'context'         => $context,
        ]);
    }
}
