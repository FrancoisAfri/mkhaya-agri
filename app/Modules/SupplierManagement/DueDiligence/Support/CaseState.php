<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

/**
 * The states a due-diligence case moves through, and who may move it.
 *
 * Written as data rather than as a pile of conditionals because the interesting questions about a
 * workflow are all questions about the table: which moves exist, who may make each, and what has to
 * be true first. A reader can answer those here; they could not answer them from twenty `if`
 * statements spread over a controller.
 *
 * Two rules are structural rather than configurable, and both exist because a control that can be
 * switched off is not a control:
 *
 *  - The person who raised a case can never decide it. That is the single-operator path every
 *    payments fraud takes, and no role map may permit it.
 *  - Approval requires the gates to pass. A person may reject against the gates; nobody may approve
 *    around them without recording an override reason.
 */
final class CaseState
{
    public const DRAFT = 'draft';
    public const INVITED = 'invited';
    public const AWAITING_SUPPLIER = 'awaiting_supplier';
    public const SUBMITTED = 'submitted';
    public const VERIFICATION = 'verification';
    public const SCREENING = 'screening';
    public const RISK_ASSESSMENT = 'risk_assessment';
    public const ENHANCED_DUE_DILIGENCE = 'enhanced_due_diligence';
    public const COMPLIANCE_REVIEW = 'compliance_review';
    public const PROCUREMENT_REVIEW = 'procurement_review';
    public const MANAGEMENT_APPROVAL = 'management_approval';
    public const APPROVED = 'approved';
    public const CONDITIONAL_APPROVAL = 'conditional_approval';
    public const REJECTED = 'rejected';
    public const SUSPENDED = 'suspended';
    public const BLACKLISTED = 'blacklisted';
    public const EXPIRED = 'expired';
    public const REVIEW_REQUIRED = 'review_required';

    /**
     * @return array<string, array{label:string, group:string, terminal:bool, open:bool}>
     */
    public static function all(): array
    {
        return [
            self::DRAFT => ['label' => 'Draft', 'group' => 'intake', 'terminal' => false, 'open' => true],
            self::INVITED => ['label' => 'Invited', 'group' => 'intake', 'terminal' => false, 'open' => true],
            self::AWAITING_SUPPLIER => ['label' => 'Awaiting supplier', 'group' => 'intake', 'terminal' => false, 'open' => true],
            self::SUBMITTED => ['label' => 'Information submitted', 'group' => 'intake', 'terminal' => false, 'open' => true],
            self::VERIFICATION => ['label' => 'Verification', 'group' => 'checks', 'terminal' => false, 'open' => true],
            self::SCREENING => ['label' => 'Screening', 'group' => 'checks', 'terminal' => false, 'open' => true],
            self::RISK_ASSESSMENT => ['label' => 'Risk assessment', 'group' => 'checks', 'terminal' => false, 'open' => true],
            self::ENHANCED_DUE_DILIGENCE => ['label' => 'Enhanced due diligence', 'group' => 'review', 'terminal' => false, 'open' => true],
            self::COMPLIANCE_REVIEW => ['label' => 'Compliance review', 'group' => 'review', 'terminal' => false, 'open' => true],
            self::PROCUREMENT_REVIEW => ['label' => 'Procurement review', 'group' => 'review', 'terminal' => false, 'open' => true],
            self::MANAGEMENT_APPROVAL => ['label' => 'Management approval', 'group' => 'review', 'terminal' => false, 'open' => true],
            self::APPROVED => ['label' => 'Approved', 'group' => 'closed', 'terminal' => true, 'open' => false],
            self::CONDITIONAL_APPROVAL => ['label' => 'Conditional approval', 'group' => 'closed', 'terminal' => false, 'open' => false],
            self::REJECTED => ['label' => 'Rejected', 'group' => 'closed', 'terminal' => true, 'open' => false],
            self::SUSPENDED => ['label' => 'Suspended', 'group' => 'closed', 'terminal' => false, 'open' => false],
            self::BLACKLISTED => ['label' => 'Blacklisted', 'group' => 'closed', 'terminal' => true, 'open' => false],
            self::EXPIRED => ['label' => 'Expired', 'group' => 'closed', 'terminal' => false, 'open' => false],
            self::REVIEW_REQUIRED => ['label' => 'Review required', 'group' => 'review', 'terminal' => false, 'open' => true],
        ];
    }

