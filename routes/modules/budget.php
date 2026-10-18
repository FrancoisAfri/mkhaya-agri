<?php

use App\Modules\Budget\Controllers\BudgetAdjustmentController;
use App\Modules\Budget\Controllers\BudgetApprovalSetupController;
use App\Modules\Budget\Controllers\BudgetApprovalsController;
use App\Modules\Budget\Controllers\BudgetDelegationController;
use App\Modules\Budget\Controllers\BudgetController;
use App\Modules\Budget\Controllers\BudgetDashboardController;
use App\Modules\Budget\Controllers\BudgetInsightsController;
use App\Modules\Budget\Controllers\BudgetLedgerController;
use App\Modules\Budget\Controllers\BudgetOverrideController;
use App\Modules\Budget\Controllers\BudgetPlanningController;
use App\Modules\Budget\Controllers\BudgetSettingsController;
use App\Modules\Budget\Controllers\BudgetTrackingController;
use Illuminate\Support\Facades\Route;

// Auth + crud.permissions already applied by the parent group in web.php.
Route::middleware('module.active:budget_management')->prefix('budgets')->name('budgets.')->group(function () {

    Route::get('/', [BudgetDashboardController::class, 'index'])->name('dashboard');

    // ── Approvals: what is waiting on me, and what I am waiting on ──────────────
    Route::get('/approvals', [BudgetApprovalsController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/{uuid}/approve', [BudgetApprovalsController::class, 'approve'])->name('approvals.approve');
    Route::post('/approvals/{uuid}/reject', [BudgetApprovalsController::class, 'reject'])->name('approvals.reject');
    Route::post('/approvals/{uuid}/withdraw', [BudgetApprovalsController::class, 'cancel'])->name('approvals.cancel');

    /*
     * Handing your approvals to somebody while you are away.
     *
     * The controller, the model, the approval engine's handling of a delegate and the dialog on the
     * Approvals screen were all built. These two lines were not, so pressing Save threw before it
     * reached the server and the feature had never once run — `budget_approval_delegations` has no
     * rows. The uuid is the key the screen is given, matching the approval routes above.
     */
    Route::post('/approvals/delegations', [BudgetDelegationController::class, 'store'])->name('approvals.delegations.store');
    Route::delete('/approvals/delegations/{uuid}', [BudgetDelegationController::class, 'destroy'])->name('approvals.delegations.destroy');

    // ── Tracking: position, alerts, blockers and the rules behind them ──────────
    Route::get('/tracking', [BudgetTrackingController::class, 'index'])->name('tracking.index');
    Route::post('/tracking/sweep', [BudgetTrackingController::class, 'sweep'])->name('tracking.sweep');
    Route::post('/tracking/alerts/{uuid}/acknowledge', [BudgetTrackingController::class, 'acknowledge'])->name('tracking.alerts.acknowledge');
    Route::post('/tracking/rules', [BudgetTrackingController::class, 'ruleStore'])->name('tracking.rules.store');
    Route::put('/tracking/rules/{uuid}', [BudgetTrackingController::class, 'ruleUpdate'])->name('tracking.rules.update');
    Route::delete('/tracking/rules/{uuid}', [BudgetTrackingController::class, 'ruleDestroy'])->name('tracking.rules.destroy');

    // ── BI ──────────────────────────────────────────────────────────────────────
    Route::get('/analytics', [BudgetInsightsController::class, 'analytics'])->name('insights.analytics');
    Route::get('/reports', [BudgetInsightsController::class, 'reports'])->name('insights.reports');
    Route::get('/reports/{key}/export', [BudgetInsightsController::class, 'export'])->name('insights.export');

    // ── Preparation: phasing, carry-forward, bulk capture ───────────────────────
    Route::get('/planning', [BudgetPlanningController::class, 'index'])->name('planning.index');
    Route::post('/planning/carry-forward', [BudgetPlanningController::class, 'carryForward'])->name('planning.carry_forward');
    Route::post('/planning/import', [BudgetPlanningController::class, 'import'])->name('planning.import');
    Route::get('/planning/template', [BudgetPlanningController::class, 'template'])->name('planning.template');
    Route::post('/planning/years/{financialYear}/close', [BudgetPlanningController::class, 'closeYear'])->name('planning.close_year')->whereNumber('financialYear');

    // ── Settings (financial years, periods, categories, approval routing) ───────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [BudgetSettingsController::class, 'index'])->name('index');

        Route::post('/years',                 [BudgetSettingsController::class, 'yearStore'])->name('years.store');
        Route::patch('/years/{financialYear}', [BudgetSettingsController::class, 'yearUpdate'])->name('years.update')->whereNumber('financialYear');
        Route::delete('/years/{financialYear}', [BudgetSettingsController::class, 'yearDestroy'])->name('years.destroy')->whereNumber('financialYear');
        Route::post('/years/{financialYear}/periods', [BudgetSettingsController::class, 'periodStore'])->name('periods.store')->whereNumber('financialYear');
        Route::post('/years/{financialYear}/periods/generate', [BudgetSettingsController::class, 'periodGenerate'])->name('periods.generate')->whereNumber('financialYear');
        Route::delete('/periods/{period}',    [BudgetSettingsController::class, 'periodDestroy'])->name('periods.destroy')->whereNumber('period');

        Route::post('/categories',            [BudgetSettingsController::class, 'categoryStore'])->name('categories.store');
        Route::put('/categories/{category}', [BudgetSettingsController::class, 'categoryUpdate'])->name('categories.update')->whereNumber('category');
        Route::delete('/categories/{category}', [BudgetSettingsController::class, 'categoryDestroy'])->name('categories.destroy')->whereNumber('category');

        // Approval routing — designed per tenant, because companies do not agree on who signs what.
        Route::get('/approvals', [BudgetApprovalSetupController::class, 'index'])->name('approvals');
        Route::post('/approvals', [BudgetApprovalSetupController::class, 'store'])->name('approvals.store');
        Route::put('/approvals/{uuid}', [BudgetApprovalSetupController::class, 'update'])->name('approvals.update');
        Route::delete('/approvals/{uuid}', [BudgetApprovalSetupController::class, 'destroy'])->name('approvals.destroy');
    });

    // ── Budgets (static before /{budget}) ───────────────────────────────────────
    Route::get('/list',    [BudgetController::class, 'index'])->name('index');
    Route::get('/create',  [BudgetController::class, 'create'])->name('create');
    Route::post('/',       [BudgetController::class, 'store'])->name('store');

    Route::get('/{budget}',    [BudgetController::class, 'show'])->name('show')->whereUuid('budget');
    Route::patch('/{budget}',  [BudgetController::class, 'update'])->name('update')->whereUuid('budget');
    Route::delete('/{budget}', [BudgetController::class, 'destroy'])->name('destroy')->whereUuid('budget');

    // Approval is no longer a button on this page — it goes to the queue like everything else.
    Route::post('/{budget}/submit', [BudgetController::class, 'submit'])->name('submit')->whereUuid('budget');
    /*
     * Where this budget sits under another, for the roll-up.
     *
     * Same omission as the delegation routes: the controller, the roll-up service that re-parents and
     * refuses a cycle, and the picker on the budget screen all existed, and the one line joining them
     * did not — so choosing a parent threw in the browser before anything was sent.
     */
    Route::post('/{budget}/parent', [BudgetController::class, 'setParent'])->name('parent.set')->whereUuid('budget');

    Route::post('/{budget}/adjustments', [BudgetAdjustmentController::class, 'store'])->name('adjustments.store')->whereUuid('budget');
    Route::post('/{budget}/overrides',   [BudgetOverrideController::class, 'store'])->name('overrides.store')->whereUuid('budget');

    // Owners and delegates — who the alerts reach, and who a "budget owner" step resolves to.
    Route::post('/{budget}/owners', [BudgetController::class, 'ownerStore'])->name('owners.store')->whereUuid('budget');
    Route::delete('/{budget}/owners/{owner}', [BudgetController::class, 'ownerDestroy'])->name('owners.destroy')->whereUuid('budget')->whereNumber('owner');

    // Manual ledger entry, for the commitments that never pass through procurement.
    Route::post('/{budget}/reservations', [BudgetLedgerController::class, 'reserve'])->name('ledger.reserve')->whereUuid('budget');
    Route::post('/{budget}/commitments',  [BudgetLedgerController::class, 'commit'])->name('ledger.commit')->whereUuid('budget');
    Route::post('/{budget}/consumptions', [BudgetLedgerController::class, 'consume'])->name('ledger.consume')->whereUuid('budget');
    Route::post('/reservations/{reservation}/release', [BudgetLedgerController::class, 'release'])->name('ledger.reservations.release')->whereNumber('reservation');
    Route::post('/commitments/{commitment}/release',   [BudgetLedgerController::class, 'releaseCommitment'])->name('ledger.commitments.release')->whereNumber('commitment');

    // Phasing: when the allocation is planned to go.
    Route::post('/{budget}/phasing',        [BudgetPlanningController::class, 'savePhasing'])->name('phasing.save')->whereUuid('budget');
    Route::post('/{budget}/phasing/spread', [BudgetPlanningController::class, 'spreadPhasing'])->name('phasing.spread')->whereUuid('budget');

    // Reforecast (the owner's revised expectation for open periods) and the reasons behind variances.
    Route::post('/{budget}/forecast', [BudgetPlanningController::class, 'saveForecast'])->name('forecast.save')->whereUuid('budget');
    Route::post('/{budget}/variances/{period}/explain', [BudgetPlanningController::class, 'explainVariance'])->name('variances.explain')->whereUuid('budget')->whereNumber('period');
});
