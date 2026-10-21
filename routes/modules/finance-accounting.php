<?php

use App\Modules\Finance\Accounting\Http\Controllers\AccountingController;
use App\Modules\Finance\Accounting\Http\Controllers\BankingController;
use App\Modules\Finance\Accounting\Http\Controllers\BankFeedController;
use App\Modules\Finance\Accounting\Http\Controllers\ConsolidationController;
use App\Modules\Finance\Accounting\Http\Controllers\BudgetController;
use App\Modules\Finance\Accounting\Http\Controllers\FixedAssetController;
use App\Modules\Finance\Accounting\Http\Controllers\ExpenseController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceAnalyticsController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceDashboardController;
use App\Modules\Finance\Accounting\Http\Controllers\InventoryController;
use App\Modules\Finance\Accounting\Http\Controllers\WarehouseController;
use App\Modules\Finance\Accounting\Http\Controllers\ReportBuilderController;
use App\Modules\Finance\Accounting\Http\Controllers\TaxController;
use App\Modules\Finance\Accounting\Http\Controllers\ContractController;
use App\Modules\Finance\Accounting\Http\Controllers\FiscalController;
use App\Modules\Finance\Accounting\Http\Controllers\DebtorsController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceArApController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceDocumentController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceItemController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceQuoteController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceRecurringController;
use App\Modules\Finance\Accounting\Http\Controllers\FinEntityController;
use App\Modules\Finance\Accounting\Http\Controllers\FinanceSettingsController;
use App\Modules\Finance\Accounting\Http\Controllers\PaymentBatchController;
use App\Modules\Finance\Accounting\Http\Controllers\PriceListController;
use App\Modules\Finance\Accounting\Http\Controllers\SalesOrderController;
use App\Modules\Finance\Accounting\Http\Controllers\StatementsController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

/**
 * Finance — General Ledger / Accounting engine (Phase 1).
 */
/*
 * `finance.group-view` sits on the whole module rather than on a list of report routes.
 *
 * It does nothing unless a GET asks for it by name (`?all_businesses=1`), so carrying it
 * everywhere costs a boolean per request — and the alternative is a list of "which screens are
 * reports", which would be wrong the first time somebody adds one.
 */