    /**
     * Every permitted move: action → from states, to state, and which role slot may do it.
     *
     * `requires_reason` is on the moves where a decision has to be defensible later. `requires_gates`
     * marks the ones that may not happen while an approval gate is failing, unless somebody records
     * an override — which is itself a transition, so the override is on the file.
     *
     * @return array<string, array{label:string, from:array<int,string>, to:string, slot:string,
     *                             requires_reason:bool, requires_gates:bool, blocks_if_raiser:bool}>
     */
    public static function transitions(): array
    {
        return [
            'invite' => [
                'label' => 'Invite the supplier', 'from' => [self::DRAFT], 'to' => self::INVITED,
                'slot' => RoleSlot::PROCUREMENT_OFFICER, 'requires_reason' => false,
                'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'await' => [
                'label' => 'Wait on the supplier', 'from' => [self::INVITED, self::SUBMITTED, self::VERIFICATION],
                'to' => self::AWAITING_SUPPLIER, 'slot' => RoleSlot::PROCUREMENT_OFFICER,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'submit' => [
                'label' => 'Record the supplier\'s submission',
                'from' => [self::DRAFT, self::INVITED, self::AWAITING_SUPPLIER], 'to' => self::SUBMITTED,
                'slot' => RoleSlot::PROCUREMENT_OFFICER, 'requires_reason' => false,
                'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'verify' => [
                'label' => 'Start verification', 'from' => [self::SUBMITTED, self::AWAITING_SUPPLIER, self::REVIEW_REQUIRED],
                'to' => self::VERIFICATION, 'slot' => RoleSlot::PROCUREMENT_OFFICER,
                'requires_reason' => false, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'screen' => [
                'label' => 'Move to screening', 'from' => [self::VERIFICATION], 'to' => self::SCREENING,
                'slot' => RoleSlot::PROCUREMENT_OFFICER, 'requires_reason' => false,
                'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'assess' => [
                'label' => 'Assess the risk', 'from' => [self::SCREENING, self::VERIFICATION, self::ENHANCED_DUE_DILIGENCE],
                'to' => self::RISK_ASSESSMENT, 'slot' => RoleSlot::PROCUREMENT_OFFICER,
                'requires_reason' => false, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'require_edd' => [
                'label' => 'Require enhanced due diligence',
                'from' => [self::RISK_ASSESSMENT, self::COMPLIANCE_REVIEW, self::PROCUREMENT_REVIEW, self::REVIEW_REQUIRED],
                'to' => self::ENHANCED_DUE_DILIGENCE, 'slot' => RoleSlot::COMPLIANCE_OFFICER,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'to_compliance' => [
                'label' => 'Send for compliance review',
                'from' => [self::RISK_ASSESSMENT, self::ENHANCED_DUE_DILIGENCE, self::PROCUREMENT_REVIEW],
                'to' => self::COMPLIANCE_REVIEW, 'slot' => RoleSlot::PROCUREMENT_OFFICER,
                'requires_reason' => false, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'to_procurement' => [
                'label' => 'Send for procurement review',
                'from' => [self::RISK_ASSESSMENT, self::COMPLIANCE_REVIEW, self::ENHANCED_DUE_DILIGENCE],
                'to' => self::PROCUREMENT_REVIEW,
                // The officer's own assessed case has to be able to reach the decision queue. Making
                // this a compliance-only move meant the ordinary path required compliance to touch
                // every case, which is not segregation — it is a bottleneck people route around.
                // Compliance is senior to the officer, so handing a case back still works.
                'slot' => RoleSlot::PROCUREMENT_OFFICER,
                'requires_reason' => false, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'to_management' => [
                'label' => 'Escalate to management',
                'from' => [self::COMPLIANCE_REVIEW, self::PROCUREMENT_REVIEW, self::ENHANCED_DUE_DILIGENCE],
                'to' => self::MANAGEMENT_APPROVAL, 'slot' => RoleSlot::COMPLIANCE_OFFICER,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'approve' => [
                'label' => 'Approve',
                'from' => [self::PROCUREMENT_REVIEW, self::COMPLIANCE_REVIEW, self::MANAGEMENT_APPROVAL, self::CONDITIONAL_APPROVAL],
                'to' => self::APPROVED, 'slot' => RoleSlot::PROCUREMENT_MANAGER,
                'requires_reason' => false, 'requires_gates' => true, 'blocks_if_raiser' => true,
            ],
            'approve_conditionally' => [
                'label' => 'Approve with conditions',
                'from' => [self::PROCUREMENT_REVIEW, self::COMPLIANCE_REVIEW, self::MANAGEMENT_APPROVAL],
                'to' => self::CONDITIONAL_APPROVAL, 'slot' => RoleSlot::PROCUREMENT_MANAGER,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => true,
            ],
            'reject' => [
                'label' => 'Reject',
                'from' => [self::SUBMITTED, self::VERIFICATION, self::SCREENING, self::RISK_ASSESSMENT,
                    self::ENHANCED_DUE_DILIGENCE, self::COMPLIANCE_REVIEW, self::PROCUREMENT_REVIEW,
                    self::MANAGEMENT_APPROVAL, self::REVIEW_REQUIRED],
                'to' => self::REJECTED, 'slot' => RoleSlot::PROCUREMENT_MANAGER,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => true,
            ],
            'suspend' => [
                'label' => 'Suspend',
                'from' => [self::APPROVED, self::CONDITIONAL_APPROVAL, self::REVIEW_REQUIRED],
                'to' => self::SUSPENDED, 'slot' => RoleSlot::COMPLIANCE_OFFICER,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'reinstate' => [
                'label' => 'Reinstate', 'from' => [self::SUSPENDED], 'to' => self::REVIEW_REQUIRED,
                'slot' => RoleSlot::COMPLIANCE_OFFICER, 'requires_reason' => true,
                'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'blacklist' => [
                'label' => 'Blacklist',
                'from' => [self::SUSPENDED, self::REJECTED, self::REVIEW_REQUIRED, self::APPROVED,
                    self::CONDITIONAL_APPROVAL, self::COMPLIANCE_REVIEW, self::MANAGEMENT_APPROVAL],
                'to' => self::BLACKLISTED, 'slot' => RoleSlot::EXECUTIVE,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => true,
            ],
            'flag_review' => [
                'label' => 'Send back for review',
                'from' => [self::APPROVED, self::CONDITIONAL_APPROVAL, self::EXPIRED],
                'to' => self::REVIEW_REQUIRED, 'slot' => RoleSlot::COMPLIANCE_OFFICER,
                'requires_reason' => true, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
            'expire' => [
                'label' => 'Expire', 'from' => [self::APPROVED, self::CONDITIONAL_APPROVAL],
                'to' => self::EXPIRED, 'slot' => RoleSlot::COMPLIANCE_OFFICER,
                'requires_reason' => false, 'requires_gates' => false, 'blocks_if_raiser' => false,
            ],
        ];
    }

    public static function label(string $state): string
    {
        return self::all()[$state]['label'] ?? $state;
    }

    public static function exists(string $state): bool
    {
        return array_key_exists($state, self::all());
    }

    /** A case in one of these is still work in progress. */
    public static function isOpen(string $state): bool
    {
        return (bool) (self::all()[$state]['open'] ?? false);
    }

    /** Nothing moves out of a terminal state — a new case is raised instead. */
    public static function isTerminal(string $state): bool
    {
        return (bool) (self::all()[$state]['terminal'] ?? false);
    }

    /** The moves available from a given state, before any question of who is asking. */
    public static function availableFrom(string $state): array
    {
        return array_filter(self::transitions(), fn ($t) => in_array($state, $t['from'], true));
    }
}
