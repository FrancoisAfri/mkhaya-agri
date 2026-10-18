<?php

namespace App\Modules\Operations\Manufacturing\Support;

/**
 * The manufacturing permission set (MFG-001.09).
 *
 * Named constants rather than loose strings, because a typo in a permission name does not fail —
 * `can('mfg.bom.aprove')` simply returns false, the button disappears, and it looks like a
 * configuration problem on the customer's side.
 *
 * Reading is separated from configuring, and configuring from approving, because the three are
 * done by different people: a planner reads a BOM all day, an engineer drafts one, and somebody
 * else has to sign it off — which is the control the BOM approval requirement exists to impose.
 */
final class RoleCatalog
{
    // Reading
    public const VIEW_DASHBOARD = 'mfg.dashboard.view';
    public const VIEW_ITEMS = 'mfg.item.view';
    public const VIEW_BOMS = 'mfg.bom.view';

    // Engineering
    public const MANAGE_ITEMS = 'mfg.item.manage';
    public const MANAGE_BOMS = 'mfg.bom.manage';

    /** Signing off a revision. Deliberately not implied by MANAGE_BOMS. */
    public const APPROVE_BOMS = 'mfg.bom.approve';

    /** Retiring a revision for good, which ends its use on any future order. */
    public const OBSOLETE_BOMS = 'mfg.bom.obsolete';

    /** Overriding a substitution that was recorded as needing approval. */
    public const APPROVE_SUBSTITUTIONS = 'mfg.substitution.approve';

    // Routings (MFG-003). Their own set: the people who lay out a line are not always the people
    // who write a recipe, and signing off how a product is made is its own decision.
    public const VIEW_ROUTINGS = 'mfg.routing.view';
    public const MANAGE_ROUTINGS = 'mfg.routing.manage';
    public const APPROVE_ROUTINGS = 'mfg.routing.approve';

    // Production orders (MFG-006). Split along who actually does each: a planner raises and plans,
    // a production manager approves and releases, finance or the manager closes, and an override of
    // a control is its own permission so it can be held by very few people.
    public const VIEW_ORDERS = 'mfg.order.view';
    public const MANAGE_ORDERS = 'mfg.order.manage';
    public const APPROVE_ORDERS = 'mfg.order.approve';
    public const RELEASE_ORDERS = 'mfg.order.release';
    public const CLOSE_ORDERS = 'mfg.order.close';
    public const OVERRIDE_CONTROLS = 'mfg.order.override';

    // Shop floor (MFG-007). Recording activity grants nothing over BOMs, costs or settings.
    public const EXECUTE = 'mfg.floor.execute';
    public const ISSUE_MATERIAL = 'mfg.material.issue';

    /** Assigning jobs to operators and answering their escalations. */
    public const SUPERVISE_FLOOR = 'mfg.floor.supervise';

    // Quality (MFG-009). Inspecting is separate from deciding: the person who measured a lot is
    // not the person who releases it, and a concession is a quality manager's call.
    public const VIEW_QUALITY = 'mfg.quality.view';
    public const INSPECT = 'mfg.quality.inspect';
    public const DISPOSE = 'mfg.quality.dispose';
    public const MANAGE_QUALITY_PLANS = 'mfg.quality.plan';
    public const MANAGE_NCR = 'mfg.quality.ncr';

    // Traceability and recall (MFG-010). Tracing reads across suppliers, orders and customers, so it
    // is its own grant; opening a recall puts stock on hold company-wide and is rarer still.
    public const VIEW_TRACE = 'mfg.trace.view';
    public const MANAGE_RECALL = 'mfg.recall.manage';

    // Costing (MFG-011). Costs and margins are sensitive; calculating a standard is not approving it.
    public const VIEW_COSTS = 'mfg.cost.view';
    public const MANAGE_COSTS = 'mfg.cost.manage';
    public const APPROVE_COSTS = 'mfg.cost.approve';

    // Maintenance and OEE (MFG-013). Returning a held machine to service is its own sign-off.
    public const VIEW_MAINTENANCE = 'mfg.maintenance.view';
    public const MANAGE_MAINTENANCE = 'mfg.maintenance.manage';
    public const RELEASE_MACHINE = 'mfg.maintenance.release';

    // Planning (MFG-004/005/015). Running the plan is not committing to it: approving a
    // recommendation creates orders and purchase requests, and is its own permission.
    public const VIEW_PLANNING = 'mfg.plan.view';
    public const MANAGE_PLANNING = 'mfg.plan.manage';
    public const APPROVE_PLAN = 'mfg.plan.approve';

    // Workforce (MFG-016). Who is qualified and how much each person worked is personal data:
    // reading it is its own grant.
    public const VIEW_LABOUR = 'mfg.labour.view';
    public const MANAGE_LABOUR = 'mfg.labour.manage';

