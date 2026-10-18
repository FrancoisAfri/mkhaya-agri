<?php

namespace App\Modules\HR\Contracts\Services;

use App\Modules\HR\Contracts\Models\Contract;
use App\Modules\HR\Contracts\Models\SignatureRequest;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Contracts module actions and the global Policy rule engine.
 *
 * Covers contract creation, dispatch, signing and expiry handling.
 *
 * Outcomes:
 *  block     → ValidationException (action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Contract rules:
 *  module = 'Contracts'
 *  action_pattern examples: 'contracts.*', 'contracts.contract.*', 'contracts.signature.*'
 */
class ContractPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against an event.
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

    // ── Action-specific helpers ────────────────────────────────────────────────

    /**
     * Check policies when a new employment contract is created.
     */
    public function forContractCreated(Contract $contract, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Contracts',
            action:      'contracts.contract.created',
            payload:     [
                'contract_id'   => $contract->id,
                'employee_id'   => $contract->employee_id ?? null,
                'contract_type' => $contract->contract_type ?? null,
                'start_date'    => $contract->start_date ?? null,
                'end_date'      => $contract->end_date ?? null,
            ],
            actorId:     $actorId,
            subjectId:   $contract->id,
            subjectType: Contract::class,
        ));
    }

    /**
     * Check policies when a contract is dispatched / sent for signing.
     */
    public function forContractSent(Contract $contract, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Contracts',
            action:      'contracts.contract.sent',
            payload:     [
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id ?? null,
                'sent_by'     => $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $contract->id,
            subjectType: Contract::class,
        ));
    }

    /**
     * Check policies when a signature is submitted (OTP-verified signing).
     */
    public function forContractSigned(
        SignatureRequest $signatureRequest,
        int              $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Contracts',
            action:      'contracts.signature.submitted',
            payload:     [
                'signature_request_id' => $signatureRequest->id,
                'contract_id'          => $signatureRequest->contract_id,
                'signer_id'            => $signatureRequest->signer_id ?? $actorId,
            ],
            actorId:     $actorId,
            subjectId:   $signatureRequest->id,
            subjectType: SignatureRequest::class,
        ));
    }

    /**
     * Check policies when a contract expires without renewal.
     */
    public function forContractExpired(Contract $contract, int $actorId): array
    {
        return $this->check(new PolicyEventDTO(
            module:      'Contracts',
            action:      'contracts.contract.expired',
            payload:     [
                'contract_id' => $contract->id,
                'employee_id' => $contract->employee_id ?? null,
                'end_date'    => $contract->end_date ?? null,
            ],
            actorId:     $actorId,
            subjectId:   $contract->id,
            subjectType: Contract::class,
        ));
    }
}
