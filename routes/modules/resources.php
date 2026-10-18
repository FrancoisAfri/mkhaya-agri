<?php

use App\Modules\Communication\Resources\Controller\CorporateBrandingController;
use App\Modules\Communication\Resources\Controller\ResourceReportController;
use App\Modules\Communication\Resources\Controller\ResourcesController;
use App\Modules\Dashboard\Controller\DashbaordController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module.active:resources'])->group(function () {
    /**CEO MESSAGE */
    Route::get('cms/corporate-branding', [DashbaordController::class, 'corporateBranding'])->name('cms.corporateBranding');
    Route::post('corporate-branding/store', [CorporateBrandingController::class, 'store'])->name('corporateBranding.store');
    Route::put('corporate-branding/update/{id}', [CorporateBrandingController::class, 'update'])->name('corporateBranding.update');
    /**Resources */
    Route::get('cms/forms', [DashbaordController::class, 'forms'])->name('cms.forms');
    Route::get('cms/nda-legal', [DashbaordController::class, 'legalDocuments'])->name('cms.legal');
    Route::get('cms/employee-handbook', [DashbaordController::class, 'handbooks'])->name('cms.handbooks');
    Route::get('/cms/health-safety', [DashbaordController::class, 'healthSafety'])->name('cms.healthSafety');
    Route::get('cms/templates', [DashbaordController::class, 'templates'])->name('cms.templates');
    Route::get('cms/training-material', [DashbaordController::class, 'trainingMaterials'])->name('cms.trainingMaterials');
    Route::get('cms/sops', [DashbaordController::class, 'sops'])->name('cms.sops');
    Route::get('cms/downloads', [DashbaordController::class, 'downloads'])->name('cms.downloads');

    Route::post('resources/store', [ResourcesController::class, 'store'])->name('resource.store');
    Route::put('resources/update/{id}', [ResourcesController::class, 'update'])->name('resource.update');
    Route::get('cms/dashaboard-resources', [ResourcesController::class, 'index'])->name('resource.dashboard');
    Route::get('cms/reports-resources', [ResourceReportController::class, 'index'])->name('resource.report');
    Route::get('/resource-reports/generate/{reportKey}',          [ResourceReportController::class, 'generate'])
        ->name('generate');
    Route::get('/resource-reports/export/{reportKey}/{format}',   [ResourceReportController::class, 'export'])
        ->name('export');
});
