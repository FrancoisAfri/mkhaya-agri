<?php

// Accounting (GL) — what the books say. Module code: accounting.
// The reference example for every other dataset file: see DatasetRegistry for the format.

$invoiceDims = [
    'party' => ['label' => 'Customer', 'sql' => "coalesce(p.name, 'Unknown')"],
    'party_category' => ['label' => 'Customer category', 'sql' => "coalesce(nullif(p.category, ''), 'Uncategorised')"],
    'status' => ['label' => 'Status', 'sql' => 'i.status'],
    'sales_rep' => ['label' => 'Sales rep', 'sql' => "coalesce(nullif(p.sales_rep, ''), 'Unassigned')"],
    'city' => ['label' => 'City', 'sql' => "coalesce(nullif(p.city, ''), 'Unknown')"],
    'overdue' => ['label' => 'Overdue?', 'sql' => "case when i.status <> 'paid' and i.due_date < current_date then 'Overdue' else 'Not overdue' end"],
];

$invoiceMeasures = [
    'revenue' => ['label' => 'Revenue (excl. VAT)', 'sql' => 'coalesce(sum(i.subtotal), 0)', 'format' => 'currency'],
    'invoiced' => ['label' => 'Invoiced (incl. VAT)', 'sql' => 'coalesce(sum(i.total), 0)', 'format' => 'currency'],
    'vat' => ['label' => 'VAT', 'sql' => 'coalesce(sum(i.tax_total), 0)', 'format' => 'currency'],
    'collected' => ['label' => 'Paid so far', 'sql' => 'coalesce(sum(i.amount_paid), 0)', 'format' => 'currency'],
    'outstanding' => ['label' => 'Outstanding', 'sql' => 'coalesce(sum(i.total - i.amount_paid), 0)', 'format' => 'currency'],
    'overdue_amount' => ['label' => 'Overdue', 'sql' => "coalesce(sum(case when i.status <> 'paid' and i.due_date < current_date then i.total - i.amount_paid else 0 end), 0)", 'format' => 'currency'],
    'documents' => ['label' => 'Invoices', 'sql' => 'count(*)', 'format' => 'number'],
    'parties' => ['label' => 'Customers', 'sql' => 'count(distinct i.party_id)', 'format' => 'number'],
    'avg_invoice' => ['label' => 'Average invoice', 'ratio' => ['revenue', 'documents'], 'format' => 'currency'],
    'collection_rate' => ['label' => 'Collection rate %', 'ratio' => ['collected', 'invoiced'], 'scale' => 100, 'format' => 'percent'],
];

$ledger = fn (string $type, string $label, string $sign) => [
    'module' => 'accounting',
    'label' => $label,
    'from' => 'fin_journal_lines l join fin_journals j on j.id = l.journal_id join fin_accounts a on a.id = l.account_id',
    'company' => 'l.company_id',
    'date' => 'j.journal_date',
    'where' => "a.type = '{$type}' and j.status = 'posted'",
    'measures' => [
        'amount' => ['label' => $label, 'sql' => "coalesce(sum({$sign}), 0)", 'format' => 'currency'],
        'lines' => ['label' => 'Ledger lines', 'sql' => 'count(*)', 'format' => 'number'],
    ],
    'dimensions' => [
        'account' => ['label' => 'Account', 'sql' => "a.code || ' ' || a.name"],
        'subtype' => ['label' => 'Account group', 'sql' => "coalesce(nullif(a.subtype, ''), 'Other')"],
        'source' => ['label' => 'Source', 'sql' => "coalesce(nullif(j.source_module, ''), 'Manual')"],
    ],
];

