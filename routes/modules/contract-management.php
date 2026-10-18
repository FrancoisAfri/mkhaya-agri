<?php

use App\Modules\ContractManagement\Controllers\ContractAiController;
use App\Modules\ContractManagement\Controllers\ContractAnalyticsController;
use App\Modules\ContractManagement\Controllers\ContractClauseController;
use App\Modules\ContractManagement\Controllers\ContractScheduledReportController;
use App\Modules\ContractManagement\Controllers\ContractSearchController;
use App\Modules\ContractManagement\Controllers\ContractTemplateController;
use App\Modules\ContractManagement\Controllers\ProcurementCopilotController;
use App\Modules\ContractManagement\Controllers\ContractApprovalController;
use App\Modules\ContractManagement\Controllers\ContractAppraisalController;
use App\Modules\ContractManagement\Controllers\ContractController;
use App\Modules\ContractManagement\Controllers\ContractDashboardController;
use App\Modules\ContractManagement\Controllers\ContractReportController;
use App\Modules\ContractManagement\Controllers\ContractRequestController;
use App\Modules\ContractManagement\Controllers\ContractSettingsController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

// Auth + crud.permissions already applied by the parent group in web.php.
Route::middleware(['module.active:contract_management', 'module.tier:contract_management'])->prefix('contracts')->name('contracts.')->group(function () {

    // ── Dashboard ──────────────────────────────────────────────────────────────
    Route::get('/', [ContractDashboardController::class, 'index'])->name('dashboard');

    // ── Hub landing pages (role-tiered feature-card grids) ──────────────────────
    foreach (['register', 'workflow', 'insights', 'configure'] as $group) {
        Route::get("/{$group}-hub", [ModuleHubController::class, 'show'])
            ->defaults('module', 'contract_management')->defaults('group', $group)->name("hub.{$group}");
    }

    // ── Procurement AI Copilot (cross-module: contracts + suppliers + compliance) ─
    Route::get('/copilot',      [ProcurementCopilotController::class, 'index'])->name('copilot');
    Route::post('/copilot/ask', [ProcurementCopilotController::class, 'ask'])->name('copilot.ask');

    // ── Contract requests (requisitions) ────────────────────────────────────────
    Route::prefix('requests')->name('requests.')->group(function () {
        Route::get('/',              [ContractRequestController::class, 'index'])->name('index');
        Route::get('/create',        [ContractRequestController::class, 'create'])->name('create');
        Route::post('/',             [ContractRequestController::class, 'store'])->name('store');
        Route::get('/{id}',          [ContractRequestController::class, 'show'])->name('show')->whereNumber('id');
        Route::post('/{id}/approve', [ContractRequestController::class, 'approve'])->name('approve')->whereNumber('id');
        Route::post('/{id}/reject',  [ContractRequestController::class, 'reject'])->name('reject')->whereNumber('id');
    });

    // ── Approvals + step configuration ──────────────────────────────────────────
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/',                      [ContractApprovalController::class, 'index'])->name('index');
        Route::post('/{contract}/approve',   [ContractApprovalController::class, 'approve'])->name('approve');
        Route::post('/{contract}/decline',   [ContractApprovalController::class, 'decline'])->name('decline');
        Route::post('/steps',                [ContractApprovalController::class, 'stepsStore'])->name('steps.store');
        Route::patch('/steps/{step}',        [ContractApprovalController::class, 'stepUpdate'])->name('steps.update');
        Route::delete('/steps/{step}',       [ContractApprovalController::class, 'stepDestroy'])->name('steps.destroy');
    });

    // ── Appraisals ──────────────────────────────────────────────────────────────
    Route::prefix('appraisals')->name('appraisals.')->group(function () {
        Route::get('/',                      [ContractAppraisalController::class, 'index'])->name('index');
        Route::post('/templates',            [ContractAppraisalController::class, 'templatesStore'])->name('templates.store');
        Route::get('/{contract}/appraise',   [ContractAppraisalController::class, 'appraiseForm'])->name('appraise');
        Route::post('/{contract}/appraise',  [ContractAppraisalController::class, 'store'])->name('store');
    });

    // ── Reports ──────────────────────────────────────────────────────────────────
    Route::get('/reports', [ContractReportController::class, 'index'])->name('reports');
    Route::get('/reports/export/{key}/{format}', [ContractReportController::class, 'export'])->name('reports.export');

    // ── Analytics (BI) ─────────────────────────────────────────────────────────────
    Route::get('/analytics', [ContractAnalyticsController::class, 'index'])->name('analytics');

    // ── Scheduled reports ──────────────────────────────────────────────────────────
    Route::prefix('scheduled-reports')->name('scheduled-reports.')->group(function () {
        Route::get('/',                    [ContractScheduledReportController::class, 'index'])->name('index');
        Route::post('/',                   [ContractScheduledReportController::class, 'store'])->name('store');
        Route::patch('/{scheduledReport}', [ContractScheduledReportController::class, 'update'])->name('update')->whereNumber('scheduledReport');
        Route::delete('/{scheduledReport}', [ContractScheduledReportController::class, 'destroy'])->name('destroy')->whereNumber('scheduledReport');
    });

    // ── Settings (types + categories + templates CRUD) ──────────────────────────
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [ContractSettingsController::class, 'index'])->name('index');

        Route::post('/types',               [ContractSettingsController::class, 'typesStore'])->name('types.store');
        Route::patch('/types/{type}',       [ContractSettingsController::class, 'typesUpdate'])->name('types.update');
        Route::delete('/types/{type}',      [ContractSettingsController::class, 'typesDestroy'])->name('types.destroy');

        Route::post('/categories',              [ContractSettingsController::class, 'categoriesStore'])->name('categories.store');
        Route::patch('/categories/{category}',  [ContractSettingsController::class, 'categoriesUpdate'])->name('categories.update');
        Route::delete('/categories/{category}', [ContractSettingsController::class, 'categoriesDestroy'])->name('categories.destroy');

        Route::post('/templates',               [ContractSettingsController::class, 'templatesStore'])->name('templates.store');
        Route::patch('/templates/{template}',   [ContractSettingsController::class, 'templatesUpdate'])->name('templates.update');
        Route::delete('/templates/{template}',  [ContractSettingsController::class, 'templatesDestroy'])->name('templates.destroy');
    });

    // ── Contract templates ──────────────────────────────────────────────────────
    Route::prefix('templates')->name('templates.')->group(function () {
        Route::get('/',            [ContractTemplateController::class, 'index'])->name('index');
        Route::post('/',           [ContractTemplateController::class, 'store'])->name('store');
        Route::patch('/{template}', [ContractTemplateController::class, 'update'])->name('update')->whereNumber('template');
        Route::delete('/{template}', [ContractTemplateController::class, 'destroy'])->name('destroy')->whereNumber('template');
    });

    // ── Bulk upload (many contracts; background-processed) ───────────────────────
    Route::get('/bulk-upload',  [ContractController::class, 'bulkUploadForm'])->name('bulk-upload');
    Route::post('/bulk-upload', [ContractController::class, 'bulkUpload'])->name('bulk-upload.store');

    // ── Clause library + playbook-deviation analysis ────────────────────────────
    Route::prefix('clauses')->name('clauses.')->group(function () {
        Route::get('/',            [ContractClauseController::class, 'index'])->name('index');
        Route::post('/',           [ContractClauseController::class, 'store'])->name('store');
        Route::patch('/{clause}',  [ContractClauseController::class, 'update'])->name('update')->whereNumber('clause');
        Route::delete('/{clause}', [ContractClauseController::class, 'destroy'])->name('destroy')->whereNumber('clause');
    });

    // ── Contracts (list / CRUD / lifecycle) ─────────────────────────────────────
    // Static segments first to avoid being captured by GET /{contract}.
    Route::get('/list',          [ContractController::class, 'index'])->name('index');
    Route::get('/search',        [ContractSearchController::class, 'index'])->name('search');
    Route::get('/create',        [ContractController::class, 'create'])->name('create');
    Route::post('/',             [ContractController::class, 'store'])->name('store');
    Route::get('/trash',         [ContractController::class, 'trash'])->name('trash');
    Route::post('/{id}/restore', [ContractController::class, 'restore'])->name('restore')->whereNumber('id');
    Route::delete('/{id}/force', [ContractController::class, 'forceDelete'])->name('force-delete')->whereNumber('id');

    Route::get('/{contract}/document',     [ContractController::class, 'download'])->name('document')->whereUuid('contract');
    Route::get('/{contract}',              [ContractController::class, 'show'])->name('show')->whereUuid('contract');
    Route::patch('/{contract}',            [ContractController::class, 'update'])->name('update')->whereUuid('contract');
    Route::delete('/{contract}',           [ContractController::class, 'destroy'])->name('destroy')->whereUuid('contract');
    Route::post('/{contract}/publish',     [ContractController::class, 'publish'])->name('publish')->whereUuid('contract');
    Route::patch('/{contract}/status',     [ContractController::class, 'updateStatus'])->name('status')->whereUuid('contract');
    Route::post('/{contract}/terminate',   [ContractController::class, 'terminate'])->name('terminate')->whereUuid('contract');
    Route::post('/{contract}/renew',       [ContractController::class, 'renew'])->name('renew')->whereUuid('contract');
    Route::post('/{contract}/sign',        [ContractController::class, 'sendForSignature'])->name('sign')->whereUuid('contract');
    Route::post('/{contract}/analyze',     [ContractAiController::class, 'analyze'])->name('analyze')->whereUuid('contract');
    Route::post('/{contract}/renewal-brief', [ContractAiController::class, 'renewalBrief'])->name('renewal-brief')->whereUuid('contract');
    Route::post('/{contract}/analyze-clauses', [ContractClauseController::class, 'analyze'])->name('analyze-clauses')->whereUuid('contract');
    Route::get('/{contract}/deviation',    [ContractClauseController::class, 'deviation'])->name('deviation')->whereUuid('contract');

    // Obligations register
    Route::post('/{contract}/obligations',                [ContractController::class, 'obligationStore'])->name('obligations.store')->whereUuid('contract');
    Route::patch('/{contract}/obligations/{obligation}',  [ContractController::class, 'obligationUpdate'])->name('obligations.update')->whereUuid('contract');
    Route::delete('/{contract}/obligations/{obligation}', [ContractController::class, 'obligationDestroy'])->name('obligations.destroy')->whereUuid('contract');
});
