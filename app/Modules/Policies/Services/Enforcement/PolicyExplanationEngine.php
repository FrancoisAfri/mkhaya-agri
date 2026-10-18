<?php

namespace App\Modules\Policies\Services\Enforcement;

use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\Models\PolicyViolation;

/**
 * PolicyExplanationEngine
 * 
 * Generates human-readable explanations for policy violations.
 * Output for every violation includes:
 * - Which policy triggered the violation
 * - Which clause/rule was violated
 * - Why the rule was violated (conditions that failed)
 * - What restrictions are in place
 * - What the user can do about it
 * 
 * Used by:
 * - UI to show error messages
 * - Notifications to explain violations
 * - Audit logs for traceability
 * - Appeal/exception workflows
 */
class PolicyExplanationEngine
{
    /**
     * Generate explanation for a violation
     * 
     * @param EvaluationResultDTO $result
     * @param int|null $violationId For linking to violation record
     * @return array
     */
    public function generateExplanation(EvaluationResultDTO $result, ?int $violationId = null): array
    {
        return [
            'title' => $this->generateTitle($result),
            'policy' => $this->getPolicyExplanation($result),
            'clause' => $this->getClauseExplanation($result),
            'rule' => $this->getRuleExplanation($result),
            'conditions' => $this->getConditionsExplanation($result),
            'impact' => $this->getImpactExplanation($result),
            'actions' => $this->getAvailableActions($result),
            'support' => $this->getSupportInfo($result),
            'violation_id' => $violationId,
        ];
    }

    /**
     * Generate short title for the violation
     * 
     * @param EvaluationResultDTO $result
     * @return string
     */
    private function generateTitle(EvaluationResultDTO $result): string
    {
        $action = match ($result->outcome) {
            'block' => 'Action Blocked',
            'escalate' => 'Action Requires Approval',
            'warn' => 'Policy Warning',
            'log_only' => 'Policy Noted',
            default => 'Policy Check',
        };

        return "$action: {$result->rule->name}";
    }