return [
    'fin_sales' => [
        'module' => 'accounting',
        'label' => 'Sales invoices',
        'from' => 'fin_invoices i left join fin_parties p on p.id = i.party_id',
        'company' => 'i.company_id',
        'date' => 'i.invoice_date',
        'where' => "i.direction = 'receivable' and i.doc_type = 'invoice' and i.status not in ('void', 'draft')",
        'measures' => $invoiceMeasures,
        'dimensions' => $invoiceDims,
    ],
    'fin_purchases' => [
        'module' => 'accounting',
        'label' => 'Supplier bills',
        'from' => 'fin_invoices i left join fin_parties p on p.id = i.party_id',
        'company' => 'i.company_id',
        'date' => 'i.invoice_date',
        'where' => "i.direction = 'payable' and i.doc_type = 'invoice' and i.status not in ('void', 'draft')",
        'measures' => array_replace($invoiceMeasures, [
            'revenue' => ['label' => 'Spend (excl. VAT)', 'sql' => 'coalesce(sum(i.subtotal), 0)', 'format' => 'currency'],
            'invoiced' => ['label' => 'Billed (incl. VAT)', 'sql' => 'coalesce(sum(i.total), 0)', 'format' => 'currency'],
            'documents' => ['label' => 'Bills', 'sql' => 'count(*)', 'format' => 'number'],
            'parties' => ['label' => 'Suppliers', 'sql' => 'count(distinct i.party_id)', 'format' => 'number'],
            'avg_invoice' => ['label' => 'Average bill', 'ratio' => ['revenue', 'documents'], 'format' => 'currency'],
            'collection_rate' => ['label' => 'Paid %', 'ratio' => ['collected', 'invoiced'], 'scale' => 100, 'format' => 'percent'],
        ]),
        'dimensions' => array_replace($invoiceDims, [
            'party' => ['label' => 'Supplier', 'sql' => "coalesce(p.name, 'Unknown')"],
            'party_category' => ['label' => 'Supplier category', 'sql' => "coalesce(nullif(p.category, ''), 'Uncategorised')"],
        ]),
    ],
    'fin_credit_notes' => [
        'module' => 'accounting',
        'label' => 'Credit notes',
        'from' => 'fin_invoices i left join fin_parties p on p.id = i.party_id',
        'company' => 'i.company_id',
        'date' => 'i.invoice_date',
        'where' => "i.doc_type = 'credit_note' and i.status not in ('void', 'draft')",
        'measures' => [
            'credited' => ['label' => 'Credited (incl. VAT)', 'sql' => 'coalesce(sum(i.total), 0)', 'format' => 'currency'],
            'documents' => ['label' => 'Credit notes', 'sql' => 'count(*)', 'format' => 'number'],
        ],
        'dimensions' => ['party' => $invoiceDims['party'], 'direction' => ['label' => 'Side', 'sql' => "case when i.direction = 'receivable' then 'Customer' else 'Supplier' end"]],
    ],
    'fin_cashflow' => [
        'module' => 'accounting',
        'label' => 'Cash in and out',
        'from' => 'fin_payments y left join fin_parties p on p.id = y.party_id left join fin_bank_accounts b on b.id = y.bank_account_id',
        'company' => 'y.company_id',
        'date' => 'y.payment_date',
        'measures' => [
            'cash_in' => ['label' => 'Cash received', 'sql' => "coalesce(sum(case when y.direction = 'receipt' then y.amount else 0 end), 0)", 'format' => 'currency'],
            'cash_out' => ['label' => 'Cash paid', 'sql' => "coalesce(sum(case when y.direction = 'payment' then y.amount else 0 end), 0)", 'format' => 'currency'],
            'net_cash' => ['label' => 'Net cash flow', 'sql' => "coalesce(sum(case when y.direction = 'receipt' then y.amount else -y.amount end), 0)", 'format' => 'currency'],
            'payments' => ['label' => 'Transactions', 'sql' => 'count(*)', 'format' => 'number'],
        ],
        'dimensions' => [
            'direction' => ['label' => 'In / out', 'sql' => "case when y.direction = 'receipt' then 'Received' else 'Paid' end"],
            'party' => ['label' => 'Customer / supplier', 'sql' => "coalesce(p.name, 'Unknown')"],
            'bank' => ['label' => 'Bank account', 'sql' => "coalesce(b.name, 'Unknown')"],
        ],
    ],
    'fin_income' => $ledger('income', 'Income', 'l.base_credit - l.base_debit'),
    'fin_expenses' => $ledger('expense', 'Expenses', 'l.base_debit - l.base_credit'),
];
