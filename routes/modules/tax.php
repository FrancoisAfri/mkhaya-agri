<?php

use App\Http\Controllers\ModuleHubController;
use App\Modules\Finance\Tax\Http\Controllers\TaxAdminController;
use App\Modules\Finance\Tax\Http\Controllers\TaxAuditController;
use App\Modules\Finance\Tax\Http\Controllers\TaxCalendarController;
use App\Modules\Finance\Tax\Http\Controllers\IncomeTaxController;
use App\Modules\Finance\Tax\Http\Controllers\TaxDashboardController;
use App\Modules\Finance\Tax\Http\Controllers\TaxEvidenceController;
use App\Modules\Finance\Tax\Http\Controllers\TaxImportController;
use App\Modules\Finance\Tax\Http\Controllers\TaxLiabilityController;
use App\Modules\Finance\Tax\Http\Controllers\TaxReturnController;
use App\Modules\Finance\Tax\Http\Controllers\TaxPeriodController;
use App\Modules\Finance\Tax\Http\Controllers\TaxReconciliationController;
use App\Modules\Finance\Tax\Http\Controllers\TaxReportController;
use App\Modules\Finance\Tax\Http\Controllers\TaxRegistrationController;
use App\Modules\Finance\Tax\Http\Controllers\TaxRuleController;
use App\Modules\Finance\Tax\Http\Controllers\TaxTransactionController;
use App\Modules\Finance\Tax\Support\TaxHubs;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Finance — Tax Management & Compliance
|--------------------------------------------------------------------------
|
| Every record parameter is constrained to a UUID, so a malformed address falls through to the app's
| fallback instead of reaching the database as an invalid UUID (a 500). Gated by module entitlement and hub tier; within that, every action checks its own tax.*
| permission in the controller. Tenant data is isolated by row-level security, and records bind on
| uuid so an internal id never appears in a URL (reconciliation items, which only exist inside a
| reconciliation the user can already open, are the exception).
*/

