<?php

use App\Modules\HR\Holidays\Controller\PublicHolidatsController;
use App\Modules\Admin\Setup\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

// ── SMS Configuration ───────────────────────────────────────────────────────
Route::post('/sms-config', [SetupController::class, 'updateSmsConfig'])->name('sms-config.update');

Route::get('/public-holidays',           [PublicHolidatsController::class, 'index'])->name('public_holidays.index');
Route::post('/public-holidays/store',    [PublicHolidatsController::class, 'store'])->name('public_holidays.store');
Route::put('/public-holidays/{id}',      [PublicHolidatsController::class, 'update'])->name('public_holidays.update');
Route::delete('/public-holidays/{id}',   [PublicHolidatsController::class, 'destroy'])->name('public_holidays.destroy');



Route::get('/setup', [SetupController::class, 'index'])->name('setup.list');
Route::post('/setup/update', [SetupController::class, 'updateCompany'])->name('setup.update');
Route::post('/setup/api-keys', [SetupController::class, 'updateApiKeys'])->name('setup.api-keys.update');

// White-label logo — streamed from the DB (or legacy filesystem) for the current company.
Route::get('/branding/logo', [\App\Http\Controllers\BrandingController::class, 'logo'])->name('branding.logo');
