<?php

namespace App\Modules\Assets\Services;

use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\Assets;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Asset Management module actions and the global Policy rule engine.
 *
 * Covers asset assignment, disposal, maintenance requests, purchase requests,
 * misuse flagging and compliance checks.
 *
 * Outcomes:
 *  block     → ValidationException (action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Asset rules:
 *  module = 'Assets'
 *  action_pattern examples: 'assets.*', 'assets.assignment.*', 'assets.disposal.*'
 */
class AssetPolicyGuard
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
     * Check policies when an asset is assigned to an employee.
     */
    public function forAssetAssigned(
        AssetAssignment $assignment,
        int             $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Assets',
            action:      'assets.assignment.created',
            payload:     [
                'asset_id'      => $assignment->asset_id,
                'employee_id'   => $assignment->assigned_to,
                'assigned_date' => $assignment->effective_date ?? null,
                'asset_value'   => $assignment->asset?->current_value ?? null,
                'asset_category' => $assignment->asset?->category?->name ?? null,
            ],
            actorId:     $actorId,
            subjectId:   $assignment->asset_id,
            subjectType: Assets::class,
        ));
    }

    /**
     * Check policies when an asset assignment is revoked (return).
     */
    public function forAssetReturned(
        AssetAssignment $assignment,
        string          $condition,
        int             $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Assets',
            action:      'assets.assignment.returned',
            payload:     [
                'asset_id'      => $assignment->asset_id,
                'employee_id'   => $assignment->assigned_to,
                'return_date'   => now()->toDateString(),
                'condition'     => $condition,
            ],
            actorId:     $actorId,
            subjectId:   $assignment->asset_id,
            subjectType: Assets::class,
        ));
    }

    /**
     * Check policies when an asset disposal is requested or executed.
     */
    public function forAssetDisposed(
        int    $assetId,
        string $disposalMethod,
        ?float $disposalValue,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Assets',
            action:      'assets.disposal.executed',
            payload:     [
                'asset_id'        => $assetId,
                'disposal_method' => $disposalMethod,
                'disposal_value'  => $disposalValue,
            ],
            actorId:     $actorId,
            subjectId:   $assetId,
            subjectType: Assets::class,
        ));
    }

    /**
     * Check policies when a purchase request is submitted.
     */
    public function forPurchaseRequested(
        int    $requestedBy,
        int    $assetCategoryId,
        float  $estimatedCost,
        int    $quantity,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Assets',
            action:      'assets.purchase_request.submitted',
            payload:     [
                'requested_by'      => $requestedBy,
                'asset_category_id' => $assetCategoryId,
                'estimated_cost'    => $estimatedCost,
                'quantity'          => $quantity,
            ],
            actorId:     $actorId,
        ));
    }

    /**
     * Check policies when a maintenance request is submitted for an asset.
     */
    public function forMaintenanceRequested(
        int    $assetId,
        string $maintenanceType,
        ?float $estimatedCost,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Assets',
            action:      'assets.maintenance.requested',
            payload:     [
                'asset_id'         => $assetId,
                'maintenance_type' => $maintenanceType,
                'estimated_cost'   => $estimatedCost,
            ],
            actorId:     $actorId,
            subjectId:   $assetId,
            subjectType: Assets::class,
        ));
    }

    /**
     * Check policies when asset misuse is flagged.
     */
    public function forMisueFlagged(
        int    $assetId,
        int    $employeeId,
        string $misuseType,
        string $description,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Assets',
            action:      'assets.misuse.flagged',
            payload:     [
                'asset_id'     => $assetId,
                'employee_id'  => $employeeId,
                'misuse_type'  => $misuseType,
                'description'  => $description,
            ],
            actorId:     $actorId,
            subjectId:   $assetId,
            subjectType: Assets::class,
        ));
    }

    /**
     * Check policies when an asset incident is reported.
     */
    public function forIncidentReported(
        int    $assetId,
        string $incidentType,
        ?float $estimatedLoss,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Assets',
            action:      'assets.incident.reported',
            payload:     [
                'asset_id'       => $assetId,
                'incident_type'  => $incidentType,
                'estimated_loss' => $estimatedLoss,
            ],
            actorId:     $actorId,
            subjectId:   $assetId,
            subjectType: Assets::class,
        ));
    }
}