Route::middleware(['module.active:accounting', 'module.tier:accounting', 'finance.group-view'])->prefix('finance/accounting')->name('finance.accounting.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['sales-hub' => 'sales', 'purchases-hub' => 'purchases', 'ledger-hub' => 'ledger', 'reporting-hub' => 'reporting'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'accounting')->defaults('group', $group)->name('hub.' . $group);
    }
    // Live finance dashboard (Phase 6.1)
    Route::get('/dashboard',                        [FinanceDashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/layout',                [FinanceDashboardController::class, 'saveLayout'])->name('dashboard.layout');

    // Journals
    Route::get('/journals',                         [AccountingController::class, 'journals'])->name('journals');
    Route::post('/journals',                        [AccountingController::class, 'storeJournal'])->name('journals.store');
    Route::post('/journals/{journal}/reverse',      [AccountingController::class, 'reverse'])->name('journals.reverse');

    // Chart of accounts
    Route::get('/accounts',                         [AccountingController::class, 'accounts'])->name('accounts');
    Route::post('/accounts',                        [AccountingController::class, 'storeAccount'])->name('accounts.store');
    Route::patch('/accounts/{account}',             [AccountingController::class, 'updateAccount'])->name('accounts.update');
    Route::post('/accounts/import',                 [AccountingController::class, 'importAccounts'])->name('accounts.import');
    Route::get('/accounts/template',                [AccountingController::class, 'accountsTemplate'])->name('accounts.template');

    // Reports (Trial Balance, Balance Sheet, Income Statement, Cash Flow)
    Route::get('/reports',                          [AccountingController::class, 'reportsPage'])->name('reports');
    Route::get('/statements',                       [StatementsController::class, 'index'])->name('statements');
    Route::get('/statements/export',                [StatementsController::class, 'export'])->name('statements.export');

    // General Ledger — by account (drill-down passes ?account_id)
    Route::get('/ledger',                           [AccountingController::class, 'accountLedger'])->name('ledger');

    // Fiscal periods & financial years (dynamic per-tenant calendars, SoD-gated).
    Route::get('/periods',                          [FiscalController::class, 'index'])->name('periods');
    Route::patch('/periods/{period}',               [FiscalController::class, 'updatePeriod'])->name('periods.update');
    Route::get('/periods/{period}/checklist',       [FiscalController::class, 'checklist'])->name('periods.checklist');
    Route::post('/periods/{period}/checklist',      [FiscalController::class, 'toggleCheck'])->name('periods.checklist.toggle');
    Route::get('/periods/{period}/history',         [FiscalController::class, 'history'])->name('periods.history');
    Route::post('/fiscal-years',                    [FiscalController::class, 'createYear'])->name('fiscal-years.create');
    Route::patch('/fiscal-years/{year}',            [FiscalController::class, 'updateYear'])->name('fiscal-years.update');
    Route::post('/fiscal-years/{year}/switch',      [FiscalController::class, 'switchYear'])->name('fiscal-years.switch');
    Route::post('/fiscal-years/{year}/archive',     [FiscalController::class, 'archiveYear'])->name('fiscal-years.archive');
    Route::get('/fiscal-years/{year}/year-end/steps', [FiscalController::class, 'yearEndSteps'])->name('fiscal-years.year-end.steps');
    Route::post('/fiscal-years/{year}/year-end',    [FiscalController::class, 'yearEndClose'])->name('fiscal-years.year-end');
    Route::post('/fiscal-settings/start-month',     [FiscalController::class, 'saveFyStart'])->name('fiscal-settings.start-month');
    // Legacy alias (old UI called this) — kept so nothing 404s mid-deploy.
    Route::post('/year-end-close',                  [AccountingController::class, 'yearEndClose'])->name('year-end-close');

    // Exchange rates (foreign-currency posting)
    Route::get('/exchange-rates',                   [AccountingController::class, 'exchangeRates'])->name('exchange-rates');
    Route::post('/exchange-rates',                  [AccountingController::class, 'storeExchangeRate'])->name('exchange-rates.store');

    // Posting limits (resource-level RBAC for manual journals)
    Route::get('/posting-limits',                   [AccountingController::class, 'postingLimits'])->name('posting-limits');
    Route::post('/posting-limits',                  [AccountingController::class, 'storePostingLimit'])->name('posting-limits.store');

    /*
     * The businesses this tenant keeps books for.
     *
     * One tenant, several trading names: Mkhaya MK, Mkhaya Move, Mkhaya Hub. Choosing one here is
     * what every other screen in the module then reads — customers, suppliers, chart of accounts,
     * letterhead. The choice is remembered against the user, so it holds until they change it.
     */
    Route::get('/entities',                         [FinEntityController::class, 'index'])->name('entities');
    Route::post('/entities',                        [FinEntityController::class, 'store'])->name('entities.store');
    Route::patch('/entities/{entity}',              [FinEntityController::class, 'update'])->name('entities.update');
    Route::post('/entities/{entity}/switch',        [FinEntityController::class, 'switch'])->name('entities.switch');

    // Company / document settings (letterhead, banking, message on documents)
    Route::get('/settings',                         [FinanceSettingsController::class, 'index'])->name('settings');
    Route::post('/settings',                        [FinanceSettingsController::class, 'save'])->name('settings.save');

    // SARS Compliance — tax configuration + submission history (foundation)
    Route::get('/sars/config',                      [\App\Modules\Finance\Sars\Http\Controllers\SarsConfigController::class, 'index'])->name('sars.config');
    Route::post('/sars/config',                     [\App\Modules\Finance\Sars\Http\Controllers\SarsConfigController::class, 'save'])->name('sars.config.save');
    Route::get('/sars/vat201',                      [\App\Modules\Finance\Sars\Http\Controllers\SarsVat201Controller::class, 'index'])->name('sars.vat201');
    Route::post('/sars/vat201/generate',            [\App\Modules\Finance\Sars\Http\Controllers\SarsVat201Controller::class, 'generate'])->name('sars.vat201.generate');
    Route::get('/sars/submissions',                 [\App\Modules\Finance\Sars\Http\Controllers\SarsSubmissionController::class, 'index'])->name('sars.submissions');
    Route::get('/sars/submissions/{submission}',    [\App\Modules\Finance\Sars\Http\Controllers\SarsSubmissionController::class, 'show'])->name('sars.submissions.show');
    Route::post('/sars/submissions/{submission}/status', [\App\Modules\Finance\Sars\Http\Controllers\SarsSubmissionController::class, 'setStatus'])->name('sars.submissions.status');
    Route::get('/sars/submissions/{submission}/download', [\App\Modules\Finance\Sars\Http\Controllers\SarsSubmissionController::class, 'download'])->name('sars.submissions.download');

    // Data migration — import from Sage / Xero / Zoho
    Route::get('/import',                           [\App\Modules\Finance\Accounting\Http\Controllers\AccountingImportController::class, 'index'])->name('import');
    Route::post('/import/preview',                  [\App\Modules\Finance\Accounting\Http\Controllers\AccountingImportController::class, 'preview'])->name('import.preview');
    Route::post('/import/commit',                   [\App\Modules\Finance\Accounting\Http\Controllers\AccountingImportController::class, 'commit'])->name('import.commit');

    // Customizable invoice/quote templates
    Route::get('/templates',                        [\App\Modules\Finance\Accounting\Http\Controllers\FinTemplateController::class, 'index'])->name('templates');
    Route::post('/templates',                       [\App\Modules\Finance\Accounting\Http\Controllers\FinTemplateController::class, 'store'])->name('templates.store');
    Route::patch('/templates/{template}',           [\App\Modules\Finance\Accounting\Http\Controllers\FinTemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{template}',          [\App\Modules\Finance\Accounting\Http\Controllers\FinTemplateController::class, 'destroy'])->name('templates.destroy');
    Route::post('/templates/{template}/default',    [\App\Modules\Finance\Accounting\Http\Controllers\FinTemplateController::class, 'setDefault'])->name('templates.default');
    // Sample document rendered with the template (?doc=invoice|statement) — read-only, creates nothing.
    Route::get('/templates/{template}/preview',     [\App\Modules\Finance\Accounting\Http\Controllers\FinTemplateController::class, 'preview'])->name('templates.preview');

    // ── Accounts Receivable / Payable ───────────────────────────────────────
    Route::get('/customers',                        [FinanceArApController::class, 'customers'])->name('customers');
    Route::get('/suppliers',                        [FinanceArApController::class, 'suppliers'])->name('suppliers');
    Route::post('/parties/sync',                    [FinanceArApController::class, 'syncParties'])->name('parties.sync');
    Route::post('/parties/merge',                   [FinanceArApController::class, 'mergeParties'])->name('parties.merge');
    Route::post('/parties',                         [FinanceArApController::class, 'storeParty'])->name('parties.store');
    Route::patch('/parties/{party}',                [FinanceArApController::class, 'updateParty'])->name('parties.update');
    Route::get('/parties/{party}/statement',        [FinanceArApController::class, 'statement'])->name('parties.statement');
    Route::post('/parties/{party}/verify',          \App\Modules\Finance\Accounting\Http\Controllers\PartyVerificationController::class)->name('parties.verify');

    Route::get('/receivables',                      [FinanceArApController::class, 'receivables'])->name('receivables');
    Route::get('/payables',                         [FinanceArApController::class, 'payables'])->name('payables');
    Route::post('/invoices',                        [FinanceArApController::class, 'storeInvoice'])->name('invoices.store');
    // Amend an invoice that has not been settled: the ledger entry is reversed and re-posted, so
    // this is a PATCH on the document rather than a correction note.
    Route::patch('/invoices/{invoice}',              [FinanceArApController::class, 'updateInvoice'])->name('invoices.update');
    Route::post('/invoices/{invoice}/void',         [FinanceArApController::class, 'voidInvoice'])->name('invoices.void');
    Route::post('/invoices/{invoice}/credit-note',  [FinanceArApController::class, 'storeCreditNote'])->name('invoices.credit-note');
    Route::post('/invoices/{invoice}/write-off',    [FinanceArApController::class, 'writeOffInvoice'])->name('invoices.write-off');
    Route::post('/invoices/{invoice}/debit-note',   [FinanceArApController::class, 'storeDebitNote'])->name('invoices.debit-note');
    Route::post('/payments',                        [FinanceArApController::class, 'storePayment'])->name('payments.store');

    // Payment batches (AP pay-run — maker-checker + bank file export)
    Route::get('/payment-batches',                  [PaymentBatchController::class, 'index'])->name('payment-batches');
    Route::post('/payment-batches',                 [PaymentBatchController::class, 'store'])->name('payment-batches.store');
    Route::post('/payment-batches/{batch}/approve', [PaymentBatchController::class, 'approve'])->name('payment-batches.approve');
    Route::post('/payment-batches/{batch}/reject',  [PaymentBatchController::class, 'reject'])->name('payment-batches.reject');
    Route::post('/payment-batches/{batch}/pay',     [PaymentBatchController::class, 'pay'])->name('payment-batches.pay');
    Route::get('/payment-batches/{batch}/pain001',  [PaymentBatchController::class, 'pain001'])->name('payment-batches.pain001');
    Route::get('/payment-batches/{batch}/csv',      [PaymentBatchController::class, 'csv'])->name('payment-batches.csv');

    // Price lists
    Route::get('/price-lists',                       [PriceListController::class, 'index'])->name('price-lists');
    Route::post('/price-lists',                      [PriceListController::class, 'store'])->name('price-lists.store');
    Route::patch('/price-lists/{priceList}',         [PriceListController::class, 'update'])->name('price-lists.update');
    Route::get('/price-lists/price',                 [PriceListController::class, 'price'])->name('price-lists.price');

    // Items (products & services master)
    Route::get('/items',                            [FinanceItemController::class, 'index'])->name('items');
    Route::post('/items',                           [FinanceItemController::class, 'store'])->name('items.store');
    Route::patch('/items/{item}',                   [FinanceItemController::class, 'update'])->name('items.update');

    // Recurring / subscription invoices
    Route::get('/recurring',                        [FinanceRecurringController::class, 'index'])->name('recurring');
    Route::post('/recurring',                       [FinanceRecurringController::class, 'store'])->name('recurring.store');
    Route::patch('/recurring/{recurring}',          [FinanceRecurringController::class, 'update'])->name('recurring.update');
    Route::post('/recurring/{recurring}/status',    [FinanceRecurringController::class, 'changeStatus'])->name('recurring.status');
    Route::post('/recurring/{recurring}/run',       [FinanceRecurringController::class, 'runNow'])->name('recurring.run');

    // Contracts (progress billing + retention)
    Route::get('/contracts',                        [ContractController::class, 'index'])->name('contracts');
    Route::post('/contracts',                       [ContractController::class, 'store'])->name('contracts.store');
    Route::patch('/contracts/{contract}',           [ContractController::class, 'update'])->name('contracts.update');
    Route::post('/contracts/{contract}/claim',      [ContractController::class, 'claim'])->name('contracts.claim');
    Route::post('/contracts/{contract}/release',    [ContractController::class, 'release'])->name('contracts.release');

    // Quotations
    Route::get('/quotes',                           [FinanceQuoteController::class, 'index'])->name('quotes');
    Route::post('/quotes',                          [FinanceQuoteController::class, 'store'])->name('quotes.store');
    Route::patch('/quotes/{quote}',                 [FinanceQuoteController::class, 'update'])->name('quotes.update');
    Route::post('/quotes/{quote}/status',           [FinanceQuoteController::class, 'changeStatus'])->name('quotes.status');
    Route::post('/quotes/{quote}/convert',          [FinanceQuoteController::class, 'convert'])->name('quotes.convert');
    Route::post('/quotes/{quote}/to-order',         [FinanceQuoteController::class, 'toOrder'])->name('quotes.to-order');

    // Sales orders + delivery notes
    Route::get('/sales-orders',                     [SalesOrderController::class, 'index'])->name('sales-orders');
    Route::post('/sales-orders',                    [SalesOrderController::class, 'store'])->name('sales-orders.store');
    Route::patch('/sales-orders/{order}',           [SalesOrderController::class, 'update'])->name('sales-orders.update');
    Route::post('/sales-orders/{order}/deliver',    [SalesOrderController::class, 'deliver'])->name('sales-orders.deliver');
    Route::post('/sales-orders/{order}/invoice',    [SalesOrderController::class, 'invoice'])->name('sales-orders.invoice');
    Route::post('/sales-orders/{order}/cancel',     [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel');
    Route::get('/delivery-notes/{deliveryNote}/pdf', [FinanceDocumentController::class, 'deliveryNotePdf'])->name('delivery-notes.pdf');
    Route::post('/delivery-notes/{deliveryNote}/invoice', [SalesOrderController::class, 'invoiceDelivery'])->name('delivery-notes.invoice');
    // Customer disputes: raised against an invoice (held from dunning), resolved with a credit note when money goes back.
    Route::get('/disputes',                         [\App\Modules\Finance\Accounting\Http\Controllers\DisputeController::class, 'index'])->name('disputes');
    Route::post('/disputes',                        [\App\Modules\Finance\Accounting\Http\Controllers\DisputeController::class, 'store'])->name('disputes.store');
    Route::post('/disputes/{dispute}/resolve',      [\App\Modules\Finance\Accounting\Http\Controllers\DisputeController::class, 'resolve'])->name('disputes.resolve');

    // Documents — PDF + email
    Route::get('/quotes/{quote}/pdf',               [FinanceDocumentController::class, 'quotePdf'])->name('quotes.pdf');
    Route::post('/quotes/{quote}/email',            [FinanceDocumentController::class, 'emailQuote'])->name('quotes.email');
    Route::get('/invoices/{invoice}/pdf',           [FinanceDocumentController::class, 'invoicePdf'])->name('invoices.pdf');
    Route::post('/invoices/{invoice}/email',        [FinanceDocumentController::class, 'emailInvoice'])->name('invoices.email');
    Route::post('/invoices/{invoice}/whatsapp',     [FinanceDocumentController::class, 'whatsappInvoice'])->name('invoices.whatsapp');
    Route::post('/quotes/{quote}/whatsapp',         [FinanceDocumentController::class, 'whatsappQuote'])->name('quotes.whatsapp');
    Route::get('/parties/{party}/statement/pdf',    [FinanceDocumentController::class, 'statementPdf'])->name('parties.statement.pdf');
    Route::post('/parties/{party}/statement/email', [FinanceDocumentController::class, 'emailStatement'])->name('parties.statement.email');

    // ── Banking & Cash Management (bank accounts, statement import, reconciliation) ──
    Route::get('/banking',                          [BankingController::class, 'index'])->name('banking');
    Route::post('/banking/accounts',                [BankingController::class, 'store'])->name('banking.accounts.store');
    Route::patch('/banking/accounts/{bankAccount}', [BankingController::class, 'update'])->name('banking.accounts.update');
    Route::post('/banking/transfer',                [BankingController::class, 'transfer'])->name('banking.transfer');
    Route::get('/cash-forecast',                    [BankingController::class, 'forecast'])->name('cash-forecast');

    // Fixed Assets (register, depreciation run, disposal)
    Route::get('/assets',                           [FixedAssetController::class, 'index'])->name('assets');
    Route::post('/assets',                          [FixedAssetController::class, 'store'])->name('assets.store');
    Route::post('/assets/depreciate',               [FixedAssetController::class, 'depreciate'])->name('assets.depreciate');
    Route::post('/assets/{asset}/dispose',          [FixedAssetController::class, 'dispose'])->name('assets.dispose');

    // Budgeting (budget vs actual variance)
    Route::get('/budgets',                          [BudgetController::class, 'index'])->name('budgets');
    Route::get('/budgets/create',                   [BudgetController::class, 'create'])->name('budgets.create');
    Route::get('/budgets/{budget}/edit',            [BudgetController::class, 'edit'])->name('budgets.edit');
    Route::post('/budgets',                         [BudgetController::class, 'store'])->name('budgets.store');
    Route::patch('/budgets/{budget}',               [BudgetController::class, 'update'])->name('budgets.update');
    Route::post('/budgets/{budget}/status',         [BudgetController::class, 'status'])->name('budgets.status');

    // Tax management (VAT201 return + tax registry)
    Route::get('/tax',                              [TaxController::class, 'index'])->name('tax');
    Route::post('/tax/codes',                       [TaxController::class, 'storeCode'])->name('tax.codes.store');
    Route::patch('/tax/codes/{taxCode}',            [TaxController::class, 'updateCode'])->name('tax.codes.update');

    // Inventory accounting (costed stock, receipts/issues, valuation)
    Route::get('/inventory',                        [InventoryController::class, 'index'])->name('inventory');
    Route::post('/inventory',                       [InventoryController::class, 'store'])->name('inventory.store');
    Route::post('/inventory/{item}/receive',        [InventoryController::class, 'receive'])->name('inventory.receive');
    Route::post('/inventory/{item}/issue',          [InventoryController::class, 'issue'])->name('inventory.issue');
    Route::post('/inventory/{item}/adjust',         [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::post('/inventory/{item}/transfer',       [InventoryController::class, 'transfer'])->name('inventory.transfer');
    // Warehouses / stock locations
    Route::get('/warehouses',                       [WarehouseController::class, 'index'])->name('warehouses');
    Route::post('/warehouses',                      [WarehouseController::class, 'store'])->name('warehouses.store');
    Route::patch('/warehouses/{warehouse}',         [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::post('/warehouses/{warehouse}/default',  [WarehouseController::class, 'setDefault'])->name('warehouses.default');
    Route::delete('/warehouses/{warehouse}',        [WarehouseController::class, 'destroy'])->name('warehouses.destroy');

    // Expense management (capture → approve → post)
    Route::get('/expenses',                         [ExpenseController::class, 'index'])->name('expenses');
    Route::post('/expenses',                        [ExpenseController::class, 'store'])->name('expenses.store');
    Route::post('/expenses/{expense}/approve',      [ExpenseController::class, 'approve'])->name('expenses.approve');
    Route::post('/expenses/{expense}/reject',       [ExpenseController::class, 'reject'])->name('expenses.reject');

    // Custom report builder (Phase 6.3)
    Route::get('/analytics',                        [FinanceAnalyticsController::class, 'index'])->name('analytics');
    Route::get('/report-builder',                   [ReportBuilderController::class, 'index'])->name('report-builder');
    Route::post('/report-builder/run',              [ReportBuilderController::class, 'run'])->name('report-builder.run');
    Route::get('/report-builder/export',            [ReportBuilderController::class, 'export'])->name('report-builder.export');
    Route::post('/report-builder/save',             [ReportBuilderController::class, 'store'])->name('report-builder.save');
    Route::delete('/report-builder/{report}',       [ReportBuilderController::class, 'destroy'])->name('report-builder.destroy');

    // Group consolidation (Phase 6.4)
    Route::get('/consolidation',                    [ConsolidationController::class, 'index'])->name('consolidation');
    Route::post('/consolidation/groups',            [ConsolidationController::class, 'storeGroup'])->name('consolidation.groups.store');
    Route::patch('/consolidation/groups/{group}',   [ConsolidationController::class, 'updateGroup'])->name('consolidation.groups.update');
    Route::post('/consolidation/groups/{group}/members', [ConsolidationController::class, 'addMember'])->name('consolidation.members.add');
    Route::delete('/consolidation/members/{member}', [ConsolidationController::class, 'removeMember'])->name('consolidation.members.remove');
    Route::get('/banking/{bankAccount}/reconcile',  [BankingController::class, 'reconcile'])->name('banking.reconcile');
    Route::post('/banking/{bankAccount}/import',    [BankingController::class, 'importStatement'])->name('banking.import');
    Route::post('/banking/{bankAccount}/auto-match', [BankingController::class, 'autoMatch'])->name('banking.auto-match');
    Route::post('/banking/{bankAccount}/apply-rules', [BankingController::class, 'applyRules'])->name('banking.apply-rules');
    Route::post('/banking/{bankAccount}/match',     [BankingController::class, 'match'])->name('banking.match');
    Route::post('/banking/lines/{statementLine}/unmatch',  [BankingController::class, 'unmatchLine'])->name('banking.unmatch');
    Route::post('/banking/lines/{statementLine}/allocate', [BankingController::class, 'allocate'])->name('banking.allocate');
    // Bank feeds (auto-import)
    Route::get('/bank-feeds',                       [BankFeedController::class, 'index'])->name('bank-feeds');
    Route::post('/bank-feeds/connect',              [BankFeedController::class, 'connect'])->name('bank-feeds.connect');
    Route::post('/bank-feeds/{feed}/refresh',       [BankFeedController::class, 'refresh'])->name('bank-feeds.refresh');
    Route::delete('/bank-feeds/{feed}',             [BankFeedController::class, 'disconnect'])->name('bank-feeds.disconnect');
    // Yodlee: the tenant's own subscription, and the two calls the FastLink flow makes. The bank
    // login itself happens on Yodlee's hosted widget — those credentials never reach this app.
    Route::post('/bank-feeds/yodlee/link',          [BankFeedController::class, 'yodleeLink'])->name('bank-feeds.yodlee.link');
    Route::get('/bank-feeds/yodlee/accounts',       [BankFeedController::class, 'yodleeAccounts'])->name('bank-feeds.yodlee.accounts');

    Route::post('/banking/rules',                   [BankingController::class, 'storeRule'])->name('banking.rules.store');
    Route::patch('/banking/rules/{rule}',           [BankingController::class, 'updateRule'])->name('banking.rules.update');
    Route::delete('/banking/rules/{rule}',          [BankingController::class, 'destroyRule'])->name('banking.rules.destroy');

    // ── Debtors Manager (AR collections) ────────────────────────────────────
    Route::get('/debtors',                          [DebtorsController::class, 'index'])->name('debtors');
    Route::get('/debtors/export',                   [DebtorsController::class, 'export'])->name('debtors.export');
    Route::get('/debtors/customer/{party}',         [DebtorsController::class, 'customer'])->name('debtors.customer');
    Route::get('/debtors/{party}/statement',        [DebtorsController::class, 'statement'])->name('debtors.statement');
    Route::post('/debtors/{party}/statement/email', [DebtorsController::class, 'emailStatement'])->name('debtors.statement.email');
    Route::get('/debtors/{invoice}/timeline',       [DebtorsController::class, 'timeline'])->name('debtors.timeline');
    Route::post('/debtors/{invoice}/activity',      [DebtorsController::class, 'storeActivity'])->name('debtors.activity');
    Route::post('/debtors/{invoice}/reminder',      [DebtorsController::class, 'sendReminder'])->name('debtors.reminder');
    Route::post('/debtors/{invoice}/assign',        [DebtorsController::class, 'assign'])->name('debtors.assign');
    Route::post('/debtors/{invoice}/dispute',       [DebtorsController::class, 'dispute'])->name('debtors.dispute');
    Route::post('/debtors/{invoice}/payment',       [DebtorsController::class, 'recordPayment'])->name('debtors.payment');
    Route::post('/debtors/bulk-remind',             [DebtorsController::class, 'bulkRemind'])->name('debtors.bulk-remind');
    Route::post('/debtors/run-reminders',           [DebtorsController::class, 'runReminders'])->name('debtors.run-reminders');
    Route::post('/debtors/reasons',                 [DebtorsController::class, 'storeReason'])->name('debtors.reasons.store');
});
