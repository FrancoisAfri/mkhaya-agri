<?php

namespace App\Modules\Workflow\Support;

/**
 * More ready-to-configure processes across the Hub's modules. Steps go to people the engine can
 * always resolve — the requester's line manager, their department head, the process owner (whoever
 * installs it), or a person chosen on the form — so every template installs and validates in any
 * company; designers then point steps at their own roles.
 */
final class TemplateLibraryMore
{
    public static function all(): array
    {
        return [
            'payment_authorisation' => self::paymentAuthorisation(),
            'petty_cash' => self::pettyCash(),
            'staff_loan' => self::staffLoan(),
            'budget_transfer' => self::budgetTransfer(),
            'travel_request' => self::travelRequest(),
            'new_position' => self::newPosition(),
            'employee_onboarding' => self::onboarding(),
            'employee_offboarding' => self::offboarding(),
            'training_request' => self::trainingRequest(),
            'overtime_preapproval' => self::overtime(),
            'disciplinary_case' => self::disciplinary(),
            'supplier_onboarding' => self::supplierOnboarding(),
            'contract_approval' => self::contractApproval(),
            'quotation_discount' => self::quotationDiscount(),
            'it_access' => self::itAccess(),
            'it_change' => self::itChange(),
            'document_review' => self::documentReview(),
            'policy_approval' => self::policyApproval(),
            'board_resolution' => self::boardResolution(),
            'incident_report' => self::incidentReport(),
            'maintenance_request' => self::maintenanceRequest(),
            'vehicle_booking' => self::vehicleBooking(),
            'asset_disposal' => self::assetDisposal(),
            'batch_release' => self::batchRelease(),
            'monthly_compliance_review' => self::monthlyCompliance(),
        ];
    }

    // ── Builders ────────────────────────────────────────────────────────────

    private static function n(string $key, string $type, string $name, array $config = []): array
    {
        return ['key' => $key, 'type' => $type, 'name' => $name, 'x' => 0, 'y' => 0, 'config' => $config];
    }

    private static function sla(int $hours, string $escalation = 'notify_manager'): array
    {
        return ['hours' => $hours, 'business_hours' => true, 'warn_percent' => 75, 'escalation' => $escalation];
    }

    private const MANAGER = ['type' => 'line_manager'];

    private const HEAD = ['type' => 'department_head'];

    private const OWNER = ['type' => 'process_owner'];

    private const REQUESTER = ['type' => 'requester'];

    /**
     * Start → each step in turn → Done. Approvals get an approved route onward and a rejected route to
     * "Not approved"; with `fix` an approval may return to the requester for correction and come back.
     *
     * @param  list<array>  $steps  nodes from n()
     */
    private static function chain(string $start, array $steps, ?string $form = null, string $done = 'Completed', bool $fix = false): array
    {
        $nodes = [self::n('start', 'start', $start, $form ? ['form' => $form] : [])];
        $edges = [];
        $prev = 'start';
        $prevType = 'start';
        $e = 0;
        $link = function (string $from, string $to, array $extra = []) use (&$edges, &$e) {
            $edges[] = array_merge(['key' => 'e' . (++$e), 'from' => $from, 'to' => $to], $extra);
        };
        $needsNo = false;
        foreach ($steps as $s) {
            if ($s['type'] === 'approval' && $fix) {
                $s['config']['allow_return'] = true;
            }
            $nodes[] = $s;
            $link($prev, $s['key'], $prevType === 'approval' ? ['outcome' => 'approved'] : []);
            if ($s['type'] === 'approval') {
                $link($s['key'], 'no', ['outcome' => 'rejected']);
                $needsNo = true;
                if ($fix) {
                    $link($s['key'], 'fix', ['outcome' => 'returned']);
                }
            }
            $prev = $s['key'];
            $prevType = $s['type'];
        }
        $nodes[] = self::n('done', 'end', $done, ['outcome' => 'completed']);
        $link($prev, 'done', $prevType === 'approval' ? ['outcome' => 'approved'] : []);
        if ($needsNo) {
            $nodes[] = self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']);
        }
        if ($fix) {
            $nodes[] = self::n('fix', 'user_task', 'Correct the request', ['assignee' => self::REQUESTER, 'instructions' => 'Make the changes the approver asked for, then complete this task.']);
            $first = collect($steps)->firstWhere('type', 'approval');
            $link('fix', $first['key']);
        }

        return ['nodes' => self::layout($nodes, $edges), 'edges' => $edges];
    }

    /** Left-to-right positions by distance from Start, so a template opens tidy. */
    private static function layout(array $nodes, array $edges): array
    {
        $depth = ['start' => 0];
        for ($pass = 0; $pass < count($nodes); $pass++) {
            foreach ($edges as $e) {
                if (isset($depth[$e['from']]) && ! isset($depth[$e['to']])) {
                    $depth[$e['to']] = $depth[$e['from']] + 1;
                }
            }
        }
        $rows = [];
        foreach ($nodes as &$n) {
            $d = $depth[$n['key']] ?? 0;
            $row = $rows[$d] = ($rows[$d] ?? -1) + 1;
            $n['x'] = 40 + $d * 230;
            $n['y'] = 120 + $row * 130;
        }
        unset($n);

        return $nodes;
    }

    private static function form(string $name, array $fields): array
    {
        return ['name' => $name, 'fields' => $fields];
    }

