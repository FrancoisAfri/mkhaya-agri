<?php

namespace App\Modules\Workflow\Support;

/**
 * The standard template library (PRS §23). Installing a template creates an editable draft for the
 * company: its own definition, forms and version history. Later changes to these templates never
 * overwrite what a company has made from them (§22). Templates on a module (leave, claims,
 * procurement) hand their outcome to that module's own validated code.
 */
final class TemplateLibrary
{
    public static function all(): array
    {
        return [
            'procurement_approval' => self::procurement(),
            'invoice_approval' => self::invoice(),
            'leave_request' => self::leave(),
            'expense_claim' => self::claim(),
            'stock_adjustment' => self::stockAdjustment(),
            'sheq_corrective_action' => self::correctiveAction(),
        ];
    }

    public static function get(string $key): ?array
    {
        return self::everything()[$key] ?? null;
    }

    /** @var array<string, array> templates a module adds (the farm's season processes) */
    private static array $registered = [];

    /** A module adds its own processes to the gallery; they install like any other template. */
    public static function register(array $template): void
    {
        self::$registered[$template['key']] = $template;
    }

    /** The original six, the wider library and what modules registered, in one list. */
    public static function everything(): array
    {
        return self::all() + TemplateLibraryMore::all() + self::$registered;
    }

    /**
     * The other templates one starts (sub-workflows, one child per item), children first, so
     * installing it brings in what it needs.
     */
    public static function requires(string $key, array $seen = []): array
    {
        $out = [];
        foreach (self::get($key)['graph']['nodes'] ?? [] as $n) {
            $child = $n['config']['definition_key'] ?? null;
            if (in_array($n['type'] ?? null, ['subworkflow', 'multi_instance'], true) && $child && $child !== $key && ! in_array($child, $seen, true) && self::get($child)) {
                $out = [...$out, ...self::requires($child, [...$seen, $key]), $child];
            }
        }

        return array_values(array_unique($out));
    }

    private static function n(string $key, string $type, string $name, int $x, int $y, array $config = []): array
    {
        return compact('key', 'type', 'name', 'x', 'y', 'config');
    }

    private static function e(string $key, string $from, string $to, array $extra = []): array
    {
        return array_merge(compact('key', 'from', 'to'), $extra);
    }

    private static function sla(int $hours, string $escalation = 'notify_manager'): array
    {
        return ['hours' => $hours, 'business_hours' => true, 'warn_percent' => 75, 'escalation' => $escalation];
    }

