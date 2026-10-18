<?php

return [
    /*
    | The functional/base currency the ledger reports in. Foreign-currency
    | postings are converted to this via fin_exchange_rates. ZAR for SA SMEs.
    */
    'base_currency' => env('FINANCE_BASE_CURRENCY', 'ZAR'),

    /*
    | Account code that the year-end run posts net profit/(loss) into when it
    | closes the P&L. Must be a postable equity account in the chart.
    */
    'retained_earnings_account' => env('FINANCE_RETAINED_EARNINGS', '3100'),

    /*
    | Control accounts the AR/AP engine posts against. All must be postable.
    */
    'ar_control'  => env('FINANCE_AR_CONTROL', '1200'),  // Accounts Receivable
    'ap_control'  => env('FINANCE_AP_CONTROL', '2100'),  // Accounts Payable
    // Goods Received Not Invoiced: credited when a GRN posts stock, debited when the supplier's
    // bill for those goods is captured, so a delivery never owes the supplier twice.
    'grni_account' => env('FINANCE_GRNI_ACCOUNT', '2150'),
    'vat_output'  => env('FINANCE_VAT_OUTPUT', '2200'),  // VAT on sales (liability)
    'vat_input'   => env('FINANCE_VAT_INPUT', '1400'),   // VAT on purchases (asset)
    'default_bank' => env('FINANCE_DEFAULT_BANK', '1100'),
    'bad_debt_account' => env('FINANCE_BAD_DEBT', '6800'),   // expense account for write-offs
    'retention_account' => env('FINANCE_RETENTION', '1250'), // retention receivable (held back on progress claims)

    /*
    | Fixed-asset defaults (Phase 5.1). Each asset can override these; these are
    | the fallbacks the depreciation and disposal engine posts against.
    */
    'fixed_asset_account'          => env('FINANCE_FIXED_ASSET', '1510'), // cost account
    'accum_depreciation_account'   => env('FINANCE_ACCUM_DEP', '1520'),   // contra-asset
    'depreciation_expense_account' => env('FINANCE_DEP_EXPENSE', '6500'), // P&L expense
    'asset_disposal_account'       => env('FINANCE_DISPOSAL', '4900'),    // gain/loss on disposal

    // Inventory (Phase 5.2)
    'inventory_account' => env('FINANCE_INVENTORY', '1300'),
    'cogs_account'      => env('FINANCE_COGS', '5000'),

    // Manufacturing. Material issued to a production order is work in progress, not a cost of
    // sale; it becomes finished goods when the order completes. Variance and scrap carry the
    // difference between what an order should have cost and what it did.
    'wip_account'                 => env('FINANCE_WIP', '1310'),
    'production_variance_account' => env('FINANCE_PRODUCTION_VARIANCE', '5600'),
    'scrap_account'               => env('FINANCE_SCRAP', '5610'),

    // Expenses (5.5) & Staff loans (5.6)
    'mileage_rate'           => env('FINANCE_MILEAGE_RATE', 4.84),
    'staff_loan_receivable'  => env('FINANCE_STAFF_LOAN', '1270'),
];