Route::middleware(['module.active:tax_management', 'module.tier:tax_management'])
    ->prefix('finance/tax')->name('tax.')
    ->group(function () {
        Route::get('/', TaxDashboardController::class)->name('dashboard');

        foreach (array_keys(TaxHubs::hubs()) as $group) {
            // HubAccess treats a user with no module-access row as READ, which opened the READ-tier
            // Compliance landing to people with no tax permission at all. The landings need tax.view.
            Route::get('/' . $group, [ModuleHubController::class, 'show'])
                ->defaults('module', 'tax_management')->defaults('group', $group)->name('hub.' . $group)
                ->middleware('can:' . \App\Modules\Finance\Tax\Support\TaxPermissions::VIEW);
        }

        Route::get('/registrations', [TaxRegistrationController::class, 'index'])->name('registrations.index');
        Route::post('/registrations', [TaxRegistrationController::class, 'store'])->name('registrations.store');
        Route::patch('/registrations/{registration}', [TaxRegistrationController::class, 'update'])->name('registrations.update')->whereUuid('registration');
        Route::post('/registrations/{registration}/confirm', [TaxRegistrationController::class, 'confirm'])->name('registrations.confirm')->whereUuid('registration');
        Route::post('/registrations/{registration}/generate', [TaxRegistrationController::class, 'generate'])->name('registrations.generate')->whereUuid('registration');

        Route::get('/rules', [TaxRuleController::class, 'index'])->name('rules.index');
        Route::post('/rules', [TaxRuleController::class, 'store'])->name('rules.store');
        Route::post('/rules/import-finance-codes', [TaxRuleController::class, 'importFinanceCodes'])->name('rules.import');
        Route::post('/rules/draft-income-tax', [TaxRuleController::class, 'draftIncomeTax'])->name('rules.draft_income_tax');
        Route::patch('/rules/{rule}', [TaxRuleController::class, 'update'])->name('rules.update')->whereUuid('rule');
        Route::post('/rules/{rule}/simulate', [TaxRuleController::class, 'simulate'])->name('rules.simulate')->whereUuid('rule');
        Route::post('/rules/{rule}/submit', [TaxRuleController::class, 'submit'])->name('rules.submit')->whereUuid('rule');
        Route::post('/rules/{rule}/approve', [TaxRuleController::class, 'approve'])->name('rules.approve')->whereUuid('rule');
        Route::post('/rules/{rule}/reject', [TaxRuleController::class, 'reject'])->name('rules.reject')->whereUuid('rule');
        Route::post('/rules/{rule}/retire', [TaxRuleController::class, 'retire'])->name('rules.retire')->whereUuid('rule');

        Route::get('/periods', [TaxPeriodController::class, 'index'])->name('periods.index');
        Route::get('/periods/{period}', [TaxPeriodController::class, 'show'])->name('periods.show')->whereUuid('period');
        Route::post('/periods/{period}/refresh', [TaxPeriodController::class, 'refresh'])->name('periods.refresh')->whereUuid('period');
        Route::post('/periods/{period}/transition', [TaxPeriodController::class, 'transition'])->name('periods.transition')->whereUuid('period');
        Route::post('/periods/{period}/reopen', [TaxPeriodController::class, 'reopen'])->name('periods.reopen')->whereUuid('period');
        Route::post('/periods/{period}/import-payroll', [TaxPeriodController::class, 'importPayroll'])->name('periods.import_payroll')->whereUuid('period');
        Route::post('/periods/{period}/reconcile-payroll', [TaxPeriodController::class, 'reconcilePayroll'])->name('periods.reconcile_payroll')->whereUuid('period');
        Route::post('/periods/{period}/prepare-return', [TaxReturnController::class, 'prepare'])->name('returns.prepare')->whereUuid('period');

        Route::get('/returns', [TaxReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/{return}', [TaxReturnController::class, 'show'])->name('returns.show')->whereUuid('return');
        Route::post('/returns/{return}/adjust', [TaxReturnController::class, 'adjust'])->name('returns.adjust')->whereUuid('return');
        Route::post('/returns/{return}/explain', [TaxReturnController::class, 'explain'])->name('returns.explain')->whereUuid('return');
        Route::post('/returns/{return}/validate', [TaxReturnController::class, 'validateReturn'])->name('returns.validate')->whereUuid('return');
        Route::post('/returns/{return}/send', [TaxReturnController::class, 'send'])->name('returns.send')->whereUuid('return');
        Route::post('/returns/{return}/review', [TaxReturnController::class, 'review'])->name('returns.review')->whereUuid('return');
        Route::post('/returns/{return}/approve', [TaxReturnController::class, 'approve'])->name('returns.approve')->whereUuid('return');
        Route::post('/returns/{return}/submission', [TaxReturnController::class, 'submission'])->name('returns.submission')->whereUuid('return');
        Route::post('/returns/{return}/outcome', [TaxReturnController::class, 'outcome'])->name('returns.outcome')->whereUuid('return');
        Route::get('/returns/{return}/capture', [TaxReturnController::class, 'capture'])->name('returns.capture')->whereUuid('return');
        Route::get('/returns/{return}/fields/{field}/sources', [TaxReturnController::class, 'sources'])->name('returns.sources')->whereUuid('return')->where('field', '[A-Za-z0-9]{1,20}');
        Route::post('/returns/{return}/evidence', [TaxReturnController::class, 'evidence'])->name('returns.evidence')->whereUuid('return');

        Route::get('/income-tax', [IncomeTaxController::class, 'index'])->name('income.index');
        Route::get('/income-tax/classifications', [IncomeTaxController::class, 'classifications'])->name('income.classifications');
        Route::post('/income-tax/classifications', [IncomeTaxController::class, 'classify'])->name('income.classify');
        Route::post('/income-tax/opening-balances', [IncomeTaxController::class, 'openingBalance'])->name('income.opening_balance');
        Route::post('/periods/{period}/prepare-computation', [IncomeTaxController::class, 'prepare'])->name('income.prepare')->whereUuid('period');
        Route::get('/income-tax/{computation}', [IncomeTaxController::class, 'show'])->name('income.show')->whereUuid('computation');
        Route::post('/income-tax/{computation}/lines', [IncomeTaxController::class, 'addLine'])->name('income.lines.store')->whereUuid('computation');
        Route::delete('/income-tax/{computation}/lines/{line}', [IncomeTaxController::class, 'removeLine'])->name('income.lines.destroy')->whereNumber('line')->whereUuid('computation');
        Route::post('/income-tax/{computation}/send', [IncomeTaxController::class, 'send'])->name('income.send')->whereUuid('computation');
        Route::post('/income-tax/{computation}/review', [IncomeTaxController::class, 'review'])->name('income.review')->whereUuid('computation');
        Route::post('/income-tax/{computation}/approve', [IncomeTaxController::class, 'approve'])->name('income.approve')->whereUuid('computation');
        Route::post('/income-tax/{computation}/evidence', [IncomeTaxController::class, 'evidence'])->name('income.evidence')->whereUuid('computation');

        Route::get('/liabilities', [TaxLiabilityController::class, 'index'])->name('liabilities.index');
        Route::get('/liabilities/export', [TaxLiabilityController::class, 'export'])->name('liabilities.export');
        Route::post('/liabilities', [TaxLiabilityController::class, 'store'])->name('liabilities.store');
        Route::get('/liabilities/{liability}/candidates', [TaxLiabilityController::class, 'candidates'])->name('liabilities.candidates')->whereUuid('liability');
        Route::post('/liabilities/{liability}/allocate', [TaxLiabilityController::class, 'allocate'])->name('liabilities.allocate')->whereUuid('liability');
        Route::post('/allocations/{allocation}/reverse', [TaxLiabilityController::class, 'reverse'])->name('allocations.reverse')->whereNumber('allocation');

        Route::get('/transactions', [TaxTransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/export', [TaxTransactionController::class, 'export'])->name('transactions.export');
        Route::post('/transactions/{transaction}/resolve', [TaxTransactionController::class, 'resolve'])->name('transactions.resolve')->whereUuid('transaction');

        Route::get('/reconciliations', [TaxReconciliationController::class, 'index'])->name('reconciliations.index');
        Route::post('/reconciliations', [TaxReconciliationController::class, 'run'])->name('reconciliations.run');
        Route::get('/reconciliations/{reconciliation}', [TaxReconciliationController::class, 'show'])->name('reconciliations.show')->whereUuid('reconciliation');
        Route::post('/reconciliations/{reconciliation}/sign-off', [TaxReconciliationController::class, 'signOff'])->name('reconciliations.sign_off')->whereUuid('reconciliation');
        Route::post('/reconciliations/{reconciliation}/reopen', [TaxReconciliationController::class, 'reopen'])->name('reconciliations.reopen')->whereUuid('reconciliation');
        Route::post('/reconciliation-items/{item}/explain', [TaxReconciliationController::class, 'explain'])->name('reconciliations.explain')->whereNumber('item');
        Route::post('/reconciliation-items/{item}/match', [TaxReconciliationController::class, 'match'])->name('reconciliations.match')->whereNumber('item');
        Route::post('/reconciliation-items/{item}/assign', [TaxReconciliationController::class, 'assign'])->name('reconciliations.assign')->whereNumber('item');

        Route::get('/calendar', [TaxCalendarController::class, 'index'])->name('calendar.index');
        Route::post('/calendar', [TaxCalendarController::class, 'store'])->name('calendar.store');
        Route::post('/calendar/{deadline}/reschedule', [TaxCalendarController::class, 'reschedule'])->name('calendar.reschedule')->whereUuid('deadline');
        Route::post('/calendar/{deadline}/assign', [TaxCalendarController::class, 'assign'])->name('calendar.assign')->whereUuid('deadline');
        Route::post('/calendar/{deadline}/complete', [TaxCalendarController::class, 'complete'])->name('calendar.complete')->whereUuid('deadline');

        Route::get('/import', [TaxImportController::class, 'index'])->name('import.index');
        Route::get('/import/template/{kind}', [TaxImportController::class, 'template'])->name('import.template')->whereIn('kind', ['registrations', 'liabilities', 'returns', 'carry_forwards']);
        Route::post('/import', [TaxImportController::class, 'preview'])->name('import.preview');
        Route::post('/import/{batch}/commit', [TaxImportController::class, 'commit'])->name('import.commit')->whereUuid('batch');
        Route::post('/import/{batch}/reject', [TaxImportController::class, 'reject'])->name('import.reject')->whereUuid('batch');

        Route::get('/reports', [TaxReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [TaxReportController::class, 'export'])->name('reports.export');

        Route::post('/evidence/{type}/{uuid}', [TaxEvidenceController::class, 'store'])->name('evidence.store')
            ->whereIn('type', ['registrations', 'liabilities', 'reconciliations', 'returns', 'computations'])->whereUuid('uuid');

        Route::get('/admin', [TaxAdminController::class, 'index'])->name('admin.index');
        Route::post('/admin/settings', [TaxAdminController::class, 'update'])->name('admin.update');
        Route::post('/admin/events/{event}/retry', [TaxAdminController::class, 'retryEvent'])->name('admin.events.retry')->whereNumber('event');

        Route::get('/audit', [TaxAuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [TaxAuditController::class, 'export'])->name('audit.export');
        Route::post('/audit/verify', [TaxAuditController::class, 'verify'])->name('audit.verify');
    });
