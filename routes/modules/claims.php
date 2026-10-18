<?php

use App\Modules\Finance\Claims\Controllers\ClaimsAiController;
use App\Modules\Finance\Claims\Controllers\ClaimsAttachmentController;
use App\Modules\Finance\Claims\Controllers\ClaimsApprovalController;
use App\Modules\Finance\Claims\Controllers\ClaimsController;
use App\Modules\Finance\Claims\Controllers\ClaimsDashboardController;
use App\Modules\Finance\Claims\Controllers\ClaimsDelegationController;
use App\Modules\Finance\Claims\Controllers\ClaimsExecutiveDashboardController;
use App\Modules\Finance\Claims\Controllers\ClaimsPaymentController;
use App\Modules\Finance\Claims\Controllers\ClaimsReportController;
use App\Modules\Finance\Claims\Controllers\ClaimsSetupController;
use App\Modules\Finance\Claims\Controllers\ClaimsTravelController;
use Illuminate\Support\Facades\Route;

Route::middleware('module.active:claims')->prefix('claims')->name('claims.')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/', [ClaimsDashboardController::class, 'index'])->name('dashboard');

    // ── My Claims ─────────────────────────────────────────────────────────────
    Route::prefix('my')->name('my.')->group(function () {
        Route::get('/',                      [ClaimsController::class, 'index'])->name('index');
        Route::get('/create',                [ClaimsController::class, 'create'])->name('create');
        Route::post('/',                     [ClaimsController::class, 'store'])->name('store');
        Route::get('/{claim}',               [ClaimsController::class, 'show'])->name('show');
        Route::get('/{claim}/edit',          [ClaimsController::class, 'edit'])->name('edit');
        Route::get('/{claim}/detail',        [ClaimsController::class, 'detail'])->name('detail');
        Route::patch('/{claim}',             [ClaimsController::class, 'update'])->name('update');
        Route::post('/{claim}/items',        [ClaimsController::class, 'addItem'])->name('items.add');
        Route::delete('/{claim}/items/{item}', [ClaimsController::class, 'removeItem'])->name('items.remove');
        Route::post('/{claim}/receipts',     [ClaimsController::class, 'uploadReceipt'])->name('receipts.upload');
        Route::post('/{claim}/submit',       [ClaimsController::class, 'submit'])->name('submit');
        Route::post('/{claim}/cancel',       [ClaimsController::class, 'cancel'])->name('cancel');
    });

    // ── Approvals ─────────────────────────────────────────────────────────────
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/',                          [ClaimsApprovalController::class, 'index'])->name('index');
        Route::post('/{claim}/approve',          [ClaimsApprovalController::class, 'approve'])->name('approve');
        Route::post('/{claim}/reject',           [ClaimsApprovalController::class, 'reject'])->name('reject');
        Route::post('/{claim}/pay',              [ClaimsApprovalController::class, 'pay'])->name('pay');
        // Delegation
        Route::get('/delegations',                   [ClaimsDelegationController::class, 'index'])->name('delegations.index');
        Route::post('/delegations',                  [ClaimsDelegationController::class, 'store'])->name('delegations.store');
        Route::delete('/delegations/{delegation}',   [ClaimsDelegationController::class, 'revoke'])->name('delegations.revoke');
    });

    // ── AI Intelligence ───────────────────────────────────────────────────────
    Route::prefix('ai-intelligence')->name('ai.')->group(function () {
        Route::get('/',                          [ClaimsAiController::class, 'index'])->name('index');
        Route::post('/analyse-all',              [ClaimsAiController::class, 'analyseAll'])->name('analyse-all');
        Route::post('/{claim}/analyse',          [ClaimsAiController::class, 'analyseClaim'])->name('analyse');
    });

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',                            [ClaimsReportController::class, 'index'])->name('index');
        Route::get('/scheduled',                   [ClaimsReportController::class, 'scheduleIndex'])->name('scheduled');
        Route::get('/export',                      [ClaimsReportController::class, 'export'])->name('export');
        Route::post('/schedules',                  [ClaimsReportController::class, 'scheduleStore'])->name('schedules.store');
        Route::patch('/schedules/{schedule}',      [ClaimsReportController::class, 'scheduleUpdate'])->name('schedules.update');
        Route::delete('/schedules/{schedule}',     [ClaimsReportController::class, 'scheduleDestroy'])->name('schedules.destroy');
    });

    // ── Payment Processing ────────────────────────────────────────────────────
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/',               [ClaimsPaymentController::class, 'index'])->name('index');
        Route::post('/{claim}/process',[ClaimsPaymentController::class, 'process'])->name('process');
    });

    // ── Executive Dashboard ───────────────────────────────────────────────────
    Route::get('/executive', [ClaimsExecutiveDashboardController::class, 'index'])->name('executive');

    // ── Travel ───────────────────────────────────────────────────────────────
    Route::post('/travel/calculate-distance', [ClaimsTravelController::class, 'calculateDistance'])->name('travel.calculate-distance');

    // ── Attachments ───────────────────────────────────────────────────────────
    Route::get('/attachments/{uuid}/download', [ClaimsAttachmentController::class, 'download'])->name('attachments.download');

    // ── Setup ─────────────────────────────────────────────────────────────────
    Route::prefix('setup')->name('setup.')->group(function () {
        Route::get('/',                                          [ClaimsSetupController::class, 'index'])->name('index');
        Route::post('/categories',                               [ClaimsSetupController::class, 'storeCategory'])->name('categories.store');
        Route::patch('/categories/{category}',                   [ClaimsSetupController::class, 'updateCategory'])->name('categories.update');
        Route::delete('/categories/{category}',                  [ClaimsSetupController::class, 'destroyCategory'])->name('categories.destroy');
        Route::post('/approval-steps',                            [ClaimsSetupController::class, 'storeApprovalStep'])->name('approval-steps.store');
        Route::patch('/approval-steps/{step}',                   [ClaimsSetupController::class, 'updateApprovalStep'])->name('approval-steps.update');
        Route::delete('/approval-steps/{step}',                  [ClaimsSetupController::class, 'destroyApprovalStep'])->name('approval-steps.destroy');
        Route::post('/policy-links',                              [ClaimsSetupController::class, 'linkPolicyRule'])->name('policy-links.store');
        Route::delete('/policy-links/{id}',                      [ClaimsSetupController::class, 'unlinkPolicyRule'])->name('policy-links.destroy');
        Route::post('/payment-processors',                        [ClaimsSetupController::class, 'storePaymentProcessor'])->name('payment-processors.store');
        Route::delete('/payment-processors/{processor}',          [ClaimsSetupController::class, 'destroyPaymentProcessor'])->name('payment-processors.destroy');
    });
});