    /**
     * Get policy-level explanation
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function getPolicyExplanation(EvaluationResultDTO $result): array
    {
        $policy = $result->rule->policy;

        return [
            'id' => $policy->id,
            'name' => $policy->name,
            'description' => $policy->description,
            'module' => $policy->module,
            'effective_date' => $policy->effective_date?->format('Y-m-d'),
            'owner' => $policy->owner?->name,
            'summary' => "This action has been evaluated against the {$policy->name} policy.",
        ];
    }

    /**
     * Get clause-level explanation
     * Shows which part of the document triggered the rule
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function getClauseExplanation(EvaluationResultDTO $result): array
    {
        $clause = $result->rule->clause;

        if (!$clause) {
            return [
                'id' => null,
                'title' => 'Policy Rule',
                'text' => 'This rule was created from policy requirements.',
                'source' => 'derived',
            ];
        }

        return [
            'id' => $clause->id,
            'title' => $clause->title,
            'text' => $clause->content,
            'source' => 'extracted',
            'ai_confidence' => $clause->ai_confidence,
            'summary' => "Based on: {$clause->title}",
        ];
    }

    /**
     * Get rule-level explanation
     * Shows what specific rule was triggered
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function getRuleExplanation(EvaluationResultDTO $result): array
    {
        $rule = $result->rule;

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'enforcement_action' => $rule->action_on_breach,
            'severity' => $rule->severity,
            'requires_exception' => $rule->requires_exception_approval,
            'explanation' => "Rule: {$rule->name}. {$rule->description}",
        ];
    }

    /**
     * Get detailed conditions explanation
     * Shows what triggered the rule
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function getConditionsExplanation(EvaluationResultDTO $result): array
    {
        $rule = $result->rule;
        $payload = $result->event->payload;

        $conditions = $rule->conditions->map(function ($condition) use ($payload) {
            $fieldValue = data_get($payload, $condition->field);

            return [
                'field' => $this->getHumanReadableField($condition->field),
                'operator' => $this->getHumanReadableOperator($condition->operator),
                'expected_value' => $condition->value,
                'actual_value' => $fieldValue,
                'matched' => $this->evaluateCondition($fieldValue, $condition->operator, $condition->value),
                'explanation' => $this->getConditionExplanation($condition, $fieldValue),
            ];
        })->toArray();

        return [
            'count' => count($conditions),
            'all_conditions' => $conditions,
            'summary' => $this->getConditionsSummary($conditions),
        ];
    }

    /**
     * Get impact explanation
     * Shows what happens as a result of the violation
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function getImpactExplanation(EvaluationResultDTO $result): array
    {
        $outcome = match ($result->outcome) {
            'block' => [
                'status' => 'blocked',
                'impact' => 'Your action has been blocked and will not be processed.',
                'icon' => 'ban',
            ],
            'escalate' => [
                'status' => 'pending_approval',
                'impact' => 'Your action has been escalated for manager approval.',
                'icon' => 'alert-circle',
            ],
            'warn' => [
                'status' => 'allowed_with_warning',
                'impact' => 'Your action is allowed but has been flagged for review.',
                'icon' => 'alert-triangle',
            ],
            'log_only' => [
                'status' => 'logged',
                'impact' => 'Your action has been noted for audit purposes.',
                'icon' => 'info',
            ],
            default => [
                'status' => 'unknown',
                'impact' => 'Your action has been evaluated against company policies.',
                'icon' => 'help-circle',
            ],
        };

        return $outcome;
    }

    /**
     * Get available actions the user can take
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function getAvailableActions(EvaluationResultDTO $result): array
    {
        $actions = [];

        // All violations can be appealed (if exception approval is required)
        if ($result->rule->requires_exception_approval) {
            $actions[] = [
                'type' => 'request_exception',
                'label' => 'Request Exception',
                'description' => 'Request approval to proceed despite this violation',
                'icon' => 'edit',
            ];
        }

        // Can always view more details
        $actions[] = [
            'type' => 'view_policy',
            'label' => 'View Policy',
            'description' => 'Read the full policy document',
            'icon' => 'book',
        ];

        // Can contact support
        $actions[] = [
            'type' => 'contact_support',
            'label' => 'Contact Support',
            'description' => 'Get help from your HR department',
            'icon' => 'help-circle',
        ];

        return $actions;
    }

    /**
     * Get support contact information
     * 
     * @param EvaluationResultDTO $result
     * @return array
     */
    private function getSupportInfo(EvaluationResultDTO $result): array
    {
        $owner = $result->rule->policy->owner;

        return [
            'policy_owner' => [
                'name' => $owner->name ?? 'Not assigned',
                'email' => $owner->email ?? null,
                'department' => $owner->department?->name ?? null,
            ],
            'help_text' => 'If you believe this is an error or need further clarification, please contact your policy owner or HR department.',
            'policy_doc_link' => route('policies.show', ['policy' => $result->rule->policy->uuid]),
        ];
    }

