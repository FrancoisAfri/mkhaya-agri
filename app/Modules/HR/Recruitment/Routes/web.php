<?php

use App\Modules\HR\Recruitment\Http\Controllers\RecruitmentDashboardController;
use App\Modules\HR\Recruitment\Http\Controllers\RequisitionApprovalController;
use App\Modules\HR\Recruitment\Http\Controllers\RequisitionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('recruitment')->name('recruitment.')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard', [RecruitmentDashboardController::class, 'index'])->name('dashboard');

    // ── Requisitions ──────────────────────────────────────────────────────────
    Route::prefix('requisitions')->name('requisitions.')->group(function () {
        Route::get('/',    [RequisitionController::class, 'index'])->name('index');
        Route::get('/create', [RequisitionController::class, 'create'])->name('create');
        Route::post('/',   [RequisitionController::class, 'store'])->name('store');

        Route::prefix('/{requisition}')->group(function () {
            Route::get('/',       [RequisitionController::class, 'show'])->name('show');
            Route::put('/',       [RequisitionController::class, 'update'])->name('update');
            Route::delete('/',    [RequisitionController::class, 'destroy'])->name('destroy');
            Route::post('/submit',  [RequisitionController::class, 'submit'])->name('submit');
            Route::post('/cancel',  [RequisitionController::class, 'cancel'])->name('cancel');
            Route::post('/convert', [RequisitionController::class, 'convert'])->name('convert');
        });
    });

    // ── Approvals ─────────────────────────────────────────────────────────────
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/pending', [RequisitionApprovalController::class, 'pending'])->name('pending');

        Route::prefix('requisitions/{requisition}')->name('requisitions.')->group(function () {
            Route::post('/approve', [RequisitionApprovalController::class, 'approve'])->name('approve');
            Route::post('/reject',  [RequisitionApprovalController::class, 'reject'])->name('reject');
        });
    });
});
