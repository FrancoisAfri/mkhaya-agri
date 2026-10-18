<?php

namespace App\Modules\Finance\Claims\Services;

use App\Modules\Finance\Claims\Models\ClaimApprovalDelegation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ClaimsApprovalDelegationService
{
    // ── Write ─────────────────────────────────────────────────────────────────

    /**
     * Create a delegation, preventing self-delegation and circular chains.
     *
     * Any prior overlapping active delegation for the same delegator is
     * automatically revoked before the new one is created.
     */
    public function delegate(
        int     $delegatorId,
        int     $delegateId,
        string  $startDate,
        string  $endDate,
        ?string $reason,
        int     $createdBy,
    ): ClaimApprovalDelegation {
        if ($delegatorId === $delegateId) {
            throw ValidationException::withMessages([
                'delegate_id' => 'A user cannot delegate to themselves.',
            ]);
        }

        $this->preventCircularDelegation($delegatorId, $delegateId, $startDate, $endDate);

        // Deactivate any overlapping outbound delegation for this delegator
        ClaimApprovalDelegation::where('delegator_id', $delegatorId)
            ->where('is_active', true)
            ->where('start_date', '<=', $endDate)
            ->where('end_date',   '>=', $startDate)
            ->update(['is_active' => false, 'revoked_at' => now(), 'revoked_by' => $createdBy]);

        return ClaimApprovalDelegation::create([
            'delegator_id' => $delegatorId,
            'delegate_id'  => $delegateId,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'reason'       => $reason,
            'is_active'    => true,
            'created_by'   => $createdBy,
        ]);
    }

    public function revoke(int $delegationId, int $revokedBy): void
    {
        ClaimApprovalDelegation::where('id', $delegationId)
            ->where('is_active', true)
            ->update([
                'is_active'  => false,
                'revoked_at' => now(),
                'revoked_by' => $revokedBy,
            ]);
    }

    // ── Queries ───────────────────────────────────────────────────────────────

    /**
     * Return the effective approver ID for $userId on the given date.
     * If an active delegation exists the delegate's ID is returned,
     * otherwise $userId is returned unchanged.
     */
    public function resolveApprover(int $userId, ?Carbon $on = null): int
    {
        $on ??= now();

        $delegation = ClaimApprovalDelegation::where('delegator_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', $on->toDateString())
            ->where('end_date',   '>=', $on->toDateString())
            ->latest('id')
            ->first();

        return $delegation?->delegate_id ?? $userId;
    }

    /**
     * IDs of users who have currently delegated their authority TO $userId.
     * Used to expand the approval queue for delegates.
     */
    public function delegatingTo(int $userId): array
    {
        return ClaimApprovalDelegation::where('delegate_id', $userId)
            ->where('is_active', true)
            ->where('start_date', '<=', now()->toDateString())
            ->where('end_date',   '>=', now()->toDateString())
            ->pluck('delegator_id')
            ->toArray();
    }

    /** The current active outbound delegation for $userId, if any. */
    public function activeDelegation(int $userId): ?ClaimApprovalDelegation
    {
        return ClaimApprovalDelegation::activeForUser($userId)->with('delegate.person')->latest('id')->first();
    }

    /** Full audit log (both directions) for $userId. */
    public function auditLog(int $userId): Collection
    {
        return ClaimApprovalDelegation::where(fn($q) => $q
            ->where('delegator_id', $userId)
            ->orWhere('delegate_id', $userId)
        )
        ->with(['delegator', 'delegate', 'revokedByUser'])
        ->latest()
        ->get();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function preventCircularDelegation(
        int    $delegatorId,
        int    $delegateId,
        string $startDate,
        string $endDate,
    ): void {
        $reverse = ClaimApprovalDelegation::where('delegator_id', $delegateId)
            ->where('delegate_id', $delegatorId)
            ->where('is_active', true)
            ->where('start_date', '<=', $endDate)
            ->where('end_date',   '>=', $startDate)
            ->exists();

        if ($reverse) {
            throw ValidationException::withMessages([
                'delegate_id' => 'Circular delegation detected: the selected delegate has already delegated their approval authority back to you during this period.',
            ]);
        }
    }
}
