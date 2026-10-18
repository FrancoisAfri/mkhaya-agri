<?php

namespace App\Modules\Finance\Claims\Services;

use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimPolicy;
use App\Modules\Finance\Claims\Models\ClaimPolicyViolation;
use Carbon\Carbon;

class ClaimsPolicyService
{
    // ── Entry point ───────────────────────────────────────────────────────────

    public function validateClaimPolicy(Claim $claim): array
    {
        $violations = [];

        $violations = array_merge($violations, $this->checkClaimLimitPolicy($claim));
        $violations = array_merge($violations, $this->checkClaimAgePolicy($claim));
        $violations = array_merge($violations, $this->checkReceiptPolicy($claim));
        $violations = array_merge($violations, $this->checkClaimApprovalPolicy($claim));

        foreach ($violations as $v) {
            ClaimPolicyViolation::firstOrCreate(
                ['claim_id' => $claim->id, 'policy_rule' => $v['rule']],
                [
                    'violation_type' => $v['type'],
                    'description'    => $v['description'],
                    'severity'       => $v['severity'],
                ]
            );
        }

        activity('claims')
            ->causedBy(auth()->user())
            ->performedOn($claim)
            ->withProperties(['violations' => count($violations)])
            ->event('policy_validated')
            ->log("Policy validation: " . count($violations) . " violation(s) found.");

        return $violations;
    }

    // ── Phase 3 functions ─────────────────────────────────────────────────────

    public function checkClaimLimitPolicy(Claim $claim): array
    {
        $violations = [];
        $policies   = ClaimPolicy::where('active', true)
            ->where('condition_type', 'amount_limit')
            ->where(fn($q) => $q->whereNull('category_id')->orWhere('category_id', $claim->category_id))
            ->get();

        foreach ($policies as $policy) {
            $limit = (float) ($policy->condition_value['limit'] ?? 0);
            if ($limit > 0 && (float) $claim->total_amount > $limit) {
                $violations[] = [
                    'rule'        => $policy->rule_key,
                    'type'        => 'amount_exceeded',
                    'description' => "Claim amount R{$claim->total_amount} exceeds the {$policy->name} limit of R{$limit}.",
                    'severity'    => $policy->action === 'block' ? 'block' : 'warning',
                ];
            }
        }

        return $violations;
    }

    public function checkClaimAgePolicy(Claim $claim): array
    {
        $violations = [];
        $policies   = ClaimPolicy::where('active', true)
            ->where('condition_type', 'age_limit')
            ->where(fn($q) => $q->whereNull('category_id')->orWhere('category_id', $claim->category_id))
            ->get();

        $ageDays = (int) $claim->claim_date->diffInDays(now());

        foreach ($policies as $policy) {
            $maxDays = (int) ($policy->condition_value['days'] ?? 30);
            if ($ageDays > $maxDays) {
                $violations[] = [
                    'rule'        => $policy->rule_key,
                    'type'        => 'age_exceeded',
                    'description' => "Claim is {$ageDays} days old, exceeding the maximum of {$maxDays} days.",
                    'severity'    => $policy->action === 'block' ? 'block' : 'warning',
                ];
            }
        }

        // Category-level max age
        if ($claim->category && $ageDays > $claim->category->max_age_days) {
            $violations[] = [
                'rule'        => 'category_age_limit',
                'type'        => 'age_exceeded',
                'description' => "Claim is {$ageDays} days old. Maximum for {$claim->category->name} is {$claim->category->max_age_days} days.",
                'severity'    => 'block',
            ];
        }

        return $violations;
    }

    public function checkReceiptPolicy(Claim $claim): array
    {
        $violations = [];

        foreach ($claim->items as $item) {
            if ($item->receipt_required && ! $item->receipt_uploaded) {
                $violations[] = [
                    'rule'        => 'missing_receipt',
                    'type'        => 'missing_receipt',
                    'description' => "Receipt missing for item: \"{$item->description}\".",
                    'severity'    => 'warning',
                ];
                break;
            }
        }

        if ($claim->category?->requires_receipt && $claim->attachments()->count() === 0) {
            $violations[] = [
                'rule'        => 'category_requires_receipt',
                'type'        => 'missing_receipt',
                'description' => "Category \"{$claim->category->name}\" requires at least one receipt.",
                'severity'    => 'warning',
            ];
        }

        return $violations;
    }

    public function checkClaimApprovalPolicy(Claim $claim): array
    {
        $violations = [];
        $policies   = ClaimPolicy::where('active', true)
            ->whereIn('condition_type', ['requires_approval', 'requires_manager_approval', 'requires_executive_approval'])
            ->where(fn($q) => $q->whereNull('category_id')->orWhere('category_id', $claim->category_id))
            ->get();

        foreach ($policies as $policy) {
            $threshold = (float) ($policy->condition_value['threshold'] ?? 0);
            if ($threshold > 0 && (float) $claim->total_amount > $threshold) {
                $violations[] = [
                    'rule'        => $policy->rule_key,
                    'type'        => 'out_of_policy',
                    'description' => "Amount R{$claim->total_amount} exceeds R{$threshold} — requires {$policy->approval_role} approval.",
                    'severity'    => 'warning',
                ];
            }
        }

        return $violations;
    }

    public function blockClaimPolicyViolation(Claim $claim): bool
    {
        return $claim->policyViolations()->where('severity', 'block')->where('exception_approved', false)->exists();
    }

    public function requestClaimPolicyException(ClaimPolicyViolation $violation, string $reason): void
    {
        $violation->update([
            'is_exception_requested' => true,
            'exception_reason'       => $reason,
        ]);

        activity('claims')
            ->causedBy(auth()->user())
            ->performedOn($violation->claim)
            ->withProperties(['violation_id' => $violation->id, 'reason' => $reason])
            ->event('exception_requested')
            ->log("Policy exception requested for violation: {$violation->violation_type}.");
    }

    public function approveClaimPolicyException(ClaimPolicyViolation $violation): void
    {
        $violation->update([
            'exception_approved' => true,
            'exception_by'       => auth()->id(),
            'exception_at'       => now(),
        ]);

        activity('claims')
            ->causedBy(auth()->user())
            ->performedOn($violation->claim)
            ->withProperties(['violation_id' => $violation->id])
            ->event('exception_approved')
            ->log("Policy exception approved for violation: {$violation->violation_type}.");
    }
}
