<?php

use App\Modules\HR\Offboarding\Http\Controllers\OffboardingAnalyticsController;
use App\Modules\HR\Offboarding\Http\Controllers\OffboardingController;
use App\Modules\HR\Offboarding\Http\Controllers\OffboardingReportController;
use App\Modules\HR\Offboarding\Http\Controllers\OffboardingTemplateController;
use Illuminate\Support\Facades\Route;

// crud.permissions:offboarding.manage — terminating employees, signing clearances
// and reading exit interviews is HR-admin work. Without the gate ANY employee
// could offboard anyone (seeded to administrator/super admin/hr manager; grant
// further via Roles & Permissions).
Route::middleware(['web', 'auth', 'module.active:offboarding', 'crud.permissions:offboarding.manage'])->group(function () {
    Route::prefix('offboarding')->name('offboarding.')->group(function () {

        // ── Plans index ──────────────────────────────────────────────────────
        Route::get('/', [OffboardingController::class, 'index'])->name('index');

        // ── Analytics ────────────────────────────────────────────────────────
        Route::get('/analytics', [OffboardingAnalyticsController::class, 'index'])->name('analytics');

        // ── Reports ──────────────────────────────────────────────────────────
        Route::get('/reports', [OffboardingReportController::class, 'index'])->name('reports');
        Route::get('/reports/{key}/export', [OffboardingReportController::class, 'export'])->name('reports.export');

        // ── Person-scoped plans ──────────────────────────────────────────────
        Route::prefix('persons/{person}')->name('persons.')->group(function () {
            Route::post('/plans', [OffboardingController::class, 'store'])->name('plans.store');
            Route::get('/plans/{plan}', [OffboardingController::class, 'show'])->name('plans.show');
            Route::put('/plans/{plan}/details', [OffboardingController::class, 'updatePlanDetails'])->name('plans.details.update');
            Route::post('/plans/{plan}/tasks/{task}/complete', [OffboardingController::class, 'completeTask'])->name('plans.tasks.complete');
            Route::post('/plans/{plan}/exit-interview', [OffboardingController::class, 'storeExitInterview'])->name('plans.exit-interview.store');
            Route::post('/plans/{plan}/clearances/{clearance}/sign-off', [OffboardingController::class, 'signOffClearance'])->name('plans.clearances.sign-off');
            Route::post('/plans/{plan}/finalize', [OffboardingController::class, 'finalize'])->name('plans.finalize');
        });

        // ── Templates ────────────────────────────────────────────────────────
        Route::prefix('templates')->name('templates.')->group(function () {
            Route::get('/', [OffboardingTemplateController::class, 'index'])->name('index');
            Route::post('/', [OffboardingTemplateController::class, 'store'])->name('store');
            Route::get('/{template}', [OffboardingTemplateController::class, 'show'])->name('show');
            Route::put('/{template}', [OffboardingTemplateController::class, 'update'])->name('update');
            Route::delete('/{template}', [OffboardingTemplateController::class, 'destroy'])->name('destroy');
            Route::post('/{template}/tasks', [OffboardingTemplateController::class, 'storeTask'])->name('tasks.store');
            Route::put('/{template}/tasks/{task}', [OffboardingTemplateController::class, 'updateTask'])->name('tasks.update');
            Route::delete('/{template}/tasks/{task}', [OffboardingTemplateController::class, 'destroyTask'])->name('tasks.destroy');
        });
    });
});