    private static function f(string $key, string $label, string $type, bool $required = false, array $extra = []): array
    {
        return array_merge(compact('key', 'label', 'type', 'required'), $extra);
    }

    private static function tpl(string $key, string $name, string $category, string $description, array $graph, array $forms = [], array $extra = []): array
    {
        return array_merge(['key' => $key, 'name' => $name, 'category' => $category, 'module' => 'generic', 'description' => $description, 'graph' => $graph, 'forms' => $forms], $extra);
    }

    private static function amountDecision(string $key, string $name, float $threshold, string $above, string $atOrBelow): array
    {
        return ['node' => self::n($key, 'decision', $name), 'edges' => [
            ['from' => $key, 'to' => $above, 'condition' => ['field' => 'amount', 'operator' => 'gt', 'value' => $threshold], 'label' => '> R' . number_format($threshold, 0, '.', ' ')],
            ['from' => $key, 'to' => $atOrBelow, 'is_default' => true, 'label' => 'up to R' . number_format($threshold, 0, '.', ' ')],
        ]];
    }

    /** A hand-made graph: nodes and [from, to, extra] edges. */
    private static function graph(array $nodes, array $edges): array
    {
        $i = 0;
        $edges = array_map(function ($e) use (&$i) {
            return array_merge(['key' => 'e' . (++$i), 'from' => $e[0], 'to' => $e[1]], $e[2] ?? []);
        }, $edges);

        return ['nodes' => self::layout($nodes, $edges), 'edges' => $edges];
    }

    // ── Finance ─────────────────────────────────────────────────────────────

    private static function paymentAuthorisation(): array
    {
        return self::tpl('payment_authorisation', 'Payment authorisation (two signatories)', 'Finance',
            'Payment batch or once-off payment → finance checks the supporting documents → two authorised signatories both approve → finance releases the payment and confirms.',
            self::chain('Payment submitted', [
                self::n('check', 'review', 'Check supporting documents', ['assignee' => self::OWNER, 'sla' => self::sla(8), 'allow_return' => false]),
                self::n('sign', 'approval', 'Two signatories approve', ['assignee' => ['type' => 'field', 'value' => 'signatories'], 'mode' => 'all', 'sla' => self::sla(16)]),
                self::n('release', 'user_task', 'Release the payment and record the reference', ['assignee' => self::OWNER]),
            ], 'pay', 'Paid'),
            ['pay' => self::form('Payment authorisation', [
                self::f('payee', 'Payee', 'text', true), self::f('amount', 'Amount', 'currency', true, ['validation' => ['min' => 0.01]]),
                self::f('purpose', 'Purpose', 'textarea', true), self::f('due_date', 'Pay by', 'date', true),
                self::f('signatories', 'Signatories (two people)', 'users', true), self::f('documents', 'Invoice / supporting documents', 'attachment', true),
            ])]);
    }

