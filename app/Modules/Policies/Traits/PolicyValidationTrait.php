<?php

namespace App\Modules\Policies\Traits;

use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\EnforcementEngine\PolicyEventDispatcher;
use App\Modules\Policies\Services\ModuleActionValidator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;

/**
 * PolicyValidationTrait
 * 
 * Use this trait in module Actions to automatically validate actions against policies.
 * 
 * Example in Leave Action:
 * ```php
 * class SubmitLeaveAction {
 *     use PolicyValidationTrait;
 *     
 *     public function execute($data) {
 *         // Check policy first
 *         $this->validatePolicyAction('Leave', 'leave_request.submitted', $data);
 *         
 *         // Then execute action
 *         $leave = LeaveRequest::create($data);
 *         
 *         // Finally dispatch event for audit/logging
 *         $this->dispatchPolicyEvent('Leave', 'leave_request.submitted', $leave->id, $data);
 *     }
 * }
 * ```
 */
trait PolicyValidationTrait
{
    /**
     * Validate action against applicable policies
     * Throws exception if action is blocked by policy
     * 
     * @param string $module Module name (e.g., 'Leave', 'Claims')
     * @param string $action Action identifier (e.g., 'leave_request.submitted')
     * @param array $payload Data associated with the action
     * @param int|null $userId User performing action (default: current user)
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validatePolicyAction(
        string $module,
        string $action,
        array $payload,
        ?int $userId = null
    ): void {
        $userId ??= Auth::id();
        
        $validator = App::make(ModuleActionValidator::class);
        
        $event = new PolicyEventDTO(
            module: $module,
            action: $action,
            payload: $payload,
            actorId: $userId,
        );
        
        if (!$validator->isActionAllowed($event)) {
            $details = $validator->getViolationDetails($event);
            
            throw new \Illuminate\Validation\ValidationException(
                \Illuminate\Support\Facades\Validator::make([], [])->errors()->add(
                    'policy_violation',
                    $details['block_reason']
                )
            );
        }
    }

    /**
     * Dispatch policy event for audit trail and violation recording
     * Call after successful action execution
     * 
     * @param string $module Module name
     * @param string $action Action identifier
     * @param int|string|null $subjectId ID of affected resource
     * @param array $payload Additional data to store
     * @param string|null $subjectType Full model class name (optional, for polymorphic storage)
     * @param int|null $userId User performing action (default: current user)
     */
    protected function dispatchPolicyEvent(
        string $module,
        string $action,
        int|string|null $subjectId = null,
        array $payload = [],
        ?string $subjectType = null,
        ?int $userId = null
    ): void {
        $userId ??= Auth::id();
        
        $dispatcher = App::make(PolicyEventDispatcher::class);
        
        $event = PolicyEventDTO::from([
            'module' => $module,
            'action' => $action,
            'subject_id' => $subjectId,
            'subject_type' => $subjectType,
            'actor_id' => $userId,
            'payload' => array_merge($payload, [
                'timestamp' => now()->toIso8601String(),
                'ip_address' => request()->ip(),
            ]),
        ]);
        
        $dispatcher->dispatch($event);
    }

    /**
     * Validate AND dispatch in one call (convenience method)
     * Use this if you want to do validation and auditing in a single method
     * 
     * @param string $module
     * @param string $action
     * @param array $data
     * @param int|null $subjectId
     * @param string|null $subjectType
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function validateAndDispatchPolicyEvent(
        string $module,
        string $action,
        array $data,
        ?int $subjectId = null,
        ?string $subjectType = null,
    ): void {
        $this->validatePolicyAction($module, $action, $data);
        $this->dispatchPolicyEvent($module, $action, $subjectId, $data, $subjectType);
    }

    /**
     * Get violation details without throwing exception
     * Useful for frontend validation before submission
     * 
     * @param string $module
     * @param string $action
     * @param array $payload
     * @param int|null $userId
     * @return array With keys: violations, is_blocked, block_reason
     */
    protected function getPolicyViolations(
        string $module,
        string $action,
        array $payload,
        ?int $userId = null
    ): array {
        $userId ??= Auth::id();
        
        $validator = App::make(ModuleActionValidator::class);
        
        $event = new PolicyEventDTO(
            module: $module,
            action: $action,
            payload: $payload,
            actorId: $userId,
        );
        
        return $validator->getViolationDetails($event);
    }
}
