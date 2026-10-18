<?php

namespace App\Modules\Finance\Claims\Services;

use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between the Claims Management module and the global Policy rule engine.
 *
 * Covers claim submission, approval, rejection, and travel claim submission.
 *
 * Outcomes:
 *  block     → ValidationException (claim action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Claims rules:
 *  module = 'Claims'
 *  action_pattern examples:
 *    'claims.*'
 *    'claims.submitted'
 *    'claims.approved'
 *    'claims.rejected'
 *    'claims.travel.submitted'
 */
class ClaimsPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against a claims event.
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
                        ?? "This claim is blocked by policy: {$result->rule->name}"],
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

    // ── Claim submission ──────────────────────────────────────────────────────

    /**
     * Check policies when a claim is submitted for approval.
     *
     * Useful rules: max claim amount, receipt requirements, claim age limits,
     * duplicate claim detection, weekend/after-hours submission flags,
     * approval thresholds by category.
     */
    public function forClaimSubmitted(Claim $claim, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Claims',
            action:      'claims.submitted',
            payload:     [
                'claim_id'        => $claim->id,
                'employee_id'     => $claim->employee_id,
                'category_id'     => $claim->category_id,
                'category'        => $claim->category?->name,
                'total_amount'    => (float) $claim->total_amount,
                'currency'        => $claim->currency,
                'claim_date'      => $claim->claim_date?->toDateString(),
                'age_days'        => $claim->age_in_days,
                'items_count'     => $claim->items()->count(),
                'has_receipts'    => $claim->attachments()->where('attachment_type', 'receipt')->exists(),
                'is_travel'       => $claim->category?->is_travel ?? false,
                'is_weekend'      => $claim->claim_date?->isWeekend() ?? false,
            ],
            actorId:     $actorId,
            subjectId:   $claim->id,
            subjectType: Claim::class,
        ));
    }

    /**
     * Check policies when a claim is approved.
     *
     * Useful rules: self-approval prohibition, approval authority limits by amount,
     * mandatory second approval above a threshold.
     */
    public function forClaimApproved(Claim $claim, int $approverUserId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Claims',
            action:      'claims.approved',
            payload:     [
                'claim_id'       => $claim->id,
                'employee_id'    => $claim->employee_id,
                'approver_id'    => $approverUserId,
                'self_approval'  => $approverUserId === $claim->employee_id,
                'total_amount'   => (float) $claim->total_amount,
                'category_id'    => $claim->category_id,
                'category'       => $claim->category?->name,
                'approval_level' => $claim->approval_level,
            ],
            actorId:     $approverUserId,
            subjectId:   $claim->id,
            subjectType: Claim::class,
        ));
    }

    /**
     * Check policies when a claim is rejected.
     *
     * Useful rules: mandatory rejection reason length, repeat rejection tracking.
     */
    public function forClaimRejected(Claim $claim, string $reason, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Claims',
            action:      'claims.rejected',
            payload:     [
                'claim_id'     => $claim->id,
                'employee_id'  => $claim->employee_id,
                'approver_id'  => $actorId,
                'total_amount' => (float) $claim->total_amount,
                'reason'       => $reason,
                'category_id'  => $claim->category_id,
            ],
            actorId:     $actorId,
            subjectId:   $claim->id,
            subjectType: Claim::class,
        ));
    }

    /**
     * Check policies when a travel claim is submitted.
     *
     * Useful rules: max km per trip, max trips per period, peak-hour surcharge caps,
     * km rate limits, travel outside approved zones.
     */
    public function forTravelClaimSubmitted(Claim $claim, int $actorId): array
    {
        $trips    = $claim->travelTrips;
        $totalKm  = $trips->sum('distance_km');
        $peakTrips= $trips->where('is_peak_hour', true)->count();

        return $this->check(new PolicyEventDTO(
            module:      'Claims',
            action:      'claims.travel.submitted',
            payload:     [
                'claim_id'       => $claim->id,
                'employee_id'    => $claim->employee_id,
                'total_amount'   => (float) $claim->total_amount,
                'trips_count'    => $trips->count(),
                'total_km'       => (float) $totalKm,
                'peak_trips'     => $peakTrips,
                'km_rate'        => (float) ($trips->first()?->km_rate ?? 0),
                'has_heavy_traffic' => $trips->where('traffic_condition', 'heavy')->isNotEmpty(),
                'claim_date'     => $claim->claim_date?->toDateString(),
            ],
            actorId:     $actorId,
            subjectId:   $claim->id,
            subjectType: Claim::class,
        ));
    }

    /**
     * Check policies when a claim is marked as paid.
     *
     * Useful rules: payment authority limits, payment without approval prohibition.
     */
    public function forClaimPaid(Claim $claim, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Claims',
            action:      'claims.paid',
            payload:     [
                'claim_id'     => $claim->id,
                'employee_id'  => $claim->employee_id,
                'total_amount' => (float) $claim->total_amount,
                'category_id'  => $claim->category_id,
                'paid_by'      => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $claim->id,
            subjectType: Claim::class,
        ));
    }
}