    /**
     * Evaluate a single condition
     * 
     * @param mixed $value
     * @param string $operator
     * @param mixed $expected
     * @return bool
     */
    private function evaluateCondition(mixed $value, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=' => $value == $expected,
            '!=' => $value != $expected,
            '>' => $value > $expected,
            '>=' => $value >= $expected,
            '<' => $value < $expected,
            '<=' => $value <= $expected,
            'in' => in_array($value, (array) $expected),
            'not_in' => !in_array($value, (array) $expected),
            'contains' => str_contains((string) $value, (string) $expected),
            'not_contains' => !str_contains((string) $value, (string) $expected),
            default => false,
        };
    }

    /**
     * Get human-readable explanation of a condition
     * 
     * @param mixed $condition
     * @param mixed $actualValue
     * @return string
     */
    private function getConditionExplanation(mixed $condition, mixed $actualValue): string
    {
        $field = $this->getHumanReadableField($condition->field);
        $operator = $this->getHumanReadableOperator($condition->operator);
        $value = $condition->value;

        return "{field} must be {operator} {value}, but got {actual}";
    }

    /**
     * Get summary of all conditions
     * 
     * @param array $conditions
     * @return string
     */
    private function getConditionsSummary(array $conditions): string
    {
        $failedCount = collect($conditions)->where('matched', false)->count();

        if ($failedCount === 0) {
            return 'All conditions were met.';
        } elseif ($failedCount === 1) {
            return 'One condition failed.';
        } else {
            return "$failedCount conditions failed.";
        }
    }

    /**
     * Convert field names to human-readable format
     * 
     * @param string $field
     * @return string
     */
    private function getHumanReadableField(string $field): string
    {
        return match ($field) {
            'days_requested' => 'Days Requested',
            'amount' => 'Amount',
            'leave_type_id' => 'Leave Type',
            'department_id' => 'Department',
            'user_id' => 'User',
            default => ucwords(str_replace('_', ' ', $field)),
        };
    }

    /**
     * Convert operators to human-readable format
     * 
     * @param string $operator
     * @return string
     */
    private function getHumanReadableOperator(string $operator): string
    {
        return match ($operator) {
            '=' => 'equal to',
            '!=' => 'not equal to',
            '>' => 'greater than',
            '>=' => 'at least',
            '<' => 'less than',
            '<=' => 'at most',
            'in' => 'one of',
            'not_in' => 'not one of',
            'contains' => 'contains',
            'not_contains' => 'does not contain',
            default => $operator,
        };
    }

    /**
     * Get full explanation as plain text
     * Useful for emails, notifications, etc.
     * 
     * @param EvaluationResultDTO $result
     * @return string
     */
    public function getPlainTextExplanation(EvaluationResultDTO $result): string
    {
        $explanation = $this->generateExplanation($result);

        $text = "Policy Violation Notice\n";
        $text .= "=======================\n\n";
        $text .= "Title: {$explanation['title']}\n\n";
        $text .= "Policy: {$explanation['policy']['name']}\n";
        $text .= "Rule: {$explanation['rule']['name']}\n\n";
        $text .= "What Happened:\n";
        $text .= "{$explanation['impact']['impact']}\n\n";
        $text .= "Conditions:\n";
        foreach ($explanation['conditions']['all_conditions'] as $cond) {
            $status = $cond['matched'] ? '✓' : '✗';
            $text .= "  $status {$cond['field']} {$cond['operator']} {$cond['expected_value']}\n";
        }

        $text .= "\nWhat You Can Do:\n";
        foreach ($explanation['actions'] as $action) {
            $text .= "  • {$action['label']}: {$action['description']}\n";
        }

        $text .= "\nJava Support:\n";
        $text .= "  Policy Owner: {$explanation['support']['policy_owner']['name']}\n";
        if ($explanation['support']['policy_owner']['email']) {
            $text .= "  Email: {$explanation['support']['policy_owner']['email']}\n";
        }

        return $text;
    }

    /**
     * Get HTML explanation for web display
     * 
     * @param EvaluationResultDTO $result
     * @param int|null $violationId
     * @return string
     */
    public function getHtmlExplanation(EvaluationResultDTO $result, ?int $violationId = null): string
    {
        $explanation = $this->generateExplanation($result, $violationId);

        $html = '<div class="policy-violation-explanation">';
        $html .= '<h2>' . htmlspecialchars($explanation['title']) . '</h2>';

        $html .= '<div class="policy-section">';
        $html .= '<h3>Policy</h3>';
        $html .= '<p><strong>' . htmlspecialchars($explanation['policy']['name']) . '</strong></p>';
        $html .= '<p>' . htmlspecialchars($explanation['policy']['description'] ?? '') . '</p>';
        $html .= '</div>';

        $html .= '<div class="rule-section">';
        $html .= '<h3>Rule</h3>';
        $html .= '<p><strong>' . htmlspecialchars($explanation['rule']['name']) . '</strong></p>';
        $html .= '<p>' . htmlspecialchars($explanation['rule']['description']) . '</p>';
        $html .= '</div>';

        $html .= '<div class="conditions-section">';
        $html .= '<h3>Conditions</h3>';
        $html .= '<ul>';
        foreach ($explanation['conditions']['all_conditions'] as $cond) {
            $class = $cond['matched'] ? 'matched' : 'unmatched';
            $html .= "<li class=\"$class\">";
            $html .= htmlspecialchars($cond['field']) . ' ';
            $html .= htmlspecialchars($cond['operator']) . ' ';
            $html .= htmlspecialchars($cond['expected_value']);
            $html .= '</li>';
        }
        $html .= '</ul>';
        $html .= '</div>';

        $html .= '<div class="impact-section">';
        $html .= '<h3>Impact</h3>';
        $html .= '<p>' . htmlspecialchars($explanation['impact']['impact']) . '</p>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }
}