    // Customer orders and subcontracting (MFG-017). Committing production to a customer and
    // sending work to a supplier are each their own decision.
    public const VIEW_CUSTOMER_ORDERS = 'mfg.sales.view';
    public const COMMIT_CUSTOMER_ORDERS = 'mfg.sales.commit';
    public const VIEW_SUBCONTRACT = 'mfg.subcontract.view';
    public const MANAGE_SUBCONTRACT = 'mfg.subcontract.manage';

    // Loss prevention (MFG-012). Approving a verified loss is management's, and never the investigator's own.
    public const VIEW_LOSS = 'mfg.loss.view';
    public const INVESTIGATE_LOSS = 'mfg.loss.investigate';
    public const APPROVE_LOSS = 'mfg.loss.approve';

    // The AI assistant (MFG-019). Asking is open to anyone in the module -- each answer is still
    // limited by the permission its figures need; drafting tasks and actions from it is a grant.
    public const USE_ASSISTANT = 'mfg.assistant.use';
    public const ACT_ON_ASSISTANT = 'mfg.assistant.act';

    // Accounting controls (MFG-021): reading the cost subledger and reconciliation, approving how
    // events are treated in the books, and reversing a posted movement are three separate grants.
    public const VIEW_ACCOUNTING = 'mfg.finance.view';
    public const APPROVE_ACCOUNTING = 'mfg.finance.approve';
    public const ADJUST_ACCOUNTING = 'mfg.finance.adjust';

    // The audit trail (MFG-023): who changed what, with before and after values.
    public const VIEW_AUDIT = 'mfg.audit.view';

    // Multi-factory (MFG-024): seeing and moving stock between the company's factories. Which
    // factory a person may act for is still their factory access.
    public const VIEW_TRANSFERS = 'mfg.transfer.view';
    public const MANAGE_TRANSFERS = 'mfg.transfer.manage';

    // External integrations (MFG-022): seeing their status, and holding their credentials --
    // the second is for designated technical administrators only.
    public const VIEW_INTEGRATIONS = 'mfg.integration.view';
    public const MANAGE_INTEGRATIONS = 'mfg.integration.manage';

    // Configuration (MFG-001)
    public const CONFIGURE = 'mfg.configure';

    /**
     * Seeing every factory regardless of assignment.
     *
     * Somebody assigned to one plant is confined to it; this is the explicit authorisation the
     * acceptance criterion allows for -- an operations director, a group controller. It is a
     * permission rather than a flag on the assignment because "who may see everything" is a
     * question about a role, and tenants design their own roles.
     */
    public const ALL_FACTORIES = 'mfg.factory.all';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW_DASHBOARD,
            self::VIEW_ITEMS,
            self::VIEW_BOMS,
            self::MANAGE_ITEMS,
            self::MANAGE_BOMS,
            self::APPROVE_BOMS,
            self::OBSOLETE_BOMS,
            self::APPROVE_SUBSTITUTIONS,
            self::VIEW_ROUTINGS,
            self::MANAGE_ROUTINGS,
            self::APPROVE_ROUTINGS,
            self::VIEW_ORDERS,
            self::MANAGE_ORDERS,
            self::APPROVE_ORDERS,
            self::RELEASE_ORDERS,
            self::CLOSE_ORDERS,
            self::OVERRIDE_CONTROLS,
            self::EXECUTE,
            self::ISSUE_MATERIAL,
            self::SUPERVISE_FLOOR,
            self::VIEW_QUALITY,
            self::INSPECT,
            self::DISPOSE,
            self::MANAGE_QUALITY_PLANS,
            self::MANAGE_NCR,
            self::VIEW_TRACE,
            self::MANAGE_RECALL,
            self::VIEW_COSTS,
            self::MANAGE_COSTS,
            self::APPROVE_COSTS,
            self::VIEW_MAINTENANCE,
            self::MANAGE_MAINTENANCE,
            self::RELEASE_MACHINE,
            self::VIEW_PLANNING,
            self::MANAGE_PLANNING,
            self::APPROVE_PLAN,
            self::VIEW_LABOUR,
            self::MANAGE_LABOUR,
            self::VIEW_CUSTOMER_ORDERS,
            self::COMMIT_CUSTOMER_ORDERS,
            self::VIEW_SUBCONTRACT,
            self::MANAGE_SUBCONTRACT,
            self::VIEW_LOSS,
            self::INVESTIGATE_LOSS,
            self::APPROVE_LOSS,
            self::USE_ASSISTANT,
            self::ACT_ON_ASSISTANT,
            self::VIEW_ACCOUNTING,
            self::APPROVE_ACCOUNTING,
            self::ADJUST_ACCOUNTING,
            self::VIEW_AUDIT,
            self::VIEW_TRANSFERS,
            self::MANAGE_TRANSFERS,
            self::VIEW_INTEGRATIONS,
            self::MANAGE_INTEGRATIONS,
            self::CONFIGURE,
            self::ALL_FACTORIES,
        ];
    }
}
