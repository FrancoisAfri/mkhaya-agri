<?php

use App\Modules\HR\Onboarding\Http\Controllers\BulkOnboardingController;
use App\Modules\HR\Onboarding\Http\Controllers\OnboardingAnalyticsController;
use App\Modules\HR\Onboarding\Http\Controllers\OnboardingInboxController;
use App\Modules\HR\Onboarding\Http\Controllers\OnboardingController;
use App\Modules\HR\Onboarding\Http\Controllers\OnboardingDocumentController;
use App\Modules\HR\Onboarding\Http\Controllers\OnboardingProvisioningController;
use App\Modules\HR\Onboarding\Http\Controllers\OnboardingTemplateController;
use App\Modules\HR\Onboarding\Http\Controllers\ProbationController;
use App\Modules\HR\PreBoarding\Http\Controllers\PreBoardingController;
use Illuminate\Support\Facades\Route;

// crud.permissions:boarding.manage — converting candidates to employees,
// approving ID documents and completing onboarding tasks is HR-admin work.
// Seeded to administrator/super admin/hr manager; extend via Roles & Permissions.
Route::middleware(['web', 'auth', 'module.active:boarding', 'crud.permissions:boarding.manage'])->group(function () {

    // ── Pre-Boarding ──────────────────────────────────────────────────────────
    Route::prefix('pre-boarding')->name('pre-boarding.')->group(function () {
        Route::get('/', [PreBoardingController::class, 'index'])->name('index');
        Route::get('/{person}', [PreBoardingController::class, 'show'])->name('show');
        Route::patch('/{person}/buddy', [PreBoardingController::class, 'assignBuddy'])->name('buddy.assign');
        Route::patch('/{person}/details', [PreBoardingController::class, 'updateDetails'])->name('details.update');
        Route::get('/{person}/plans/{plan}', [PreBoardingController::class, 'showPlan'])->name('plans.show');
        Route::post('/{person}/plans/{plan}/tasks/{task}/complete',
            [PreBoardingController::class, 'completeTask'])->name('tasks.complete');
    });

    // ── Onboarding ────────────────────────────────────────────────────────────
    Route::prefix('onboarding')->name('onboarding.')->group(function () {

        // Contract → Employee conversion
        Route::post('/contracts/{contract}/convert', [OnboardingController::class, 'convert'])
            ->name('contracts.convert');

        // Onboarding plans
        Route::get('/', [OnboardingController::class, 'index'])->name('index');
        Route::post('/persons/{person}/plans', [OnboardingController::class, 'store'])->name('plans.store');
        Route::get('/persons/{person}/plans/{plan}', [OnboardingController::class, 'show'])->name('plans.show');
        Route::post('/persons/{person}/plans/{plan}/cancel', [OnboardingController::class, 'cancel'])->name('plans.cancel');
        Route::post('/persons/{person}/plans/{plan}/tasks/{task}/complete',
            [OnboardingController::class, 'completeTask'])->name('tasks.complete');

        // AI plan generator (returns JSON)
        Route::post('/persons/{person}/generate-plan', [OnboardingController::class, 'generatePlan'])
            ->name('plans.generate');

        // My onboarding work, across every new hire.
        Route::get('/inbox', [OnboardingInboxController::class, 'index'])->name('inbox');

        // Analytics + export. Offboarding has had these since it was built.
        Route::get('/analytics', [OnboardingAnalyticsController::class, 'index'])->name('analytics');
        Route::get('/analytics/export', [OnboardingAnalyticsController::class, 'export'])->name('analytics.export');

        // Bulk import — download the spreadsheet, fill it in, upload it back.
        Route::get('/bulk', [BulkOnboardingController::class, 'index'])->name('bulk.index');
        Route::get('/bulk/template', [BulkOnboardingController::class, 'template'])->name('bulk.template');
        Route::post('/bulk/{import}/reverse', [BulkOnboardingController::class, 'reverse'])->name('bulk.reverse');
        Route::post('/bulk/import', [BulkOnboardingController::class, 'import'])->name('bulk.import');

        // Templates
        Route::get('/templates', [OnboardingTemplateController::class, 'index'])->name('templates.index');
        Route::post('/templates', [OnboardingTemplateController::class, 'store'])->name('templates.store');
        Route::get('/templates/{template}', [OnboardingTemplateController::class, 'show'])->name('templates.show');
        // Copy a platform-catalogue template into this company. The catalogue itself is read-only,
        // so this is the only route by which a shared template becomes editable.
        Route::post('/templates/{template}/copy', [OnboardingTemplateController::class, 'copy'])->name('templates.copy');
        Route::put('/templates/{template}', [OnboardingTemplateController::class, 'update'])->name('templates.update');
        Route::delete('/templates/{template}', [OnboardingTemplateController::class, 'destroy'])->name('templates.destroy');
        Route::post('/templates/{template}/tasks', [OnboardingTemplateController::class, 'storeTask'])->name('templates.tasks.store');
        Route::delete('/templates/{template}/tasks/{task}', [OnboardingTemplateController::class, 'destroyTask'])->name('templates.tasks.destroy');

        // Documents
        Route::get('/persons/{person}/documents', [OnboardingDocumentController::class, 'index'])->name('documents.index');
        Route::post('/persons/{person}/documents', [OnboardingDocumentController::class, 'store'])->name('documents.store');
        Route::post('/persons/{person}/documents/{document}/upload', [OnboardingDocumentController::class, 'upload'])->name('documents.upload');
        Route::get('/persons/{person}/documents/{document}/download', [OnboardingDocumentController::class, 'download'])->name('documents.download');
        Route::post('/persons/{person}/documents/{document}/approve', [OnboardingDocumentController::class, 'approve'])->name('documents.approve');
        Route::post('/persons/{person}/documents/{document}/reject', [OnboardingDocumentController::class, 'reject'])->name('documents.reject');
        Route::delete('/persons/{person}/documents/{document}', [OnboardingDocumentController::class, 'destroy'])->name('documents.destroy');

        // Provisioning tasks
        Route::get('/persons/{person}/provisioning', [OnboardingProvisioningController::class, 'index'])->name('provisioning.index');
        Route::post('/persons/{person}/provisioning', [OnboardingProvisioningController::class, 'store'])->name('provisioning.store');
        Route::patch('/persons/{person}/provisioning/{task}/status', [OnboardingProvisioningController::class, 'updateStatus'])->name('provisioning.status');
        Route::delete('/persons/{person}/provisioning/{task}', [OnboardingProvisioningController::class, 'destroy'])->name('provisioning.destroy');

        // Probation
        Route::get('/persons/{person}/probation', [ProbationController::class, 'show'])->name('probation.show');
        Route::post('/persons/{person}/probation', [ProbationController::class, 'store'])->name('probation.store');
        Route::post('/persons/{person}/probation/{probation}/review', [ProbationController::class, 'review'])->name('probation.review');
        Route::post('/persons/{person}/probation/{probation}/close', [ProbationController::class, 'close'])->name('probation.close');
    });
});
