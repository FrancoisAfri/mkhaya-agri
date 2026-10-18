<?php

use App\Core\Auth\Controllers\AuthController;
use App\Core\Auth\Controllers\LockController;
use App\Core\Auth\Controllers\PasswordChangeController;
use App\Core\Auth\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

    /*
     * Forgetting a password.
     *
     * "Forgot Password?" was an anchor with no href and nothing behind it, so the only way back in
     * was to find an administrator — and the one-time code, the other route, threw for any account
     * without a personnel record. These names are Laravel's defaults on purpose: the framework's
     * password broker and its ResetPassword notification build their URL from `password.reset`.
     */
    Route::get('/forgot-password',  [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'send'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password',  [PasswordResetController::class, 'update'])->name('password.store');

    // SaaS self-service tenant signup (Phase B). 404s in the self-hosted edition.
    Route::get('/signup',  [\App\Modules\Tenancy\Http\Controllers\RegisterCompanyController::class, 'show'])->name('tenancy.signup');
    Route::post('/signup', [\App\Modules\Tenancy\Http\Controllers\RegisterCompanyController::class, 'store'])->name('tenancy.signup.store');
});

Route::post('/send-otp', [AuthController::class, 'sendOtp'])->name('send.otp');
Route::post('/otp/login', [AuthController::class, 'loginWithOtp'])->name('auth.otp.login');

// ── Two-Factor Authentication (SMS OTP at login) ────────────────────────────
Route::get('/2fa',        [AuthController::class, 'showTwoFactor'])->name('2fa.index');
Route::post('/2fa',       [AuthController::class, 'verifyTwoFactor'])->name('2fa.verify');
Route::post('/2fa/resend',[AuthController::class, 'resendTwoFactor'])->name('2fa.resend');




Route::middleware(['auth', 'crud.permissions'])->group(function () {
    Route::post('/lock',   [LockController::class, 'lock'])->name('lock.session');
    Route::get('/lock',    [LockController::class, 'show'])->name('lock.screen');
    Route::post('/unlock', [LockController::class, 'unlock'])->name('login.unlock');
});

// Password expiry — exempt from CRUD permission check
Route::middleware('auth')->group(function () {
    Route::get('/password/change',  [PasswordChangeController::class, 'show'])->name('auth.password.change');
    Route::post('/password/change', [PasswordChangeController::class, 'update'])->name('auth.password.update');
});
