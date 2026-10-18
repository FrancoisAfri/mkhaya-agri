<?php

namespace App\Modules\Finance\PettyCash\Services;

use App\Modules\Finance\PettyCash\Models\PettyCashFloat;
use App\Modules\Finance\PettyCash\Models\PettyCashReconciliation;
use App\Modules\Finance\PettyCash\Models\PettyCashRequest;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between the Petty Cash module and the global Policy rule engine.
 *
 * Outcomes:
 *  block     → ValidationException (action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Petty Cash rules:
 *  module = 'PettyCash'
 *  action_pattern examples:
 *    'petty_cash.*'
 *    'petty_cash.request.submitted'
 *    'petty_cash.request.approved'
 *    'petty_cash.cash.issued'
 *    'petty_cash.reconciliation.submitted'
 *    'petty_cash.float.shortage'
 */
class PettyCashPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against a petty cash event.
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

    // ── Request submitted ─────────────────────────────────────────────────────

    /**
     * Check policies when a petty cash request is submitted.
     *
     * Useful rules:
     *  - Single request amount cap (e.g. R1,000)
     *  - Prohibited purpose keywords (salary, loan, payroll)
     *  - Repeated requests within a window
     *  - Requests just below approval limit (limit avoidance)
     */
    public function forRequestSubmitted(PettyCashRequest $request, int $actorId): array
    {
        $prohibitedKeywords = ['salary', 'loan', 'wage', 'payroll', 'bonus', 'allowance'];
        $purposeLower       = strtolower($request->purpose ?? '');
        $purposeIsProhibited = collect($prohibitedKeywords)
            ->contains(fn($kw) => str_contains($purposeLower, $kw));

        $recentRequestCount = PettyCashRequest::where('requestor_id', $request->requestor_id)
            ->where('float_id', $request->float_id)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereIn('status', ['pending', 'approved', 'issued', 'settled'])
            ->where('id', '!=', $request->id)
            ->count();

        return $this->check(new PolicyEventDTO(
            module:      'PettyCash',
            action:      'petty_cash.request.submitted',
            payload:     [
                'request_id'          => $request->id,
                'requestor_id'        => $request->requestor_id,
                'float_id'            => $request->float_id,
                'amount_requested'    => (float) $request->amount_requested,
                'purpose'             => $request->purpose,
                'purpose_is_prohibited'=> $purposeIsProhibited,
                'category'            => $request->category,
                'recent_request_count'=> $recentRequestCount,
                'is_round_amount'     => fmod((float) $request->amount_requested, 100) === 0.0,
            ],
            actorId:     $actorId,
            subjectId:   $request->id,
            subjectType: PettyCashRequest::class,
        ));
    }

    // ── Request approved ──────────────────────────────────────────────────────

    /**
     * Check policies when a petty cash request is approved.
     *
     * Useful rules:
     *  - Self-approval prohibition
     *  - Approval authority amount limits
     */
    public function forRequestApproved(PettyCashRequest $request, int $approverId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PettyCash',
            action:      'petty_cash.request.approved',
            payload:     [
                'request_id'      => $request->id,
                'requestor_id'    => $request->requestor_id,
                'approver_id'     => $approverId,
                'self_approval'   => $approverId === $request->requestor_id,
                'amount_requested'=> (float) $request->amount_requested,
                'float_id'        => $request->float_id,
            ],
            actorId:     $approverId,
            subjectId:   $request->id,
            subjectType: PettyCashRequest::class,
        ));
    }

    // ── Cash issued ───────────────────────────────────────────────────────────

    /**
     * Check policies when cash is physically issued.
     *
     * Useful rules:
     *  - Float balance below minimum after issuance
     *  - Issued without approved status
     */
    public function forCashIssued(PettyCashRequest $request, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PettyCash',
            action:      'petty_cash.cash.issued',
            payload:     [
                'request_id'     => $request->id,
                'requestor_id'   => $request->requestor_id,
                'custodian_id'   => $request->custodian_id,
                'float_id'       => $request->float_id,
                'amount_issued'  => (float) ($request->amount_approved ?? $request->amount_requested),
                'float_balance'  => (float) ($request->float?->current_balance ?? 0),
                'receipt_due_at' => $request->receipt_due_at?->toISOString(),
            ],
            actorId:     $actorId,
            subjectId:   $request->id,
            subjectType: PettyCashRequest::class,
        ));
    }

    // ── Reconciliation submitted ──────────────────────────────────────────────

    /**
     * Check policies when a reconciliation is submitted.
     *
     * Useful rules:
     *  - Custodian self-reconciliation prohibition
     *  - Float shortage escalation requirement
     *  - Missing receipt gap above threshold
     *  - Reconciliation variance above tolerance
     */
    public function forReconciliationSubmitted(PettyCashReconciliation $reconciliation, int $actorId): array
    {
        $float = $reconciliation->float;

        return $this->check(new PolicyEventDTO(
            module:      'PettyCash',
            action:      'petty_cash.reconciliation.submitted',
            payload:     [
                'reconciliation_id'  => $reconciliation->id,
                'float_id'           => $reconciliation->float_id,
                'custodian_id'       => $float?->custodian_id,
                'reconciled_by'      => $actorId,
                'self_reconciliation'=> $actorId === ($float?->custodian_id),
                'variance'           => (float) $reconciliation->variance,
                'has_shortage'       => $reconciliation->hasShortage(),
                'missing_receipt_gap'=> max(0, (float) ($reconciliation->total_issued - $reconciliation->total_receipts)),
                'cash_on_hand'       => (float) $reconciliation->cash_on_hand,
                'total_issued'       => (float) $reconciliation->total_issued,
                'total_receipts'     => (float) $reconciliation->total_receipts,
            ],
            actorId:     $actorId,
            subjectId:   $reconciliation->id,
            subjectType: PettyCashReconciliation::class,
        ));
    }

    // ── Float shortage ────────────────────────────────────────────────────────

    /**
     * Check policies when a float balance drops to replenishment threshold.
     *
     * Useful rules:
     *  - Mandatory replenishment notification
     *  - Auto-escalation when balance < minimum
     */
    public function forFloatShortage(PettyCashFloat $float, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'PettyCash',
            action:      'petty_cash.float.shortage',
            payload:     [
                'float_id'        => $float->id,
                'custodian_id'    => $float->custodian_id,
                'current_balance' => (float) $float->current_balance,
                'float_limit'     => (float) $float->float_limit,
                'threshold'       => (float) ($float->replenishment_threshold ?? 0),
                'utilization_pct' => $float->utilizationPercentage(),
            ],
            actorId:     $actorId,
            subjectId:   $float->id,
            subjectType: PettyCashFloat::class,
        ));
    }
}
