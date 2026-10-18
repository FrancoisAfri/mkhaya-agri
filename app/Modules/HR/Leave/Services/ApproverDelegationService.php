<?php

namespace App\Modules\HR\Leave\Services;

use App\Modules\HR\Leave\Models\ApproverDelegation;
use App\Modules\HR\Leave\Models\LeaveConfiguration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ApproverDelegationService
{
    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Create a new temporary delegation, preventing circular chains.
     *
     * @throws ValidationException if a circular delegation would result, or
     *                             if the delegate already has an active delegation
     *                             for the same period pointing back to delegator.
     */
    public function delegate(
        int     $delegatorId,
        int     $delegateId,
        string  $startDate,
        string  $endDate,
        ?string $reason,
        int     $createdBy,
    ): ApproverDelegation {
        if ($delegatorId === $delegateId) {
            throw ValidationException::withMessages([
                'delegate_id' => 'A user cannot delegate to themselves.',
            ]);
        }

        $this->preventCircularDelegation($delegatorId, $delegateId, $startDate, $endDate);

        $requiresApproval = LeaveConfiguration::first()?->require_delegation_approval ?? false;

        // Deactivate any prior overlapping delegation for the same delegator
        ApproverDelegation::where('delegator_id', $delegatorId)
            ->where('is_active', true)
            ->where('start_date', '<=', $endDate)
            ->where('end_date',   '>=', $startDate)
            ->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $createdBy]);

        return ApproverDelegation::create([
            'delegator_id'    => $delegatorId,
            'delegate_id'     => $delegateId,
            'start_date'      => $startDate,
            'end_date'        => $endDate,
            'reason'          => $reason,
            'is_active'       => ! $requiresApproval,
            'approval_status' => $requiresApproval ? 'pending' : 'approved',
            'created_by'      => $createdBy,
        ]);
    }

    public function revoke(int $delegationId, int $revokedBy): void
    {
        ApproverDelegation::where('id', $delegationId)
            ->where('is_active', true)
            ->update([
                'is_active'  => false,
                'revoked_at' => now(),
                'revoked_by' => $revokedBy,
            ]);
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    /**
     * Return the effective approver user ID for $userId on the given date.
     * If an active delegation exists, returns the delegate's ID. Otherwise
     * returns the original $userId unchanged.
     */
    public function resolveApprover(int $userId, ?Carbon $on = null): int
    {
        $on ??= now();

        $delegation = ApproverDelegation::where('delegator_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', $on->toDateString())
            ->where('end_date',   '>=', $on->toDateString())
            ->latest('id')
            ->first();

        return $delegation?->delegate_id ?? $userId;
    }

    /**
     * Whether $userId has an active outbound delegation today.
     */
    public function hasActiveDelegation(int $userId): bool
    {
        return $this->resolveApprover($userId) !== $userId;
    }

    /**
     * All delegations (both active and historical) for a user, for audit log display.
     */
    public function auditLog(int $userId): Collection
    {
        return ApproverDelegation::where(fn($q) => $q
            ->where('delegator_id', $userId)
            ->orWhere('delegate_id', $userId)
        )
        ->with(['delegator', 'delegate', 'revokedByUser'])
        ->latest()
        ->get();
    }

    /**
     * Active delegation for a specific user (outbound — user is delegating to someone).
     */
    public function activeDelegation(int $userId): ?ApproverDelegation
    {
        return ApproverDelegation::activeForUser($userId)->latest('id')->first();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Prevent A→B when B→A (or B→C→A) already exists for an overlapping period.
     * Simple one-hop check is sufficient since deeper chains collapse per-delegate.
     */
    private function preventCircularDelegation(
        int    $delegatorId,
        int    $delegateId,
        string $startDate,
        string $endDate,
    ): void {
        // Check if the intended delegate is already delegating back to the delegator
        $reverse = ApproverDelegation::where('delegator_id', $delegateId)
            ->where('delegate_id', $delegatorId)
            ->where('is_active', true)
            ->where('start_date', '<=', $endDate)
            ->where('end_date',   '>=', $startDate)
            ->exists();

        if ($reverse) {
            throw ValidationException::withMessages([
                'delegate_id' => 'Circular delegation detected: the selected delegate has already delegated their authority back to you during this period.',
            ]);
        }
    }
}
