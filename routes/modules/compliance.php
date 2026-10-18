<?php

use App\Modules\Compliance\Http\Controllers\ComplianceDashboardController;
use App\Modules\Compliance\Http\Controllers\ConsentController;
use App\Modules\Compliance\Http\Controllers\DataBreachController;
use App\Modules\Compliance\Http\Controllers\PhiAccessLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'module.active:popia_compliance'])
    ->prefix('compliance')
    ->name('compliance.')
    ->group(function () {

        Route::get('/',        [ComplianceDashboardController::class, 'index'])->name('dashboard');

        // ── Data Breach Incidents ──────────────────────────────────────────────
        Route::get('/breaches',                     [DataBreachController::class, 'index'])->name('breaches.index');
        Route::post('/breaches',                    [DataBreachController::class, 'store'])->name('breaches.store');
        Route::get('/breaches/{incident:uuid}',     [DataBreachController::class, 'show'])->name('breaches.show');
        Route::patch('/breaches/{incident:uuid}/status', [DataBreachController::class, 'updateStatus'])->name('breaches.update-status');

        // ── Consent Register ──────────────────────────────────────────────────
        Route::get('/consents', [ConsentController::class, 'index'])->name('consents.index');

        // ── PHI Access Log ────────────────────────────────────────────────────
        Route::get('/phi-log', [PhiAccessLogController::class, 'index'])->name('phi-log.index');
    });