    private static function pettyCash(): array
    {
        return self::tpl('petty_cash', 'Petty cash request', 'Finance', 'Small cash request → line manager → custodian pays out → receipt attached within the week.',
            self::chain('Petty cash requested', [
                self::n('mgr', 'approval', 'Line manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(8)]),
                self::n('payout', 'user_task', 'Pay out the cash', ['assignee' => self::OWNER]),
                self::n('receipt', 'user_task', 'Attach the till slip', ['assignee' => self::REQUESTER, 'sla' => self::sla(40, 'notify_manager')]),
            ], 'cash', 'Closed'),
            ['cash' => self::form('Petty cash request', [
                self::f('amount', 'Amount', 'currency', true, ['validation' => ['min' => 1, 'max' => 2000]]), self::f('purpose', 'What it is for', 'textarea', true),
            ])]);
    }

    private static function staffLoan(): array
    {
        $d = self::amountDecision('size', 'More than R10 000?', 10000, 'exec', 'payroll');

        return self::tpl('staff_loan', 'Staff loan or salary advance', 'Finance',
            'Employee applies → line manager → HR checks eligibility → (above R10 000) executive approval → payroll sets up the deduction.',
            self::graph([
                self::n('start', 'start', 'Loan requested', ['form' => 'loan']),
                self::n('mgr', 'approval', 'Line manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(16)]),
                self::n('hr', 'approval', 'HR eligibility check', ['assignee' => self::OWNER, 'sla' => self::sla(16)]),
                $d['node'],
                self::n('exec', 'approval', 'Executive approval', ['assignee' => self::HEAD, 'sla' => self::sla(24)]),
                self::n('payroll', 'user_task', 'Set up the payroll deduction', ['assignee' => self::OWNER]),
                self::n('done', 'end', 'Loan granted', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], array_merge([
                ['start', 'mgr'], ['mgr', 'hr', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']],
                ['hr', 'size', ['outcome' => 'approved']], ['hr', 'no', ['outcome' => 'rejected']],
                ['exec', 'payroll', ['outcome' => 'approved']], ['exec', 'no', ['outcome' => 'rejected']], ['payroll', 'done'],
            ], array_map(fn ($e) => [$e['from'], $e['to'], array_diff_key($e, ['from' => 1, 'to' => 1])], $d['edges']))),
            ['loan' => self::form('Staff loan', [
                self::f('amount', 'Amount', 'currency', true, ['validation' => ['min' => 100]]), self::f('months', 'Repay over (months)', 'number', true, ['validation' => ['min' => 1, 'max' => 24]]),
                self::f('reason', 'Reason', 'textarea', true), self::f('acknowledge', 'I agree to monthly salary deductions until repaid', 'acknowledgement', true),
            ])]);
    }

    private static function budgetTransfer(): array
    {
        return self::tpl('budget_transfer', 'Budget transfer (virement)', 'Finance',
            'Request to move budget between lines → both budget holders agree → finance approves → finance processes the transfer in Budget.',
            self::graph([
                self::n('start', 'start', 'Transfer requested', ['form' => 'vire']),
                self::n('split', 'parallel', 'Both budget holders'),
                self::n('from', 'approval', 'Releasing budget holder', ['assignee' => ['type' => 'field', 'value' => 'from_holder'], 'sla' => self::sla(16)]),
                self::n('to', 'approval', 'Receiving budget holder', ['assignee' => ['type' => 'field', 'value' => 'to_holder'], 'sla' => self::sla(16)]),
                self::n('join', 'join', 'Both agreed', ['mode' => 'all']),
                self::n('fin', 'approval', 'Finance approval', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
                self::n('post', 'user_task', 'Process the transfer in Budget', ['assignee' => self::OWNER]),
                self::n('done', 'end', 'Transferred', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], [
                ['start', 'split'], ['split', 'from'], ['split', 'to'],
                ['from', 'join', ['outcome' => 'approved']], ['from', 'no', ['outcome' => 'rejected']],
                ['to', 'join', ['outcome' => 'approved']], ['to', 'no', ['outcome' => 'rejected']],
                ['join', 'fin'], ['fin', 'post', ['outcome' => 'approved']], ['fin', 'no', ['outcome' => 'rejected']], ['post', 'done'],
            ]),
            ['vire' => self::form('Budget transfer', [
                self::f('from_line', 'From budget line', 'text', true), self::f('from_holder', 'Its budget holder', 'user', true),
                self::f('to_line', 'To budget line', 'text', true), self::f('to_holder', 'Its budget holder', 'user', true),
                self::f('amount', 'Amount', 'currency', true), self::f('reason', 'Why', 'textarea', true),
            ])]);
    }

    private static function travelRequest(): array
    {
        return self::tpl('travel_request', 'Business travel request', 'Finance',
            'Trip request with estimated cost → line manager → (international) department head → travel desk books → traveller files the claim afterwards.',
            self::graph([
                self::n('start', 'start', 'Trip requested', ['form' => 'trip']),
                self::n('mgr', 'approval', 'Line manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(16)]),
                self::n('intl', 'decision', 'International?'),
                self::n('head', 'approval', 'Department head approval', ['assignee' => self::HEAD, 'sla' => self::sla(24)]),
                self::n('book', 'user_task', 'Book travel and accommodation', ['assignee' => self::OWNER, 'sla' => self::sla(16)]),
                self::n('done', 'end', 'Booked', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], [
                ['start', 'mgr'], ['mgr', 'intl', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']],
                ['intl', 'head', ['condition' => ['field' => 'international', 'operator' => 'true'], 'label' => 'International']], ['intl', 'book', ['is_default' => true, 'label' => 'Local']],
                ['head', 'book', ['outcome' => 'approved']], ['head', 'no', ['outcome' => 'rejected']], ['book', 'done'],
            ]),
            ['trip' => self::form('Travel request', [
                self::f('destination', 'Destination', 'text', true), self::f('dates', 'Travel dates', 'daterange', true),
                self::f('international', 'International travel', 'yesno'), self::f('purpose', 'Purpose', 'textarea', true),
                self::f('amount', 'Estimated cost', 'currency', true), self::f('passport_needed', 'Visa or passport documents', 'attachment', false, ['visible_if' => ['field' => 'international', 'operator' => 'true']]),
            ])]);
    }

    // ── HR ──────────────────────────────────────────────────────────────────

    private static function newPosition(): array
    {
        return self::tpl('new_position', 'New position approval', 'HR',
            'Manager motivates a new or replacement position → department head → HR (grading) → finance (budget) → recruitment can start.',
            self::chain('Position requested', [
                self::n('head', 'approval', 'Department head approval', ['assignee' => self::HEAD, 'sla' => self::sla(24)]),
                self::n('hr', 'review', 'HR grading and job profile', ['assignee' => self::OWNER, 'sla' => self::sla(40)]),
                self::n('fin', 'approval', 'Finance: budget available', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
            ], 'pos', 'Approved for recruitment', true),
            ['pos' => self::form('New position', [
                self::f('title', 'Position title', 'text', true), self::f('kind', 'New or replacement', 'radio', true, ['options' => ['New', 'Replacement']]),
                self::f('replacing', 'Replacing', 'user', false, ['visible_if' => ['field' => 'kind', 'operator' => 'eq', 'value' => 'Replacement'], 'required_if' => ['field' => 'kind', 'operator' => 'eq', 'value' => 'Replacement']]),
                self::f('amount', 'Annual cost to company', 'currency', true), self::f('motivation', 'Motivation', 'textarea', true), self::f('profile', 'Job profile', 'attachment'),
            ])]);
    }

    private static function onboarding(): array
    {
        return self::tpl('employee_onboarding', 'Employee onboarding', 'HR',
            'New starter → HR, IT and facilities prepare in parallel → manager welcomes and confirms day one → done.',
            self::graph([
                self::n('start', 'start', 'New starter confirmed', ['form' => 'starter']),
                self::n('split', 'parallel', 'Prepare'),
                self::n('hr', 'user_task', 'HR: contract, payroll and benefits', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
                self::n('it', 'user_task', 'IT: laptop, email and system access', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
                self::n('fac', 'user_task', 'Facilities: desk, access card, parking', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
                self::n('join', 'join', 'All ready', ['mode' => 'all']),
                self::n('welcome', 'user_task', 'Welcome and confirm day one', ['assignee' => ['type' => 'field', 'value' => 'manager']]),
                self::n('done', 'end', 'Onboarded', ['outcome' => 'completed']),
            ], [
                ['start', 'split'], ['split', 'hr'], ['split', 'it'], ['split', 'fac'], ['hr', 'join'], ['it', 'join'], ['fac', 'join'], ['join', 'welcome'], ['welcome', 'done'],
            ]),
            ['starter' => self::form('New starter', [
                self::f('name', 'Full name', 'text', true), self::f('start_date', 'Start date', 'date', true), self::f('position', 'Position', 'text', true),
                self::f('manager', 'Manager', 'user', true), self::f('department', 'Department', 'department'), self::f('systems', 'Systems needed', 'checkboxes', false, ['options' => ['ERP', 'Payroll', 'CRM', 'Email', 'VPN']]),
            ])]);
    }

    private static function offboarding(): array
    {
        return self::tpl('employee_offboarding', 'Employee exit clearance', 'HR',
            'Resignation or termination → IT revokes access, assets are returned and finance settles in parallel → HR final pay and certificate of service.',
            self::graph([
                self::n('start', 'start', 'Exit confirmed', ['form' => 'exit']),
                self::n('split', 'parallel', 'Clear'),
                self::n('it', 'user_task', 'IT: revoke access, collect devices', ['assignee' => self::OWNER, 'sla' => self::sla(8)]),
                self::n('assets', 'user_task', 'Return company assets', ['assignee' => ['type' => 'field', 'value' => 'manager'], 'sla' => self::sla(16)]),
                self::n('fin', 'user_task', 'Finance: settle loans, advances, claims', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
                self::n('join', 'join', 'Cleared', ['mode' => 'all']),
                self::n('hr', 'user_task', 'HR: final pay, UI-19 and certificate of service', ['assignee' => self::OWNER]),
                self::n('done', 'end', 'Exit complete', ['outcome' => 'completed']),
            ], [
                ['start', 'split'], ['split', 'it'], ['split', 'assets'], ['split', 'fin'], ['it', 'join'], ['assets', 'join'], ['fin', 'join'], ['join', 'hr'], ['hr', 'done'],
            ]),
            ['exit' => self::form('Exit clearance', [
                self::f('employee', 'Employee', 'user', true), self::f('last_day', 'Last working day', 'date', true),
                self::f('reason', 'Reason', 'select', true, ['options' => ['Resignation', 'Retirement', 'End of contract', 'Dismissal', 'Retrenchment', 'Death']]),
                self::f('manager', 'Manager', 'user', true),
            ])]);
    }

    private static function trainingRequest(): array
    {
        return self::tpl('training_request', 'Training request', 'HR', 'Employee requests a course → line manager → (above R15 000) department head → HR books it.',
            self::graph([
                self::n('start', 'start', 'Training requested', ['form' => 'course']),
                self::n('mgr', 'approval', 'Line manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(24)]),
                self::n('cost', 'decision', 'More than R15 000?'),
                self::n('head', 'approval', 'Department head approval', ['assignee' => self::HEAD, 'sla' => self::sla(24)]),
                self::n('book', 'user_task', 'HR books the course', ['assignee' => self::OWNER]),
                self::n('done', 'end', 'Booked', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], [
                ['start', 'mgr'], ['mgr', 'cost', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']],
                ['cost', 'head', ['condition' => ['field' => 'amount', 'operator' => 'gt', 'value' => 15000], 'label' => '> R15 000']], ['cost', 'book', ['is_default' => true]],
                ['head', 'book', ['outcome' => 'approved']], ['head', 'no', ['outcome' => 'rejected']], ['book', 'done'],
            ]),
            ['course' => self::form('Training request', [
                self::f('course', 'Course', 'text', true), self::f('provider', 'Provider', 'text', true), self::f('dates', 'Dates', 'daterange', true),
                self::f('amount', 'Cost', 'currency', true), self::f('benefit', 'How it helps your role', 'textarea', true),
            ])]);
    }

    private static function overtime(): array
    {
        return self::tpl('overtime_preapproval', 'Overtime pre-approval', 'HR', 'Overtime requested before it is worked (BCEA limits apply) → line manager → payroll is told.',
            self::chain('Overtime requested', [
                self::n('mgr', 'approval', 'Line manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(8, 'reassign_manager')]),
                self::n('payroll', 'notification', 'Tell payroll', ['to' => self::OWNER, 'subject' => 'Overtime approved: {{requester.name}}', 'message' => '{{hours}} hours on {{date}}: {{reason}}']),
            ], 'ot', 'Approved'),
            ['ot' => self::form('Overtime', [
                self::f('date', 'Date', 'date', true), self::f('hours', 'Hours', 'decimal', true, ['validation' => ['min' => 0.5, 'max' => 10]]), self::f('reason', 'Reason', 'textarea', true),
            ])]);
    }

    private static function disciplinary(): array
    {
        return self::tpl('disciplinary_case', 'Disciplinary case', 'HR',
            'Confidential. Incident reported → HR investigates → hearing chaired by an independent person → outcome confirmed by HR → employee informed. Only the people named on it see it.',
            self::chain('Matter reported', [
                self::n('investigate', 'user_task', 'Investigate and gather statements', ['assignee' => self::OWNER, 'sla' => self::sla(80, 'notify_owner')]),
                self::n('hearing', 'user_task', 'Hold the hearing and record the finding', ['assignee' => ['type' => 'field', 'value' => 'chair']]),
                self::n('confirm', 'approval', 'HR confirms the outcome', ['assignee' => self::OWNER]),
                self::n('inform', 'user_task', 'Inform the employee in writing', ['assignee' => self::OWNER]),
            ], 'case', 'Closed'),
            ['case' => self::form('Disciplinary matter', [
                self::f('employee', 'Employee', 'user', true, ['sensitive' => true]), self::f('date', 'Date of the incident', 'date', true),
                self::f('allegation', 'Allegation', 'textarea', true, ['sensitive' => true]), self::f('chair', 'Independent chairperson', 'user', true),
                self::f('evidence', 'Evidence', 'attachment'),
            ])], ['confidential' => true]);
    }

    // ── Procurement, legal, sales ───────────────────────────────────────────

    private static function supplierOnboarding(): array
    {
        return self::tpl('supplier_onboarding', 'Supplier onboarding', 'Procurement',
            'New supplier → procurement checks tax clearance, B-BBEE and bank details → finance verifies the bank account → approved for use.',
            self::chain('Supplier proposed', [
                self::n('kyc', 'review', 'Procurement: compliance documents', ['assignee' => self::OWNER, 'sla' => self::sla(24), 'allow_return' => false]),
                self::n('bank', 'approval', 'Finance: verify bank details', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
            ], 'supplier', 'Approved supplier'),
            ['supplier' => self::form('New supplier', [
                self::f('name', 'Registered name', 'text', true), self::f('registration', 'Company registration number', 'text', true),
                self::f('vat_number', 'VAT number', 'text', false, ['validation' => ['pattern' => 'sa_vat_number', 'message' => 'A South African VAT number is 10 digits starting with 4.']]),
                self::f('bbbee_level', 'B-BBEE level', 'select', true, ['options' => ['1', '2', '3', '4', '5', '6', '7', '8', 'Non-compliant']]),
                self::f('documents', 'Tax clearance, B-BBEE certificate, bank letter', 'attachment', true),
            ])]);
    }

    private static function contractApproval(): array
    {
        return self::tpl('contract_approval', 'Contract approval', 'Legal',
            'Contract submitted → legal and finance review at the same time → department head approves → signed copy filed.',
            self::graph([
                self::n('start', 'start', 'Contract submitted', ['form' => 'contract']),
                self::n('split', 'parallel', 'Reviews'),
                self::n('legal', 'approval', 'Legal review', ['assignee' => self::OWNER, 'sla' => self::sla(40)]),
                self::n('fin', 'approval', 'Finance review', ['assignee' => self::OWNER, 'sla' => self::sla(24), 'distinct_approvers' => false]),
                self::n('join', 'join', 'Both reviews done', ['mode' => 'all']),
                self::n('head', 'approval', 'Department head approval', ['assignee' => self::HEAD, 'sla' => self::sla(24)]),
                self::n('sign', 'user_task', 'Sign and file the signed copy', ['assignee' => self::REQUESTER]),
                self::n('done', 'end', 'Signed', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], [
                ['start', 'split'], ['split', 'legal'], ['split', 'fin'],
                ['legal', 'join', ['outcome' => 'approved']], ['legal', 'no', ['outcome' => 'rejected']],
                ['fin', 'join', ['outcome' => 'approved']], ['fin', 'no', ['outcome' => 'rejected']],
                ['join', 'head'], ['head', 'sign', ['outcome' => 'approved']], ['head', 'no', ['outcome' => 'rejected']], ['sign', 'done'],
            ]),
            ['contract' => self::form('Contract', [
                self::f('counterparty', 'Counterparty', 'text', true), self::f('amount', 'Contract value', 'currency', true),
                self::f('term', 'Term', 'daterange', true), self::f('summary', 'Key terms', 'textarea', true), self::f('draft', 'Draft contract', 'attachment', true),
            ])]);
    }

    private static function quotationDiscount(): array
    {
        return self::tpl('quotation_discount', 'Quotation discount approval', 'Sales',
            'Sales quote with a discount → above 10% the sales manager approves → above 25% a director too → quote released.',
            self::graph([
                self::n('start', 'start', 'Discount requested', ['form' => 'quote']),
                self::n('band', 'decision', 'Discount band'),
                self::n('mgr', 'approval', 'Sales manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(8)]),
                self::n('big', 'decision', 'More than 25%?'),
                self::n('dir', 'approval', 'Director approval', ['assignee' => self::HEAD, 'sla' => self::sla(8)]),
                self::n('done', 'end', 'Quote released', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], [
                ['start', 'band'], ['band', 'mgr', ['condition' => ['field' => 'discount', 'operator' => 'gt', 'value' => 10], 'label' => '> 10%']], ['band', 'done', ['is_default' => true, 'label' => 'up to 10%']],
                ['mgr', 'big', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']],
                ['big', 'dir', ['condition' => ['field' => 'discount', 'operator' => 'gt', 'value' => 25], 'label' => '> 25%']], ['big', 'done', ['is_default' => true]],
                ['dir', 'done', ['outcome' => 'approved']], ['dir', 'no', ['outcome' => 'rejected']],
            ]),
            ['quote' => self::form('Discount', [
                self::f('customer', 'Customer', 'text', true), self::f('quote_number', 'Quote number', 'text', true),
                self::f('amount', 'Quote value', 'currency', true), self::f('discount', 'Discount (%)', 'decimal', true, ['validation' => ['min' => 0, 'max' => 100]]), self::f('reason', 'Why', 'textarea', true),
            ])]);
    }

    // ── IT, documents, governance ───────────────────────────────────────────

    private static function itAccess(): array
    {
        return self::tpl('it_access', 'IT system access', 'IT', 'Access request → line manager → (admin access) system owner → IT grants it.',
            self::graph([
                self::n('start', 'start', 'Access requested', ['form' => 'access']),
                self::n('mgr', 'approval', 'Line manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(8)]),
                self::n('level', 'decision', 'Admin access?'),
                self::n('owner', 'approval', 'System owner approval', ['assignee' => self::OWNER, 'sla' => self::sla(16)]),
                self::n('grant', 'user_task', 'IT grants the access', ['assignee' => self::OWNER, 'sla' => self::sla(8)]),
                self::n('done', 'end', 'Access granted', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], [
                ['start', 'mgr'], ['mgr', 'level', ['outcome' => 'approved']], ['mgr', 'no', ['outcome' => 'rejected']],
                ['level', 'owner', ['condition' => ['field' => 'level', 'operator' => 'eq', 'value' => 'Admin'], 'label' => 'Admin']], ['level', 'grant', ['is_default' => true]],
                ['owner', 'grant', ['outcome' => 'approved']], ['owner', 'no', ['outcome' => 'rejected']], ['grant', 'done'],
            ]),
            ['access' => self::form('System access', [
                self::f('system', 'System', 'select', true, ['options' => ['ERP', 'Payroll', 'CRM', 'Email', 'VPN', 'File server']]),
                self::f('level', 'Access level', 'radio', true, ['options' => ['Read', 'Write', 'Admin']]),
                self::f('justification', 'Why admin access is needed', 'textarea', false, ['visible_if' => ['field' => 'level', 'operator' => 'eq', 'value' => 'Admin'], 'required_if' => ['field' => 'level', 'operator' => 'eq', 'value' => 'Admin']]),
            ])]);
    }

    private static function itChange(): array
    {
        return self::tpl('it_change', 'IT change request', 'IT',
            'Change proposed → technical review → change advisory board (2 of 3 approve) → implemented → post-implementation review.',
            self::chain('Change proposed', [
                self::n('tech', 'review', 'Technical review and risk', ['assignee' => self::OWNER, 'sla' => self::sla(16), 'allow_return' => false]),
                self::n('cab', 'approval', 'Change advisory board (2 of 3)', ['assignee' => ['type' => 'field', 'value' => 'cab'], 'mode' => 'quorum', 'quorum' => 2, 'sla' => self::sla(24)]),
                self::n('implement', 'user_task', 'Implement in the change window', ['assignee' => self::REQUESTER]),
                self::n('pir', 'review', 'Post-implementation review', ['assignee' => self::OWNER]),
            ], 'change', 'Closed'),
            ['change' => self::form('Change request', [
                self::f('summary', 'Change', 'text', true), self::f('risk', 'Risk', 'select', true, ['options' => ['Low', 'Medium', 'High']]),
                self::f('window', 'Change window', 'daterange', true), self::f('rollback', 'Rollback plan', 'textarea', true),
                self::f('cab', 'Change advisory board members', 'users', true),
            ])]);
    }

    private static function documentReview(): array
    {
        return self::tpl('document_review', 'Document review and approval', 'Governance',
            'Draft document → reviewers comment in parallel → owner updates → approver signs off → published in Document Management.',
            self::chain('Draft submitted', [
                self::n('review', 'review', 'Peer review', ['assignee' => self::HEAD, 'sla' => self::sla(40), 'allow_return' => false]),
                self::n('update', 'user_task', 'Update the draft from the comments', ['assignee' => self::REQUESTER]),
                self::n('approve', 'approval', 'Approve for publication', ['assignee' => self::OWNER, 'sla' => self::sla(24)]),
                self::n('publish', 'user_task', 'Publish in Document Management', ['assignee' => self::REQUESTER]),
            ], 'doc', 'Published', true),
            ['doc' => self::form('Document for review', [
                self::f('title', 'Document', 'text', true), self::f('version', 'Version', 'text', true), self::f('changes', 'What changed', 'textarea', true), self::f('file', 'Draft', 'attachment', true),
            ])]);
    }

    private static function policyApproval(): array
    {
        return self::tpl('policy_approval', 'Policy approval', 'Governance',
            'Policy drafted → compliance check → executive committee (majority) → board noting → policy published and staff notified.',
            self::chain('Policy drafted', [
                self::n('compliance', 'review', 'Compliance and legal check', ['assignee' => self::OWNER, 'sla' => self::sla(40), 'allow_return' => false]),
                self::n('exco', 'approval', 'Executive committee (majority)', ['assignee' => ['type' => 'field', 'value' => 'committee'], 'mode' => 'quorum', 'quorum' => 2, 'allow_abstain' => true, 'sla' => self::sla(80)]),
                self::n('publish', 'user_task', 'Publish the policy and tell staff', ['assignee' => self::OWNER]),
            ], 'policy', 'Policy in force', true),
            ['policy' => self::form('Policy', [
                self::f('title', 'Policy', 'text', true), self::f('effective', 'Effective from', 'date', true), self::f('summary', 'Summary of changes', 'textarea', true),
                self::f('committee', 'Committee members', 'users', true), self::f('draft', 'Policy document', 'attachment', true),
            ])]);
    }

    private static function boardResolution(): array
    {
        return self::tpl('board_resolution', 'Round-robin board resolution', 'Governance',
            'Resolution circulated → directors vote (quorum, conflicts declared) → company secretary records the resolution.',
            self::chain('Resolution circulated', [
                self::n('vote', 'approval', 'Directors vote', ['assignee' => ['type' => 'field', 'value' => 'directors'], 'mode' => 'quorum', 'quorum' => 3, 'allow_abstain' => true, 'distinct_approvers' => false, 'sla' => self::sla(120, 'notify_owner')]),
                self::n('record', 'user_task', 'Company secretary records the resolution', ['assignee' => self::OWNER]),
            ], 'res', 'Resolution passed'),
            ['res' => self::form('Resolution', [
                self::f('title', 'Resolution', 'text', true), self::f('text', 'Wording', 'textarea', true),
                self::f('directors', 'Directors', 'users', true), self::f('papers', 'Supporting papers', 'attachment'),
            ])]);
    }

    // ── SHEQ, facilities, fleet, assets, manufacturing ──────────────────────

    private static function incidentReport(): array
    {
        return self::tpl('incident_report', 'Safety incident report', 'SHEQ',
            'Incident reported → serious incidents alert the SHEQ manager immediately → investigation → corrective actions → closure.',
            self::graph([
                self::n('start', 'start', 'Incident reported', ['form' => 'incident']),
                self::n('sev', 'decision', 'Serious?'),
                self::n('alert', 'notification', 'Alert the SHEQ manager now', ['to' => self::OWNER, 'subject' => 'Serious incident at {{site}}', 'message' => '{{severity}}: {{what_happened}}']),
                self::n('investigate', 'user_task', 'Investigate the root cause', ['assignee' => self::OWNER, 'sla' => self::sla(40, 'notify_owner')]),
                self::n('act', 'user_task', 'Complete the corrective actions', ['assignee' => self::HEAD, 'sla' => self::sla(80)]),
                self::n('close', 'approval', 'SHEQ closes the incident', ['assignee' => self::OWNER, 'allow_return' => true, 'distinct_approvers' => false]),
                self::n('done', 'end', 'Closed', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not accepted', ['outcome' => 'rejected']),
            ], [
                ['start', 'sev'], ['sev', 'alert', ['condition' => ['field' => 'severity', 'operator' => 'in', 'value' => ['Serious', 'Fatal']], 'label' => 'Serious or fatal']], ['sev', 'investigate', ['is_default' => true]],
                ['alert', 'investigate'], ['investigate', 'act'], ['act', 'close'],
                ['close', 'done', ['outcome' => 'approved']], ['close', 'act', ['outcome' => 'returned']], ['close', 'no', ['outcome' => 'rejected']],
            ]),
            ['incident' => self::form('Incident', [
                self::f('site', 'Site', 'text', true), self::f('occurred_at', 'Date', 'date', true),
                self::f('severity', 'Severity', 'select', true, ['options' => ['Near miss', 'Minor', 'Serious', 'Fatal']]),
                self::f('what_happened', 'What happened', 'textarea', true), self::f('injured', 'Anyone injured', 'yesno'),
                self::f('injury_details', 'Injury details', 'textarea', false, ['visible_if' => ['field' => 'injured', 'operator' => 'true'], 'required_if' => ['field' => 'injured', 'operator' => 'true'], 'sensitive' => true]),
                self::f('photos', 'Photos', 'attachment'),
            ])]);
    }

    private static function maintenanceRequest(): array
    {
        $d = self::amountDecision('cost', 'Quote above R20 000?', 20000, 'approve', 'work');

        return self::tpl('maintenance_request', 'Maintenance request', 'Facilities',
            'Fault logged → facilities triages and gets a quote → (above R20 000) department head approves → work done → requester confirms it is fixed.',
            self::graph([
                self::n('start', 'start', 'Fault logged', ['form' => 'fault']),
                self::n('triage', 'user_task', 'Triage and obtain a quote', ['assignee' => self::OWNER, 'sla' => self::sla(16)]),
                $d['node'],
                self::n('approve', 'approval', 'Department head approves the quote', ['assignee' => self::HEAD, 'sla' => self::sla(24)]),
                self::n('work', 'user_task', 'Do the work', ['assignee' => self::OWNER, 'sla' => self::sla(40)]),
                self::n('confirm', 'review', 'Confirm it is fixed', ['assignee' => self::REQUESTER, 'allow_return' => true]),
                self::n('done', 'end', 'Fixed', ['outcome' => 'completed']),
                self::n('no', 'end', 'Not approved', ['outcome' => 'rejected']),
            ], array_merge([
                ['start', 'triage'], ['triage', 'cost'], ['approve', 'work', ['outcome' => 'approved']], ['approve', 'no', ['outcome' => 'rejected']],
                ['work', 'confirm'], ['confirm', 'done', ['outcome' => 'completed']], ['confirm', 'work', ['outcome' => 'returned']],
            ], array_map(fn ($e) => [$e['from'], $e['to'], array_diff_key($e, ['from' => 1, 'to' => 1])], $d['edges']))),
            ['fault' => self::form('Maintenance request', [
                self::f('location', 'Where', 'text', true), self::f('fault', 'What is wrong', 'textarea', true),
                self::f('urgency', 'Urgency', 'select', true, ['options' => ['Low', 'Normal', 'Urgent — safety risk']]), self::f('amount', 'Quoted cost (filled in by facilities)', 'currency'), self::f('photos', 'Photos', 'attachment'),
            ])]);
    }

    private static function vehicleBooking(): array
    {
        return self::tpl('vehicle_booking', 'Pool vehicle booking', 'Fleet', 'Booking request → line manager → fleet allocates a vehicle → driver returns it and records the odometer.',
            self::chain('Vehicle requested', [
                self::n('mgr', 'approval', 'Line manager approval', ['assignee' => self::MANAGER, 'sla' => self::sla(8)]),
                self::n('allocate', 'user_task', 'Fleet allocates a vehicle', ['assignee' => self::OWNER, 'sla' => self::sla(8)]),
                self::n('return', 'user_task', 'Return the vehicle and record the odometer', ['assignee' => self::REQUESTER]),
            ], 'trip', 'Returned'),
            ['trip' => self::form('Vehicle booking', [
                self::f('dates', 'When', 'daterange', true), self::f('destination', 'Destination', 'text', true), self::f('passengers', 'Passengers', 'number'),
                self::f('licence', 'I hold a valid driver\'s licence for this vehicle class', 'acknowledgement', true),
            ])]);
    }

    private static function assetDisposal(): array
    {
        return self::tpl('asset_disposal', 'Asset disposal', 'Assets',
            'Asset proposed for disposal → asset manager confirms its condition and book value → finance approves → disposed and removed from the register.',
            self::chain('Disposal proposed', [
                self::n('assess', 'review', 'Asset manager: condition and book value', ['assignee' => self::OWNER, 'sla' => self::sla(24), 'allow_return' => false]),
                self::n('fin', 'approval', 'Finance approval', ['assignee' => self::HEAD, 'sla' => self::sla(24)]),
                self::n('dispose', 'user_task', 'Dispose and update the asset register', ['assignee' => self::OWNER]),
            ], 'asset', 'Disposed'),
            ['asset' => self::form('Asset disposal', [
                self::f('asset_tag', 'Asset tag', 'text', true), self::f('method', 'Method', 'select', true, ['options' => ['Sale', 'Scrap', 'Donation', 'Trade-in']]),
                self::f('amount', 'Expected proceeds', 'currency'), self::f('reason', 'Reason', 'textarea', true), self::f('photos', 'Photos', 'attachment'),
            ])]);
    }

    private static function batchRelease(): array
    {
        return self::tpl('batch_release', 'Production batch release', 'Manufacturing',
            'Batch completed → quality control tests → QA manager releases or quarantines → warehouse receives released stock.',
            self::graph([
                self::n('start', 'start', 'Batch completed', ['form' => 'batch']),
                self::n('qc', 'user_task', 'Quality control tests', ['assignee' => self::OWNER, 'sla' => self::sla(16)]),
                self::n('qa', 'approval', 'QA manager release decision', ['assignee' => self::HEAD, 'sla' => self::sla(8)]),
                self::n('receive', 'user_task', 'Warehouse receives the released stock', ['assignee' => self::OWNER]),
                self::n('quarantine', 'user_task', 'Quarantine and log a non-conformance', ['assignee' => self::OWNER]),
                self::n('done', 'end', 'Released', ['outcome' => 'completed']),
                self::n('no', 'end', 'Quarantined', ['outcome' => 'rejected']),
            ], [
                ['start', 'qc'], ['qc', 'qa'], ['qa', 'receive', ['outcome' => 'approved']], ['qa', 'quarantine', ['outcome' => 'rejected']],
                ['receive', 'done'], ['quarantine', 'no'],
            ]),
            ['batch' => self::form('Batch', [
                self::f('batch', 'Batch number', 'text', true), self::f('product', 'Product', 'text', true), self::f('quantity', 'Quantity', 'decimal', true),
                self::f('results', 'Test results / certificate of analysis', 'attachment', true),
            ])]);
    }

    private static function monthlyCompliance(): array
    {
        return self::tpl('monthly_compliance_review', 'Monthly compliance review', 'Governance',
            'Starts by itself on the 1st of each month → compliance officer completes the checklist → head of governance signs off.',
            self::chain('Month started', [
                self::n('check', 'user_task', 'Complete the monthly compliance checklist', ['assignee' => self::OWNER, 'sla' => self::sla(40, 'notify_owner')]),
                self::n('signoff', 'approval', 'Head of governance signs off', ['assignee' => self::HEAD, 'sla' => self::sla(24), 'distinct_approvers' => false]),
            ], null, 'Signed off'),
            [], ['trigger_type' => 'scheduled', 'trigger_config' => ['frequency' => 'monthly', 'day' => 1, 'time' => '07:00']]);
    }
}
