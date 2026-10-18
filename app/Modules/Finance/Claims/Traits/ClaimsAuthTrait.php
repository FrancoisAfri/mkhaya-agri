<?php

namespace App\Modules\Finance\Claims\Traits;

use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimApprovalDelegation;
use App\Modules\Finance\Claims\Models\ClaimPaymentProcessor;
use Illuminate\Support\Facades\DB;

trait ClaimsAuthTrait
{
    protected const CLAIMS_ADMIN_ROLES = ['super admin', 'administrator', 'finance manager', 'HR'];
    protected const CLAIMS_APPROVER_ROLES = ['super admin', 'administrator', 'finance manager', 'manager', 'senior manager', 'executive', 'HR'];
    protected const CLAIMS_DASHBOARD_ROLES = ['super admin', 'administrator', 'finance manager', 'HR', 'executive', 'senior manager'];

    protected function isClaimsAdmin(): bool
    {
        return auth()->user()->hasRole(self::CLAIMS_ADMIN_ROLES);
    }

    protected function canViewClaimsDashboard(): bool
    {
        return auth()->user()->hasRole(self::CLAIMS_DASHBOARD_ROLES);
    }

    protected function isClaimsApprover(): bool
    {
        return auth()->user()->hasRole(self::CLAIMS_APPROVER_ROLES);
    }

    protected function isPaymentProcessor(): bool
    {
        if ($this->isClaimsAdmin()) return true;

        $userId    = auth()->id();
        $userRoles = auth()->user()->getRoleNames()->toArray();

        return ClaimPaymentProcessor::where('is_active', true)
            ->where(function ($q) use ($userId, $userRoles) {
                $q->where(function ($sq) use ($userRoles) {
                    $sq->where('processor_type', 'role')->whereIn('role_name', $userRoles);
                })->orWhere(function ($sq) use ($userId) {
                    $sq->where('processor_type', 'user')->where('user_id', $userId);
                });
            })
            ->exists();
    }

    protected function canApproveClaim(Claim $claim): bool
    {
        $userId = auth()->id();

        // No one can approve their own claim — checked before any role bypass
        if ($claim->employee_id === $userId) return false;

        // Admins can act on any claim regardless of the designated approver step
        if (auth()->user()->hasRole(self::CLAIMS_ADMIN_ROLES)) return true;

        // Must be in a submittable status
        if (! in_array($claim->status, ['submitted', 'under_review'])) return false;

        // Must be the designated approver for the current step
        if ($claim->current_approver_id === $userId) return true;

        // Or an active delegate of the designated approver
        if ($claim->current_approver_id && $this->isActiveDelegateOf($claim->current_approver_id, $userId)) {
            return true;
        }

        return false;
    }

    private function isActiveDelegateOf(int $delegatorId, int $delegateId): bool
    {
        return ClaimApprovalDelegation::where('delegator_id', $delegatorId)
            ->where('delegate_id', $delegateId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date',   '>=', now()->toDateString())
            ->exists();
    }

    protected function canViewClaim(Claim $claim): bool
    {
        $userId = auth()->id();
        if ($this->isClaimsAdmin()) return true;
        if ($claim->employee_id === $userId) return true;
        if ($claim->current_approver_id === $userId) return true;
        if ($this->isDirectManagerOf($claim->employee_id)) return true;
        return false;
    }

    protected function scopedClaimQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $userId = auth()->id();

        if ($this->isClaimsAdmin()) {
            return Claim::query();
        }

        $subordinateIds = $this->getSubordinateUserIds($userId);

        return Claim::where(function ($q) use ($userId, $subordinateIds) {
            $q->where('employee_id', $userId)
              ->orWhere('current_approver_id', $userId);
            if (! empty($subordinateIds)) {
                $q->orWhereIn('employee_id', $subordinateIds);
            }
        });
    }

    private function isDirectManagerOf(?int $employeeId): bool
    {
        if (! $employeeId) return false;
        $myPersonId  = DB::table('hr_people')->where('user_id', auth()->id())->value('id');
        if (! $myPersonId) return false;
        $empPersonId = DB::table('hr_people')->where('user_id', $employeeId)->value('id');
        if (! $empPersonId) return false;
        return DB::table('hr_people')->where('id', $empPersonId)->where('manager_id', $myPersonId)->exists();
    }

    private function getSubordinateUserIds(int $userId): array
    {
        $myPersonId = DB::table('hr_people')->where('user_id', $userId)->value('id');
        if (! $myPersonId) return [];

        $personIds = [$myPersonId];
        $queue     = [$myPersonId];

        while (! empty($queue)) {
            $children    = DB::table('hr_people')->whereIn('manager_id', $queue)->pluck('id')->toArray();
            $newChildren = array_diff($children, $personIds);
            if (empty($newChildren)) break;
            $personIds = array_merge($personIds, $newChildren);
            $queue     = array_values($newChildren);
        }

        return DB::table('hr_people')
            ->whereIn('id', $personIds)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $userId)
            ->pluck('user_id')
            ->toArray();
    }
}
