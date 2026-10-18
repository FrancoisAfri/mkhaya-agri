<?php

namespace App\Modules\Workflow\Support;

/**
 * Ready-made forms that ship with the system (code, not seeded rows, so every installation has them).
 *
 * Installing one copies its fields into a company-owned form at version 1; the company then edits
 * its copy freely. The library is the standalone forms below plus every form the workflow templates
 * carry, so a form seen inside a template can also be taken on its own.
 */
final class FormLibrary
{
    /** @return array<string, array{key:string, name:string, category:string, description:string, fields:list<array>, source:string}> */
    public static function everything(): array
    {
        $out = self::standalone();
        foreach (TemplateLibrary::everything() as $tplKey => $tpl) {
            foreach ($tpl['forms'] ?? [] as $formKey => $form) {
                $key = "{$tplKey}__{$formKey}";
                $out[$key] = ['key' => $key, 'name' => $form['name'], 'category' => $tpl['category'],
                    'description' => "The form used by the \"{$tpl['name']}\" workflow template.", 'fields' => $form['fields'], 'source' => 'template'];
            }
        }

        return $out;
    }

    public static function get(string $key): ?array
    {
        return self::everything()[$key] ?? null;
    }

    /** @return array<string, array> */
    public static function standalone(): array
    {
        $forms = [
            // ── General ──────────────────────────────────────────────────────────────
            self::form('general_request', 'General request', 'General', 'A simple request for anything without its own form.', [
                self::f('subject', 'Subject', 'text', true), self::f('details', 'Details', 'textarea', true),
                self::f('urgency', 'Urgency', 'radio', true, ['options' => ['Low', 'Normal', 'High']]), self::f('needed_by', 'Needed by', 'date'),
                self::f('attachments', 'Supporting documents', 'attachment'),
            ]),
            self::form('suggestion', 'Suggestion or feedback', 'General', 'An idea or feedback from staff, with the benefit it brings.', [
                self::f('topic', 'Topic', 'select', true, ['options' => ['Process', 'Customer service', 'Safety', 'Cost saving', 'Workplace', 'Other']]),
                self::f('idea', 'Your suggestion', 'textarea', true), self::f('benefit', 'Expected benefit', 'textarea'),
                self::f('anonymous', 'Submit anonymously', 'yesno'),
            ]),
            self::form('meeting_room_booking', 'Meeting room or venue booking', 'General', 'Book a room or venue, with catering and equipment.', [
                self::f('venue', 'Room or venue', 'text', true), self::f('date', 'Date', 'date', true),
                self::f('start', 'Start', 'time', true), self::f('end', 'End', 'time', true),
                self::f('attendees', 'Number of attendees', 'number', true, ['validation' => ['min' => 1]]),
                self::f('needs', 'Also needed', 'checkboxes', false, ['options' => ['Projector / screen', 'Video conference', 'Catering', 'Parking for guests']]),
                self::f('catering', 'Catering details', 'textarea', false, self::when('needs', 'contains', 'Catering')),
            ]),
            self::form('equipment_loan', 'Equipment loan', 'General', 'Borrow company equipment and agree to return it in good order.', [
                self::f('item', 'Equipment', 'text', true), self::f('period', 'Loan period', 'daterange', true), self::f('purpose', 'Purpose', 'textarea', true),
                self::f('agree', 'I accept responsibility for loss or damage while the equipment is with me', 'acknowledgement', true),
            ]),
            self::form('event_request', 'Event or function request', 'General', 'Plan a staff, client or community event with a budget.', [
                self::f('event', 'Event', 'text', true), self::f('kind', 'Type', 'select', true, ['options' => ['Staff', 'Client', 'Community / CSI', 'Training', 'Other']]),
                self::f('date', 'Date', 'date', true), self::f('guests', 'Expected guests', 'number', true), self::f('venue', 'Venue', 'text'),
                self::f('budget', 'Budget', 'currency', true), self::f('plan', 'Plan and suppliers', 'textarea'),
            ]),

            // ── HR ───────────────────────────────────────────────────────────────────
            self::form('personal_details_change', 'Change of personal details', 'HR', 'Update address, contact, marital status or next of kin.', [
                self::f('change', 'What is changing', 'checkboxes', true, ['options' => ['Address', 'Phone / email', 'Marital status', 'Next of kin', 'Name']]),
                self::f('address', 'New address', 'textarea', false, self::when('change', 'contains', 'Address')),
                self::f('phone', 'New phone', 'phone', false, self::when('change', 'contains', 'Phone / email')),
                self::f('email', 'New personal email', 'email', false, ['visible_if' => ['field' => 'change', 'operator' => 'contains', 'value' => 'Phone / email']]),
                self::f('kin_name', 'Next of kin', 'text', false, self::when('change', 'contains', 'Next of kin')),
                self::f('kin_phone', 'Next of kin phone', 'phone', false, self::when('change', 'contains', 'Next of kin')),
                self::f('effective', 'Effective from', 'date', true), self::f('proof', 'Proof (marriage certificate, ID, utility bill)', 'attachment'),
            ]),
            self::form('bank_details_change', 'Employee bank details change', 'HR', 'Change the account salary is paid into, with proof and a declaration (a common fraud target: verify by phone).', [
                self::f('bank', 'Bank', 'select', true, ['options' => ['ABSA', 'Capitec', 'FNB', 'Nedbank', 'Standard Bank', 'TymeBank', 'Discovery Bank', 'African Bank', 'Investec', 'Other']]),
                self::f('account_holder', 'Account holder', 'text', true), self::f('account_number', 'Account number', 'text', true, ['validation' => ['pattern' => 'digits'], 'sensitive' => true]),
                self::f('account_type', 'Account type', 'radio', true, ['options' => ['Cheque / current', 'Savings', 'Transmission']]),
                self::f('branch_code', 'Branch code', 'text', true, ['validation' => ['pattern' => 'digits']]),
                self::f('proof', 'Bank confirmation letter (not older than 3 months)', 'attachment', true),
                self::f('declare', 'I confirm the account is in my own name and I asked for this change', 'acknowledgement', true),
            ]),
            self::form('remote_work_request', 'Remote or hybrid work request', 'HR', 'Ask to work from home on set days, with how work will be covered.', [
                self::f('arrangement', 'Arrangement', 'radio', true, ['options' => ['Fully remote', 'Hybrid', 'Temporary (date range)']]),
                self::f('days', 'Days away from the office', 'checkboxes', false, ['options' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                    'visible_if' => ['field' => 'arrangement', 'operator' => 'eq', 'value' => 'Hybrid']]),
                self::f('period', 'Period', 'daterange', false, self::when('arrangement', 'eq', 'Temporary (date range)')),
                self::f('reason', 'Reason', 'textarea', true), self::f('connectivity', 'I have a reliable connection and a safe place to work', 'acknowledgement', true),
            ]),
            self::form('resignation', 'Resignation', 'HR', 'A resignation with notice period and handover plan.', [
                self::f('last_day', 'Last working day', 'date', true), self::f('reason', 'Main reason', 'select', true,
                    ['options' => ['Better offer', 'Career growth', 'Relocation', 'Personal / family', 'Studies', 'Retirement', 'Other']]),
                self::f('handover', 'Handover plan', 'textarea', true), self::f('letter', 'Signed resignation letter', 'attachment', true),
            ]),
            self::form('exit_interview', 'Exit interview', 'HR', 'Confidential feedback from a person leaving.', [
                self::f('leaving', 'Main reason for leaving', 'select', true, ['options' => ['Pay', 'Manager', 'Growth', 'Workload', 'Culture', 'Personal', 'Other']]),
                self::f('manager_rating', 'How would you rate your manager?', 'radio', true, ['options' => ['1', '2', '3', '4', '5']]),
                self::f('culture_rating', 'How would you rate the culture?', 'radio', true, ['options' => ['1', '2', '3', '4', '5']]),
                self::f('would_return', 'Would you work here again?', 'yesno', true), self::f('improve', 'What should we improve?', 'textarea'),
            ]),
            self::form('grievance', 'Grievance', 'HR', 'A formal grievance under the grievance procedure.', [
                self::f('against', 'Grievance concerns', 'select', true, ['options' => ['A colleague', 'My manager', 'A policy or decision', 'Working conditions', 'Other']]),
                self::f('person', 'Person concerned', 'user', false, ['visible_if' => ['field' => 'against', 'operator' => 'in', 'value' => ['A colleague', 'My manager']]]),
                self::f('when', 'When it happened', 'date', true), self::f('details', 'What happened', 'textarea', true),
                self::f('witnesses', 'Witnesses', 'users'), self::f('outcome', 'Outcome you are asking for', 'textarea', true),
                self::f('evidence', 'Evidence', 'attachment'),
            ]),
            self::form('performance_improvement_plan', 'Performance improvement plan', 'HR', 'Agreed goals, support and review dates for an employee who needs to improve.', [
                self::f('employee', 'Employee', 'user', true), self::f('concerns', 'Performance concerns', 'textarea', true),
                self::f('goals', 'Goals', 'line_items', true, ['min_rows' => 1, 'columns' => [
                    self::f('goal', 'Goal', 'text', true), self::f('measure', 'How it is measured', 'text', true), self::f('due', 'Due', 'date', true)]]),
                self::f('support', 'Support provided', 'textarea', true), self::f('review', 'Final review date', 'date', true),
            ]),
            self::form('sick_note', 'Sick note submission', 'HR', 'Submit a medical certificate for sick leave already taken.', [
                self::f('period', 'Days off sick', 'daterange', true), self::f('practitioner', 'Doctor or clinic', 'text', true),
                self::f('certificate', 'Medical certificate', 'attachment', true),
            ]),
            self::form('study_assistance', 'Study assistance', 'HR', 'Funding for a qualification, with a work-back agreement.', [
                self::f('qualification', 'Qualification', 'text', true), self::f('institution', 'Institution', 'text', true),
                self::f('year', 'Year of study', 'number', true, ['validation' => ['min' => 1, 'max' => 10]]),
                self::f('fees', 'Fees for the year', 'currency', true), self::f('relevance', 'How it helps your role', 'textarea', true),
                self::f('quote', 'Fee statement', 'attachment', true),
                self::f('agree', 'I agree to repay the assistance if I leave within the work-back period', 'acknowledgement', true),
            ]),
            self::form('shift_swap', 'Shift swap', 'HR', 'Swap a shift with a colleague who agrees.', [
                self::f('colleague', 'Colleague', 'user', true), self::f('my_shift', 'My shift date', 'date', true), self::f('their_shift', 'Their shift date', 'date', true),
                self::f('reason', 'Reason', 'textarea'),
            ]),
            self::form('timesheet_correction', 'Timesheet or clocking correction', 'HR', 'Correct a missed or wrong clocking.', [
                self::f('date', 'Date', 'date', true), self::f('in', 'Actual time in', 'time', true), self::f('out', 'Actual time out', 'time', true),
                self::f('reason', 'Why the clocking is wrong', 'textarea', true),
            ]),
            self::form('employee_referral', 'Employee referral', 'HR', 'Refer someone for an open position.', [
                self::f('candidate', 'Candidate name', 'text', true), self::f('email', 'Candidate email', 'email', true), self::f('phone', 'Candidate phone', 'phone'),
                self::f('position', 'Position', 'text', true), self::f('relationship', 'How you know them', 'text', true), self::f('cv', 'CV', 'attachment', true),
            ]),

            // ── Finance ──────────────────────────────────────────────────────────────
            self::form('supplier_bank_change', 'Supplier bank details change', 'Finance', 'Change where a supplier is paid. Must be verified by calling the supplier on a known number.', [
                self::f('supplier', 'Supplier', 'supplier', true), self::f('bank', 'New bank', 'text', true),
                self::f('account_number', 'New account number', 'text', true, ['validation' => ['pattern' => 'digits'], 'sensitive' => true]),
                self::f('branch_code', 'Branch code', 'text', true, ['validation' => ['pattern' => 'digits']]),
                self::f('letter', 'Supplier letter on letterhead and bank confirmation', 'attachment', true),
                self::f('called_by', 'Verified by phone by', 'user', true), self::f('called_number', 'Number called (from our records, not the letter)', 'phone', true),
            ]),
            self::form('customer_credit_application', 'Customer credit application', 'Finance', 'Open a credit account for a business customer.', [
                self::f('company', 'Registered name', 'text', true), self::f('registration', 'Registration number', 'text', true),
                self::f('vat', 'VAT number', 'text', false, ['validation' => ['pattern' => 'sa_vat_number']]),
                self::f('contact', 'Accounts contact', 'text', true), self::f('email', 'Accounts email', 'email', true),
                self::f('limit', 'Credit limit requested', 'currency', true), self::f('terms', 'Terms', 'select', true, ['options' => ['7 days', '30 days', '60 days']]),
                self::f('documents', 'CIPC documents, bank letter and trade references', 'attachment', true),
                self::f('consent', 'The customer consents to a credit check', 'acknowledgement', true),
            ]),
            self::form('credit_note_request', 'Credit note request', 'Finance', 'Credit a customer for a return, overcharge or service failure.', [
                self::f('customer', 'Customer', 'text', true), self::f('invoice', 'Invoice number', 'text', true),
                self::f('reason', 'Reason', 'select', true, ['options' => ['Goods returned', 'Overcharged', 'Service not delivered', 'Goodwill', 'Other']]),
                self::f('amount', 'Credit amount', 'currency', true, ['validation' => ['min' => 0.01]]), self::f('explanation', 'Explanation', 'textarea', true),
            ]),
            self::form('debt_write_off', 'Bad debt write-off', 'Finance', 'Write off a debt that cannot be recovered.', [
                self::f('customer', 'Customer', 'text', true), self::f('amount', 'Amount', 'currency', true), self::f('age', 'Days overdue', 'number', true),
                self::f('steps', 'Collection steps taken', 'textarea', true), self::f('evidence', 'Letters of demand / attorney report', 'attachment', true),
            ]),
            self::form('refund_request', 'Customer refund', 'Finance', 'Refund money a customer paid.', [
                self::f('customer', 'Customer', 'text', true), self::f('amount', 'Refund amount', 'currency', true),
                self::f('method', 'Refund to', 'radio', true, ['options' => ['Original card', 'Bank account', 'Account credit']]),
                self::f('bank_details', 'Customer bank confirmation', 'attachment', false, self::when('method', 'eq', 'Bank account')),
                self::f('reason', 'Reason', 'textarea', true),
            ]),
            self::form('capex_request', 'Capital expenditure request', 'Finance', 'A business case for buying a long-term asset.', [
                self::f('asset', 'Asset', 'text', true), self::f('department', 'Department', 'department', true),
                self::f('cost', 'Total cost', 'currency', true), self::f('budgeted', 'In the approved budget', 'yesno', true),
                self::f('benefit', 'Business case and payback', 'textarea', true), self::f('useful_life', 'Useful life (years)', 'number', true),
                self::f('quotes', 'Quotes', 'attachment', true),
            ]),
            self::form('invoice_dispute', 'Supplier invoice dispute', 'Finance', 'Query a supplier invoice before it is paid.', [
                self::f('supplier', 'Supplier', 'supplier', true), self::f('invoice', 'Invoice number', 'text', true), self::f('amount', 'Disputed amount', 'currency', true),
                self::f('issue', 'Issue', 'select', true, ['options' => ['Price differs from order', 'Quantity not received', 'Duplicate', 'Not ordered', 'Other']]),
                self::f('details', 'Details', 'textarea', true),
            ]),

            // ── Procurement ──────────────────────────────────────────────────────────
            self::form('request_for_quotation', 'Request for quotation', 'Procurement', 'What is needed, sent to suppliers for quotes.', [
                self::f('title', 'Title', 'text', true), self::f('lines', 'Items', 'line_items', true, ['min_rows' => 1, 'columns' => [
                    self::f('description', 'Description', 'text', true), self::f('quantity', 'Quantity', 'decimal', true), self::f('unit', 'Unit', 'text')]]),
                self::f('closing', 'Quotes close on', 'date', true), self::f('delivery', 'Delivery address', 'textarea', true),
                self::f('suppliers', 'Suppliers to invite', 'textarea'), self::f('specification', 'Specification', 'attachment'),
            ]),
            self::form('sole_source_justification', 'Sole-source justification', 'Procurement', 'Why only one supplier can be used instead of three quotes.', [
                self::f('supplier', 'Supplier', 'supplier', true), self::f('value', 'Value', 'currency', true),
                self::f('reason', 'Reason', 'select', true, ['options' => ['Only supplier / OEM', 'Emergency', 'Compatibility with existing', 'Specialist skill', 'Other']]),
                self::f('explanation', 'Explanation', 'textarea', true), self::f('market', 'How the market was checked', 'textarea', true),
            ]),
            self::form('supplier_evaluation', 'Supplier performance evaluation', 'Procurement', 'Score a supplier on quality, delivery, price and service.', [
                self::f('supplier', 'Supplier', 'supplier', true), self::f('period', 'Period evaluated', 'daterange', true),
                self::f('quality', 'Quality (1–5)', 'radio', true, ['options' => ['1', '2', '3', '4', '5']]),
                self::f('delivery', 'On-time delivery (1–5)', 'radio', true, ['options' => ['1', '2', '3', '4', '5']]),
                self::f('price', 'Price (1–5)', 'radio', true, ['options' => ['1', '2', '3', '4', '5']]),
                self::f('service', 'Service (1–5)', 'radio', true, ['options' => ['1', '2', '3', '4', '5']]),
                self::f('comments', 'Comments', 'textarea'), self::f('keep', 'Keep using this supplier', 'yesno', true),
            ]),
            self::form('goods_received_discrepancy', 'Goods received discrepancy', 'Procurement', 'Delivery short, damaged or wrong against the order.', [
                self::f('supplier', 'Supplier', 'supplier', true), self::f('order', 'Order number', 'text', true), self::f('delivery_note', 'Delivery note number', 'text', true),
                self::f('lines', 'Items affected', 'line_items', true, ['min_rows' => 1, 'columns' => [
                    self::f('item', 'Item', 'text', true), self::f('ordered', 'Ordered', 'decimal', true), self::f('received', 'Received', 'decimal', true),
                    self::f('issue', 'Issue', 'select', true, ['options' => ['Short', 'Damaged', 'Wrong item', 'Expired']])]]),
                self::f('photos', 'Photos', 'attachment'),
            ]),

            // ── IT ───────────────────────────────────────────────────────────────────
            self::form('software_request', 'Software request', 'IT', 'Ask for software or a licence to be installed.', [
                self::f('software', 'Software', 'text', true), self::f('licence', 'Licence', 'radio', true, ['options' => ['Free', 'Once-off purchase', 'Subscription']]),
                self::f('cost', 'Cost', 'currency', false, ['visible_if' => ['field' => 'licence', 'operator' => 'neq', 'value' => 'Free']]),
                self::f('reason', 'Business need', 'textarea', true), self::f('data', 'Does it store company or personal data?', 'yesno', true),
            ]),
            self::form('it_fault_report', 'IT fault report', 'IT', 'Report something broken: hardware, network, email or a system.', [
                self::f('area', 'Area', 'select', true, ['options' => ['Laptop / PC', 'Printer', 'Network / Wi-Fi', 'Email', 'Phone', 'Business system', 'Other']]),
                self::f('impact', 'Impact', 'radio', true, ['options' => ['Just me', 'My team', 'Whole site']]),
                self::f('description', 'What is wrong', 'textarea', true), self::f('asset_tag', 'Asset tag', 'text'), self::f('screenshot', 'Screenshot', 'attachment'),
            ]),
            self::form('lost_device', 'Lost or stolen device', 'IT', 'Report a lost device so it can be locked and wiped.', [
                self::f('device', 'Device', 'select', true, ['options' => ['Laptop', 'Phone', 'Tablet', 'Access card', 'Other']]),
                self::f('asset_tag', 'Asset tag or serial', 'text'), self::f('when', 'When', 'date', true), self::f('where', 'Where', 'text', true),
                self::f('stolen', 'Stolen', 'yesno', true), self::f('case_number', 'SAPS case number', 'text', false, self::when('stolen', 'true')),
                self::f('data', 'Company data on the device', 'textarea'),
            ]),

            // ── Compliance / Legal ───────────────────────────────────────────────────
            self::form('conflict_of_interest', 'Conflict of interest declaration', 'Legal', 'Declare an interest that could affect your decisions at work.', [
                self::f('kind', 'Nature of the interest', 'select', true, ['options' => ['Shareholding / directorship', 'Family member at a supplier or customer', 'Outside employment', 'Other']]),
                self::f('party', 'Company or person involved', 'text', true), self::f('details', 'Details', 'textarea', true),
                self::f('declare', 'The information is complete and I will update it if it changes', 'acknowledgement', true),
            ]),
            self::form('gifts_and_hospitality', 'Gifts and hospitality declaration', 'Legal', 'Declare a gift or hospitality offered by a business contact.', [
                self::f('from', 'Offered by', 'text', true), self::f('description', 'Gift or hospitality', 'text', true), self::f('value', 'Estimated value', 'currency', true),
                self::f('date', 'Date', 'date', true), self::f('accepted', 'Accepted', 'yesno', true), self::f('pending_deal', 'Is a tender or deal with them in progress?', 'yesno', true),
            ]),
            self::form('popia_request', 'POPIA data subject request', 'Legal', 'A person asks to see, correct or delete their personal information.', [
                self::f('requester_name', 'Data subject name', 'text', true), self::f('email', 'Email', 'email', true),
                self::f('request', 'Request', 'radio', true, ['options' => ['Access my information', 'Correct my information', 'Delete my information', 'Object to processing']]),
                self::f('details', 'Details', 'textarea', true), self::f('id_copy', 'Proof of identity', 'attachment', true),
                self::f('received', 'Date received', 'date', true, ['help' => 'Respond within a reasonable time (PAIA allows 30 days).']),
            ]),
            self::form('whistleblowing', 'Whistleblowing report', 'Legal', 'Report suspected fraud, corruption or misconduct; protected under the Protected Disclosures Act.', [
                self::f('kind', 'Concern', 'select', true, ['options' => ['Fraud / theft', 'Corruption / bribery', 'Safety', 'Harassment', 'Breach of law', 'Other']]),
                self::f('details', 'What happened', 'textarea', true), self::f('who', 'Who is involved', 'textarea'), self::f('when', 'When', 'date'),
                self::f('evidence', 'Evidence', 'attachment'), self::f('anonymous', 'Keep my identity confidential', 'yesno', true),
            ]),
            self::form('nda_request', 'Non-disclosure agreement request', 'Legal', 'Ask Legal for an NDA before sharing confidential information.', [
                self::f('party', 'Other party', 'text', true), self::f('mutual', 'Mutual (both sides share)', 'yesno', true),
                self::f('purpose', 'Purpose of sharing', 'textarea', true), self::f('contact', 'Their signatory and email', 'text', true),
            ]),
            self::form('customer_kyc', 'Customer onboarding (FICA / KYC)', 'Sales', 'Identify and verify a new customer.', [
                self::f('kind', 'Customer type', 'radio', true, ['options' => ['Individual', 'Company', 'Trust']]),
                self::f('name', 'Full or registered name', 'text', true),
                self::f('id_number', 'ID number', 'text', false, ['validation' => ['pattern' => 'sa_id_number'], 'sensitive' => true] + self::when('kind', 'eq', 'Individual')),
                self::f('registration', 'Registration number', 'text', false, self::when('kind', 'neq', 'Individual')),
                self::f('address', 'Physical address', 'textarea', true), self::f('email', 'Email', 'email', true), self::f('phone', 'Phone', 'phone', true),
                self::f('documents', 'ID / CIPC documents and proof of address', 'attachment', true),
                self::f('pep', 'Is the customer a politically exposed person?', 'yesno', true),
            ]),
            self::form('customer_complaint', 'Customer complaint', 'Sales', 'Log a customer complaint and what was promised to resolve it.', [
                self::f('customer', 'Customer', 'text', true), self::f('contact', 'Contact details', 'text', true),
                self::f('channel', 'Received by', 'select', true, ['options' => ['Phone', 'Email', 'In person', 'Social media', 'Website']]),
                self::f('category', 'Category', 'select', true, ['options' => ['Product quality', 'Delivery', 'Billing', 'Staff conduct', 'Other']]),
                self::f('details', 'Complaint', 'textarea', true), self::f('resolution', 'Resolution promised', 'textarea'), self::f('evidence', 'Evidence', 'attachment'),
            ]),

            // ── SHEQ ─────────────────────────────────────────────────────────────────
            self::form('near_miss', 'Near-miss report', 'SHEQ', 'Something that nearly caused harm, reported so it can be prevented.', [
                self::f('when', 'Date', 'date', true), self::f('where', 'Location', 'text', true), self::f('what', 'What happened', 'textarea', true),
                self::f('potential', 'What could have happened', 'select', true, ['options' => ['First aid', 'Medical treatment', 'Lost time injury', 'Fatality', 'Property damage', 'Environmental']]),
                self::f('suggestion', 'How to prevent it', 'textarea'), self::f('photos', 'Photos', 'attachment'),
            ]),
            self::form('hazard_report', 'Hazard report', 'SHEQ', 'A hazard spotted at work.', [
                self::f('where', 'Location', 'text', true),
                self::f('kind', 'Hazard', 'select', true, ['options' => ['Slip / trip', 'Electrical', 'Fire', 'Chemical', 'Machinery', 'Working at height', 'Ergonomic', 'Other']]),
                self::f('risk', 'Risk', 'radio', true, ['options' => ['Low', 'Medium', 'High']]), self::f('description', 'Description', 'textarea', true),
                self::f('made_safe', 'Made safe immediately', 'yesno', true), self::f('photos', 'Photos', 'attachment'),
            ]),
            self::form('permit_to_work', 'Permit to work', 'SHEQ', 'Hot work, confined space, height or electrical isolation, with precautions.', [
                self::f('kind', 'Work type', 'select', true, ['options' => ['Hot work', 'Confined space', 'Working at height', 'Electrical isolation', 'Excavation']]),
                self::f('location', 'Location', 'text', true), self::f('window', 'Valid', 'daterange', true),
                self::f('workers', 'People doing the work', 'users', true), self::f('contractor', 'Contractor company', 'text'),
                self::f('precautions', 'Precautions in place', 'checkboxes', true, ['options' => ['Area barricaded', 'Fire extinguisher on hand', 'Gas tested', 'Isolation locked out', 'Harness and anchor', 'Standby person']]),
                self::f('risk_assessment', 'Risk assessment', 'attachment', true),
                self::f('briefed', 'All workers were briefed on the hazards and precautions', 'acknowledgement', true),
            ]),
            self::form('toolbox_talk', 'Toolbox talk record', 'SHEQ', 'Topic and attendance of a safety talk.', [
                self::f('topic', 'Topic', 'text', true), self::f('date', 'Date', 'date', true), self::f('presenter', 'Presented by', 'user', true),
                self::f('attendees', 'Attendees', 'users', true), self::f('notes', 'Key points', 'textarea'), self::f('register', 'Signed register', 'attachment'),
            ]),
            self::form('ppe_issue', 'PPE request', 'SHEQ', 'Request personal protective equipment.', [
                self::f('items', 'Items', 'line_items', true, ['min_rows' => 1, 'columns' => [
                    self::f('item', 'Item', 'select', true, ['options' => ['Safety boots', 'Hard hat', 'Gloves', 'Goggles', 'Ear protection', 'Reflective vest', 'Overall', 'Respirator']]),
                    self::f('size', 'Size', 'text'), self::f('quantity', 'Quantity', 'number', true)]]),
                self::f('reason', 'Reason', 'radio', true, ['options' => ['New starter', 'Worn out', 'Lost', 'Damaged']]),
            ]),
            self::form('vehicle_inspection', 'Vehicle pre-trip inspection', 'Operations', 'Daily vehicle checklist before driving.', [
                self::f('vehicle', 'Registration', 'text', true), self::f('odometer', 'Odometer', 'number', true),
                self::f('checks', 'Checks passed', 'checkboxes', true, ['options' => ['Tyres', 'Lights', 'Brakes', 'Oil and water', 'Mirrors', 'Licence disc valid', 'Fire extinguisher', 'First aid kit']]),
                self::f('defects', 'Defects found', 'textarea'), self::f('fit', 'Vehicle is fit to drive', 'yesno', true),
            ]),

            // ── Facilities ───────────────────────────────────────────────────────────
            self::form('access_card_request', 'Access card or key request', 'Operations', 'Building access for a new person or a changed role.', [
                self::f('person', 'Person', 'user', true), self::f('kind', 'Needed', 'checkboxes', true, ['options' => ['Access card', 'Office key', 'Parking', 'Alarm code']]),
                self::f('areas', 'Areas', 'text', true), self::f('until', 'Access until', 'date'),
            ]),
            self::form('visitor_registration', 'Visitor pre-registration', 'Operations', 'Let reception know a visitor is coming.', [
                self::f('visitor', 'Visitor name', 'text', true), self::f('company', 'Company', 'text'), self::f('date', 'Date', 'date', true), self::f('time', 'Arrival', 'time', true),
                self::f('host', 'Host', 'user', true), self::f('vehicle', 'Vehicle registration', 'text'), self::f('induction', 'Needs a site safety induction', 'yesno'),
            ]),
        ];

        return collect($forms)->keyBy('key')->all();
    }

    private static function form(string $key, string $name, string $category, string $description, array $fields): array
    {
        return compact('key', 'name', 'category', 'description', 'fields') + ['source' => 'library'];
    }

    private static function f(string $key, string $label, string $type, bool $required = false, array $extra = []): array
    {
        return array_merge(compact('key', 'label', 'type', 'required'), $extra);
    }

    /** Show a field only when another has a value (and require it then, when the field is required). */
    private static function when(string $field, string $operator, mixed $value = null): array
    {
        $c = array_filter(['field' => $field, 'operator' => $operator, 'value' => $value], fn ($v) => $v !== null);

        return ['visible_if' => $c];
    }
}