    private static function procurement(): array
    {
        return [
            'key' => 'procurement_approval', 'name' => 'Purchase requisition approval', 'category' => 'Procurement', 'module' => 'procurement.requisition',
            'description' => 'Request → budget check → manager approval → (above R50 000) finance approval → procurement handles quotations and the purchase order → requester confirms receipt.',
            'trigger_type' => 'record_event', 'trigger_config' => ['event' => 'procurement.requisition.submitted'],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Requisition submitted', 40, 220),
                self::n('budget', 'decision', 'Within budget?', 240, 220),
                self::n('manager', 'approval', 'Line manager approval', 460, 140, ['assignee' => ['type' => 'line_manager'], 'mode' => 'any', 'sla' => self::sla(16), 'allow_return' => true]),
                self::n('fix', 'user_task', 'Correct the requisition', 460, 360, ['assignee' => ['type' => 'requester'], 'instructions' => 'Update the requisition as the approver asked, then resubmit.']),
                self::n('amount', 'decision', 'Above R50 000?', 680, 140),
                self::n('finance', 'approval', 'Finance approval', 900, 60, ['assignee' => ['type' => 'role', 'value' => 'Workflow Administrator'], 'mode' => 'any', 'sla' => self::sla(24)]),
                self::n('procure', 'user_task', 'Obtain quotations and raise the PO', 1120, 140, ['assignee' => ['type' => 'role', 'value' => 'Workflow Administrator'], 'queue' => true, 'sla' => self::sla(40)]),
                self::n('receipt', 'user_task', 'Confirm goods received', 1340, 140, ['assignee' => ['type' => 'requester']]),
                self::n('done', 'end', 'Approved', 1560, 140, ['outcome' => 'completed']),
                self::n('rejected', 'end', 'Rejected', 900, 360, ['outcome' => 'rejected']),
            ], 'edges' => [
                self::e('e1', 'start', 'budget'),
                self::e('e2', 'budget', 'manager', ['condition' => ['field' => 'record.within_budget', 'operator' => 'true'], 'label' => 'Within budget']),
                self::e('e3', 'budget', 'rejected', ['is_default' => true, 'label' => 'No budget']),
                self::e('e4', 'manager', 'amount', ['outcome' => 'approved']),
                self::e('e5', 'manager', 'rejected', ['outcome' => 'rejected']),
                self::e('e6', 'manager', 'fix', ['outcome' => 'returned']),
                self::e('e7', 'fix', 'manager'),
                self::e('e8', 'amount', 'finance', ['condition' => ['field' => 'record.amount', 'operator' => 'gt', 'value' => 50000], 'label' => '> R50 000']),
                self::e('e9', 'amount', 'procure', ['is_default' => true, 'label' => 'Up to R50 000']),
                self::e('e10', 'finance', 'procure', ['outcome' => 'approved']),
                self::e('e11', 'finance', 'rejected', ['outcome' => 'rejected']),
                self::e('e12', 'procure', 'receipt'),
                self::e('e13', 'receipt', 'done'),
            ]],
        ];
    }

    private static function invoice(): array
    {
        return [
            'key' => 'invoice_approval', 'name' => 'Supplier invoice approval', 'category' => 'Finance', 'module' => 'generic',
            'description' => 'Invoice submission → validation → review → approval matrix by amount → handoff to payment processing (Finance records and pays it).',
            'forms' => ['invoice' => ['name' => 'Supplier invoice', 'fields' => [
                ['key' => 'supplier_name', 'label' => 'Supplier', 'type' => 'text', 'required' => true],
                ['key' => 'invoice_number', 'label' => 'Invoice number', 'type' => 'text', 'required' => true],
                ['key' => 'invoice_date', 'label' => 'Invoice date', 'type' => 'date', 'required' => true],
                ['key' => 'amount', 'label' => 'Amount (incl. VAT)', 'type' => 'currency', 'required' => true, 'validation' => ['min' => 0.01]],
                ['key' => 'po_number', 'label' => 'Purchase order number', 'type' => 'text'],
                ['key' => 'no_po_reason', 'label' => 'Why there is no purchase order', 'type' => 'textarea', 'required_if' => ['field' => 'po_number', 'operator' => 'empty']],
                ['key' => 'invoice_file', 'label' => 'Invoice', 'type' => 'attachment', 'required' => true],
            ]]],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Invoice submitted', 40, 200, ['form' => 'invoice']),
                self::n('validate', 'review', 'Validate and match to the PO', 260, 200, ['assignee' => ['type' => 'role', 'value' => 'Workflow Administrator'], 'queue' => true, 'allow_return' => true, 'sla' => self::sla(16)]),
                self::n('fix', 'user_task', 'Correct the invoice details', 260, 380, ['assignee' => ['type' => 'requester']]),
                self::n('matrix', 'decision', 'Amount band', 480, 200),
                self::n('manager', 'approval', 'Budget holder approval', 700, 120, ['assignee' => ['type' => 'line_manager'], 'sla' => self::sla(16)]),
                self::n('exec', 'approval', 'Executive approval', 700, 300, ['assignee' => ['type' => 'role', 'value' => 'Workflow Administrator'], 'sla' => self::sla(24)]),
                self::n('pay', 'notification', 'Hand to payments', 920, 200, ['to' => ['type' => 'role', 'value' => 'Workflow Administrator'], 'subject' => 'Invoice {{invoice_number}} approved for payment', 'message' => 'Invoice {{invoice_number}} from {{supplier_name}} for R{{amount}} is approved. Record and pay it in Finance.']),
                self::n('done', 'end', 'Approved for payment', 1140, 200, ['outcome' => 'completed']),
                self::n('rejected', 'end', 'Rejected', 920, 400, ['outcome' => 'rejected']),
            ], 'edges' => [
                self::e('e1', 'start', 'validate'),
                self::e('e2', 'validate', 'matrix', ['outcome' => 'completed']),
                self::e('e3', 'validate', 'fix', ['outcome' => 'returned']),
                self::e('e4', 'fix', 'validate'),
                self::e('e5', 'matrix', 'exec', ['condition' => ['field' => 'amount', 'operator' => 'gt', 'value' => 100000], 'label' => '> R100 000']),
                self::e('e6', 'matrix', 'manager', ['is_default' => true, 'label' => 'Up to R100 000']),
                self::e('e7', 'manager', 'pay', ['outcome' => 'approved']),
                self::e('e8', 'manager', 'rejected', ['outcome' => 'rejected']),
                self::e('e9', 'exec', 'pay', ['outcome' => 'approved']),
                self::e('e10', 'exec', 'rejected', ['outcome' => 'rejected']),
                self::e('e11', 'pay', 'done'),
            ]],
        ];
    }

    private static function leave(): array
    {
        return [
            'key' => 'leave_request', 'name' => 'Leave request', 'category' => 'HR', 'module' => 'hr.leave',
            'description' => 'Employee request (leave policy checks run in Leave) → line manager decision → HR review for long leave → Leave records the outcome and the balance.',
            'trigger_type' => 'record_event', 'trigger_config' => ['event' => 'hr.leave.submitted'],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Leave applied for', 40, 200),
                self::n('manager', 'approval', 'Line manager decision', 260, 200, ['assignee' => ['type' => 'line_manager'], 'sla' => self::sla(16, 'reassign_manager')]),
                self::n('long', 'decision', 'More than 10 days?', 480, 120),
                self::n('hr', 'approval', 'HR review', 700, 40, ['assignee' => ['type' => 'role', 'value' => 'Workflow Administrator'], 'sla' => self::sla(16)]),
                self::n('done', 'end', 'Approved', 920, 120, ['outcome' => 'completed']),
                self::n('rejected', 'end', 'Declined', 480, 340, ['outcome' => 'rejected']),
            ], 'edges' => [
                self::e('e1', 'start', 'manager'),
                self::e('e2', 'manager', 'long', ['outcome' => 'approved']),
                self::e('e3', 'manager', 'rejected', ['outcome' => 'rejected']),
                self::e('e4', 'long', 'hr', ['condition' => ['field' => 'record.days', 'operator' => 'gt', 'value' => 10], 'label' => '> 10 days']),
                self::e('e5', 'long', 'done', ['is_default' => true]),
                self::e('e6', 'hr', 'done', ['outcome' => 'approved']),
                self::e('e7', 'hr', 'rejected', ['outcome' => 'rejected']),
            ]],
        ];
    }

    private static function claim(): array
    {
        return [
            'key' => 'expense_claim', 'name' => 'Expense claim approval', 'category' => 'Finance', 'module' => 'finance.claims',
            'description' => 'Claim submitted (receipt and policy checks run in Claims) → line manager → finance for claims above R5 000 → Claims queues approved claims for payment.',
            'trigger_type' => 'record_event', 'trigger_config' => ['event' => 'finance.claims.submitted'],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Claim submitted', 40, 200),
                self::n('manager', 'approval', 'Line manager approval', 260, 200, ['assignee' => ['type' => 'line_manager'], 'sla' => self::sla(16), 'allow_return' => true]),
                self::n('fix', 'user_task', 'Correct the claim', 260, 380, ['assignee' => ['type' => 'requester']]),
                self::n('amount', 'decision', 'Above R5 000?', 480, 200),
                self::n('finance', 'approval', 'Finance approval', 700, 120, ['assignee' => ['type' => 'role', 'value' => 'Workflow Administrator'], 'sla' => self::sla(24)]),
                self::n('done', 'end', 'Approved', 920, 200, ['outcome' => 'completed']),
                self::n('rejected', 'end', 'Rejected', 700, 360, ['outcome' => 'rejected']),
            ], 'edges' => [
                self::e('e1', 'start', 'manager'),
                self::e('e2', 'manager', 'amount', ['outcome' => 'approved']),
                self::e('e3', 'manager', 'rejected', ['outcome' => 'rejected']),
                self::e('e4', 'manager', 'fix', ['outcome' => 'returned']),
                self::e('e5', 'fix', 'manager'),
                self::e('e6', 'amount', 'finance', ['condition' => ['field' => 'record.amount', 'operator' => 'gt', 'value' => 5000], 'label' => '> R5 000']),
                self::e('e7', 'amount', 'done', ['is_default' => true]),
                self::e('e8', 'finance', 'done', ['outcome' => 'approved']),
                self::e('e9', 'finance', 'rejected', ['outcome' => 'rejected']),
            ]],
        ];
    }

    private static function stockAdjustment(): array
    {
        return [
            'key' => 'stock_adjustment', 'name' => 'Warehouse stock adjustment', 'category' => 'Warehouse', 'module' => 'generic',
            'description' => 'Adjustment request with reason and evidence → authorised review → the warehouse posts the adjustment in Warehouse Management → audit confirmation.',
            'forms' => ['adjust' => ['name' => 'Stock adjustment request', 'fields' => [
                ['key' => 'warehouse', 'label' => 'Warehouse', 'type' => 'text', 'required' => true],
                ['key' => 'reason', 'label' => 'Reason', 'type' => 'select', 'options' => ['Count variance', 'Damage', 'Expiry', 'Theft / loss', 'Other'], 'required' => true],
                ['key' => 'lines', 'label' => 'Items', 'type' => 'line_items', 'min_rows' => 1, 'columns' => [
                    ['key' => 'item', 'label' => 'Item code', 'type' => 'text', 'required' => true],
                    ['key' => 'quantity', 'label' => 'Quantity (+/−)', 'type' => 'decimal', 'required' => true],
                    ['key' => 'unit_cost', 'label' => 'Unit cost', 'type' => 'currency', 'required' => true],
                ]],
                ['key' => 'value', 'label' => 'Value of adjustment', 'type' => 'calculated', 'formula' => ['op' => 'sum_column', 'list' => 'lines', 'columns' => ['quantity', 'unit_cost']]],
                ['key' => 'evidence', 'label' => 'Count sheet / evidence', 'type' => 'attachment', 'required' => true],
            ]]],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Adjustment requested', 40, 200, ['form' => 'adjust']),
                self::n('review', 'approval', 'Warehouse manager review', 260, 200, ['assignee' => ['type' => 'line_manager'], 'sla' => self::sla(8), 'allow_return' => true]),
                self::n('fix', 'user_task', 'Add evidence or correct the request', 260, 380, ['assignee' => ['type' => 'requester']]),
                self::n('post', 'user_task', 'Post the adjustment in Warehouse Management', 480, 200, ['assignee' => ['type' => 'requester'], 'instructions' => 'Post the approved adjustment in Warehouse Management; it validates stock and records the movement.']),
                self::n('audit', 'review', 'Audit confirmation', 700, 200, ['assignee' => ['type' => 'role', 'value' => 'Workflow Auditor'], 'queue' => true]),
                self::n('done', 'end', 'Adjusted', 920, 200, ['outcome' => 'completed']),
                self::n('rejected', 'end', 'Rejected', 480, 380, ['outcome' => 'rejected']),
            ], 'edges' => [
                self::e('e1', 'start', 'review'),
                self::e('e2', 'review', 'post', ['outcome' => 'approved']),
                self::e('e3', 'review', 'rejected', ['outcome' => 'rejected']),
                self::e('e4', 'review', 'fix', ['outcome' => 'returned']),
                self::e('e5', 'fix', 'review'),
                self::e('e6', 'post', 'audit'),
                self::e('e7', 'audit', 'done'),
            ]],
        ];
    }

    private static function correctiveAction(): array
    {
        return [
            'key' => 'sheq_corrective_action', 'name' => 'SHEQ corrective action', 'category' => 'SHEQ', 'module' => 'generic',
            'description' => 'Incident or finding → risk assessment → assigned corrective action → evidence → verification → closure.',
            'forms' => ['finding' => ['name' => 'Incident / finding', 'fields' => [
                ['key' => 'title', 'label' => 'What happened', 'type' => 'text', 'required' => true],
                ['key' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true],
                ['key' => 'occurred_on', 'label' => 'Date', 'type' => 'date', 'required' => true],
                ['key' => 'severity', 'label' => 'Severity', 'type' => 'select', 'options' => ['Low', 'Medium', 'High', 'Critical'], 'required' => true],
                ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'required' => true],
                ['key' => 'action_owner', 'label' => 'Who will take the corrective action', 'type' => 'user', 'required' => true],
                ['key' => 'photos', 'label' => 'Photos', 'type' => 'attachment'],
            ]]],
            'graph' => ['nodes' => [
                self::n('start', 'start', 'Finding raised', 40, 200, ['form' => 'finding']),
                self::n('risk', 'review', 'Risk assessment', 260, 200, ['assignee' => ['type' => 'role', 'value' => 'Process Manager'], 'queue' => true, 'sla' => self::sla(16)]),
                self::n('act', 'user_task', 'Take the corrective action and attach evidence', 480, 200, ['assignee' => ['type' => 'field', 'value' => 'action_owner'], 'sla' => self::sla(80, 'notify_owner')]),
                self::n('verify', 'approval', 'Verify the action is effective', 700, 200, ['assignee' => ['type' => 'role', 'value' => 'Process Manager'], 'allow_return' => true, 'distinct_approvers' => false]),
                self::n('done', 'end', 'Closed', 920, 200, ['outcome' => 'completed']),
                self::n('rejected', 'end', 'Not accepted', 700, 380, ['outcome' => 'rejected']),
            ], 'edges' => [
                self::e('e1', 'start', 'risk'),
                self::e('e2', 'risk', 'act'),
                self::e('e3', 'act', 'verify'),
                self::e('e4', 'verify', 'done', ['outcome' => 'approved']),
                self::e('e5', 'verify', 'act', ['outcome' => 'returned']),
                self::e('e6', 'verify', 'rejected', ['outcome' => 'rejected']),
            ]],
        ];
    }
}
