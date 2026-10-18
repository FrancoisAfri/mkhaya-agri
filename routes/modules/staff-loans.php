<?php

use App\Modules\Finance\Executive\Controllers\ExecutiveFinanceDashboardController;
use App\Modules\Finance\StaffLoan\Controllers\SalaryAdvanceController;
use App\Modules\Finance\StaffLoan\Controllers\StaffLoanController;
use App\Modules\Finance\StaffLoan\Controllers\StaffLoanDashboardController;
use App\Modules\Finance\StaffLoan\Controllers\StaffLoanSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->get('/staff-loans', fn() => redirect()->route('staff-loans.dashboard'));

Route::middleware(['auth', 'module.active:staff_loans'])->prefix('staff-loans')->name('staff-loans.')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard', [StaffLoanDashboardController::class, 'index'])->name('dashboard');

    // ── Staff Loans ───────────────────────────────────────────────────────────
    Route::prefix('loans')->name('loans.')->group(function () {
        Route::get('/',                [StaffLoanController::class, 'index'])->name('index');
        Route::get('/create',          [StaffLoanController::class, 'create'])->name('create');
        Route::post('/',               [StaffLoanController::class, 'store'])->name('store');
        Route::get('/{loan}',          [StaffLoanController::class, 'show'])->name('show');
        Route::post('/{loan}/approve', [StaffLoanController::class, 'approve'])->name('approve');
        Route::post('/{loan}/reject',  [StaffLoanController::class, 'reject'])->name('reject');
        Route::post('/{loan}/disburse',[StaffLoanController::class, 'disburse'])->name('disburse');
        Route::post('/{loan}/repayment',[StaffLoanController::class, 'recordRepayment'])->name('repayment');
        Route::post('/{loan}/close',   [StaffLoanController::class, 'close'])->name('close');
    });

    // ── Salary Advances ───────────────────────────────────────────────────────
    Route::prefix('advances')->name('advances.')->group(function () {
        Route::get('/',                   [SalaryAdvanceController::class, 'index'])->name('index');
        Route::get('/create',             [SalaryAdvanceController::class, 'create'])->name('create');
        Route::post('/',                  [SalaryAdvanceController::class, 'store'])->name('store');
        Route::get('/{advance}',          [SalaryAdvanceController::class, 'show'])->name('show');
        Route::post('/{advance}/approve', [SalaryAdvanceController::class, 'approve'])->name('approve');
        Route::post('/{advance}/reject',  [SalaryAdvanceController::class, 'reject'])->name('reject');
        Route::post('/{advance}/disburse',[SalaryAdvanceController::class, 'disburse'])->name('disburse');
        Route::post('/{advance}/settle',  [SalaryAdvanceController::class, 'settle'])->name('settle');
    });

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/',                                [StaffLoanSettingsController::class, 'index'])->name('index');
        Route::post('/loan-types',                     [StaffLoanSettingsController::class, 'storeLoanType'])->name('loan-types.store');
        Route::get('/violations',                      [StaffLoanSettingsController::class, 'violations'])->name('violations');
        Route::post('/violations/{violation}/resolve', [StaffLoanSettingsController::class, 'resolveViolation'])->name('violations.resolve');
    });
});

// ── Executive Finance Dashboard (Module 6) ────────────────────────────────────
Route::middleware(['auth'])->prefix('finance/executive')->name('finance.executive.')->group(function () {
    Route::get('/dashboard', [ExecutiveFinanceDashboardController::class, 'index'])->name('dashboard');
});
