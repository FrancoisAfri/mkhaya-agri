<?php

use App\Modules\Finance\PettyCash\Controllers\PettyCashDashboardController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashFloatController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashReconciliationController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashReportController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashRequestController;
use App\Modules\Finance\PettyCash\Controllers\PettyCashSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('module.active:petty_cash')->prefix('petty-cash')->name('petty-cash.')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/', [PettyCashDashboardController::class, 'index'])->name('dashboard');

    // ── Floats ────────────────────────────────────────────────────────────────
    Route::prefix('floats')->name('floats.')->group(function () {
        Route::get('/',                         [PettyCashFloatController::class, 'index'])->name('index');
        Route::get('/create',                   [PettyCashFloatController::class, 'create'])->name('create');
        Route::post('/',                        [PettyCashFloatController::class, 'store'])->name('store');
        Route::get('/{float}',                  [PettyCashFloatController::class, 'show'])->name('show');
        Route::patch('/{float}',                [PettyCashFloatController::class, 'update'])->name('update');
        Route::post('/{float}/replenish',       [PettyCashFloatController::class, 'replenish'])->name('replenish');
    });

    // ── Requests ──────────────────────────────────────────────────────────────
    Route::prefix('requests')->name('requests.')->group(function () {
        Route::get('/',                         [PettyCashRequestController::class, 'index'])->name('index');
        Route::get('/create',                   [PettyCashRequestController::class, 'create'])->name('create');
        Route::post('/',                        [PettyCashRequestController::class, 'store'])->name('store');
        /*
         * `{pcRequest}`, not `{request}`.
         *
         * Laravel matches route parameters to controller arguments by name, and every action below
         * also takes the HTTP `Request $request`. With the parameter named `request` the model
         * never bound: `$pcRequest` was resolved from the container as an *empty* PettyCashRequest,
         * so `$request->status` was null and every approve, reject, issue and settle answered
         * "Only pending requests can be approved." The whole petty-cash approval flow was dead.
         */
        Route::get('/{pcRequest}',              [PettyCashRequestController::class, 'show'])->name('show');
        Route::post('/{pcRequest}/approve',     [PettyCashRequestController::class, 'approve'])->name('approve');
        Route::post('/{pcRequest}/reject',      [PettyCashRequestController::class, 'reject'])->name('reject');
        Route::post('/{pcRequest}/issue',       [PettyCashRequestController::class, 'issue'])->name('issue');
        Route::post('/{pcRequest}/settle',      [PettyCashRequestController::class, 'settle'])->name('settle');
        Route::post('/{pcRequest}/receipt',     [PettyCashRequestController::class, 'uploadReceipt'])->name('receipt.upload');
    });

    // ── Reconciliations ───────────────────────────────────────────────────────
    Route::prefix('reconciliations')->name('reconciliations.')->group(function () {
        Route::get('/',                             [PettyCashReconciliationController::class, 'index'])->name('index');
        Route::get('/create',                       [PettyCashReconciliationController::class, 'create'])->name('create');
        Route::post('/',                            [PettyCashReconciliationController::class, 'store'])->name('store');
        Route::get('/{reconciliation}',             [PettyCashReconciliationController::class, 'show'])->name('show');
        Route::post('/{reconciliation}/approve',    [PettyCashReconciliationController::class, 'approve'])->name('approve');
        Route::post('/{reconciliation}/reject',     [PettyCashReconciliationController::class, 'reject'])->name('reject');
    });

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',                         [PettyCashReportController::class, 'index'])->name('index');
        Route::get('/float/{float}/ai',         [PettyCashReportController::class, 'aiReport'])->name('ai');
    });

    // ── Settings & Policy ─────────────────────────────────────────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/',                                         [PettyCashSettingsController::class, 'index'])->name('index');
        Route::get('/violations',                               [PettyCashSettingsController::class, 'violations'])->name('violations');
        Route::post('/violations/{violation}/resolve',          [PettyCashSettingsController::class, 'resolveViolation'])->name('violations.resolve');
    });
});
