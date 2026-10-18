<?php

use App\Modules\Assets\Http\Controllers\AssetApprovalController;
use App\Modules\Assets\Http\Controllers\AssetAssignmentController;
use App\Modules\Assets\Http\Controllers\AssetAuditController;
use App\Modules\Assets\Http\Controllers\AssetBarcodeController;
use App\Modules\Assets\Http\Controllers\AssetController;
use App\Modules\Assets\Http\Controllers\AssetImportController;
use App\Modules\Assets\Http\Controllers\AssetPartController;
use App\Modules\Assets\Http\Controllers\AssetWorkOrderController;
use App\Modules\Assets\Http\Controllers\AssetDashboardController;
use App\Modules\Assets\Http\Controllers\AssetIncidentController;
use App\Modules\Assets\Http\Controllers\AssetMaintenanceController;
use App\Modules\Assets\Http\Controllers\AssetMovementController;
use App\Modules\Assets\Http\Controllers\AssetPerformanceController;
use App\Modules\Assets\Http\Controllers\AssetAnalyticsController;
use App\Modules\Assets\Http\Controllers\AssetReportsController;
use App\Modules\Assets\Http\Controllers\AssetScheduledReportController;
use App\Modules\Assets\Http\Controllers\AssetSettingsController;
use App\Modules\Assets\Http\Controllers\ComplianceController;
use App\Modules\Assets\Http\Controllers\DepreciationController;
use App\Modules\Assets\Http\Controllers\DocumentExtractionController;
use App\Modules\Assets\Http\Controllers\ExitClearanceController;
use App\Modules\Assets\Http\Controllers\MisuseFlagController;
use App\Modules\Assets\Http\Controllers\PurchaseRequestController;
use App\Modules\Assets\Http\Controllers\UtilizationReportController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'module.active:asset_management', 'module.tier:asset_management'])->prefix('assets')->name('assets.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['register-hub' => 'register', 'maintenance-hub' => 'maintenance', 'finance-hub' => 'finance', 'insights-hub' => 'insights', 'configure-hub' => 'configure'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'asset_management')->defaults('group', $group)->name('hub.' . $group);
    }

    // ── Analytics ─────────────────────────────────────────────────────────────
    Route::get('/analytics', [AssetAnalyticsController::class, 'index'])->name('analytics');

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::get('/reports',                                   [AssetReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/generate/{reportKey}',              [AssetReportsController::class, 'generate'])->name('reports.generate');
    Route::get('/reports/export/{reportKey}/{format}',       [AssetReportsController::class, 'export'])->name('reports.export');

    // ── Settings ──────────────────────────────────────────────────────────────
    // Whole settings surface (taxonomy CRUD + numbering/threshold config) is gated
    // behind assets.settings — previously these mutating routes were ungated, so
    // any module user could rewrite/delete the taxonomy that assets FK into.
    Route::middleware('crud.permissions:assets.settings')->group(function () {
        Route::get('/settings',                                          [AssetSettingsController::class, 'index'])->name('settings.index');
        Route::patch('/settings',                                        [AssetSettingsController::class, 'update'])->name('settings.update');

        Route::get('/settings/categories',                               [AssetSettingsController::class, 'categoriesIndex'])->name('settings.categories.index');
        Route::post('/settings/categories',                              [AssetSettingsController::class, 'categoriesStore'])->name('settings.categories.store');
        Route::patch('/settings/categories/{category:uuid}',             [AssetSettingsController::class, 'categoriesUpdate'])->name('settings.categories.update');
        Route::delete('/settings/categories/{category:uuid}',            [AssetSettingsController::class, 'categoriesDestroy'])->name('settings.categories.destroy');

        Route::get('/settings/types',                                    [AssetSettingsController::class, 'typesIndex'])->name('settings.types.index');
        Route::post('/settings/types',                                   [AssetSettingsController::class, 'typesStore'])->name('settings.types.store');
        Route::patch('/settings/types/{type}',                           [AssetSettingsController::class, 'typesUpdate'])->name('settings.types.update');
        Route::delete('/settings/types/{type}',                          [AssetSettingsController::class, 'typesDestroy'])->name('settings.types.destroy');

        Route::get('/settings/locations',                                [AssetSettingsController::class, 'locationsIndex'])->name('settings.locations.index');
        Route::post('/settings/locations',                               [AssetSettingsController::class, 'locationsStore'])->name('settings.locations.store');
        Route::patch('/settings/locations/{location:uuid}',              [AssetSettingsController::class, 'locationsUpdate'])->name('settings.locations.update');
        Route::delete('/settings/locations/{location:uuid}',             [AssetSettingsController::class, 'locationsDestroy'])->name('settings.locations.destroy');

        Route::get('/settings/makes',                                    [AssetSettingsController::class, 'makesIndex'])->name('settings.makes.index');
        Route::post('/settings/makes',                                   [AssetSettingsController::class, 'makesStore'])->name('settings.makes.store');
        Route::patch('/settings/makes/{make:uuid}',                      [AssetSettingsController::class, 'makesUpdate'])->name('settings.makes.update');
        Route::delete('/settings/makes/{make:uuid}',                     [AssetSettingsController::class, 'makesDestroy'])->name('settings.makes.destroy');
        Route::post('/settings/makes/{make:uuid}/models',                [AssetSettingsController::class, 'modelsStore'])->name('settings.models.store');
        Route::patch('/settings/models/{model:uuid}',                    [AssetSettingsController::class, 'modelsUpdate'])->name('settings.models.update');
        Route::delete('/settings/models/{model:uuid}',                   [AssetSettingsController::class, 'modelsDestroy'])->name('settings.models.destroy');
    });

    Route::get('/settings/scheduled-reports',                        [AssetScheduledReportController::class, 'index'])->name('settings.scheduled-reports.index');
    Route::post('/settings/scheduled-reports',                       [AssetScheduledReportController::class, 'store'])->name('settings.scheduled-reports.store')->middleware('crud.permissions:assets.settings');
    Route::patch('/settings/scheduled-reports/{scheduledReport}',    [AssetScheduledReportController::class, 'update'])->name('settings.scheduled-reports.update')->middleware('crud.permissions:assets.settings');
    Route::delete('/settings/scheduled-reports/{scheduledReport}',   [AssetScheduledReportController::class, 'destroy'])->name('settings.scheduled-reports.destroy')->middleware('crud.permissions:assets.settings');

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/dashboard',                       [AssetDashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/dashboard/feed',                  [AssetDashboardController::class, 'feed'])->name('dashboard.feed');
    Route::get('/dashboard/widgets/{widget}',      [AssetDashboardController::class, 'widget'])->name('dashboard.widget');
    // AI/LLM endpoints — throttled to curb paid-call abuse (they hit the LLM).
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('/ai/dashboard-summary',           [AssetDashboardController::class, 'aiDashboardSummary'])->name('ai.dashboard-summary');
        Route::post('/ai/risk-summary/{asset}',        [AssetDashboardController::class, 'aiRiskSummary'])->name('ai.risk-summary');
        Route::post('/ai/lifecycle/{asset}',           [AssetDashboardController::class, 'aiLifecycle'])->name('ai.lifecycle');
        Route::post('/ai/replacement/{asset}',         [AssetDashboardController::class, 'aiReplacement'])->name('ai.replacement');
        Route::post('/ai/maintenance/{asset}',         [AssetDashboardController::class, 'aiMaintenance'])->name('ai.maintenance');
    });

    // ── Global maintenance dashboard ──────────────────────────────────────────
    Route::get('/maintenance', [AssetMaintenanceController::class, 'index'])->name('maintenance.index');

    // ── Asset CRUD ────────────────────────────────────────────────────────────
    Route::get('/',       [AssetController::class, 'index'])->name('index');
    Route::get('/create', [AssetController::class, 'create'])->name('create');
    Route::post('/',      [AssetController::class, 'store'])->name('store')->middleware('crud.permissions:assets.create');

    // ── Depreciation ──────────────────────────────────────────────────────────
    Route::get('/depreciation',                              [DepreciationController::class, 'index'])->name('depreciation.index');
    Route::get('/depreciation/{asset}',                      [DepreciationController::class, 'show'])->name('depreciation.show');
    Route::post('/depreciation/{asset}',                     [DepreciationController::class, 'store'])->name('depreciation.store')->middleware('crud.permissions:assets.depreciation');
    Route::delete('/depreciation/schedules/{schedule}',      [DepreciationController::class, 'destroy'])->name('depreciation.destroy')->middleware('crud.permissions:assets.depreciation');
    Route::post('/depreciation/entries/{entry}/post',        [DepreciationController::class, 'postEntry'])->name('depreciation.entries.post')->middleware('crud.permissions:assets.depreciation');

    // ── Exit Clearances ───────────────────────────────────────────────────────
    Route::get('/exit-clearances',                                          [ExitClearanceController::class, 'index'])->name('exit-clearances.index');
    Route::get('/exit-clearances/create',                                   [ExitClearanceController::class, 'create'])->name('exit-clearances.create');
    Route::post('/exit-clearances',                                         [ExitClearanceController::class, 'store'])->name('exit-clearances.store')->middleware('crud.permissions:assets.exit-clearances');
    Route::get('/exit-clearances/{exitClearance}',                          [ExitClearanceController::class, 'show'])->name('exit-clearances.show');
    Route::delete('/exit-clearances/{exitClearance}',                       [ExitClearanceController::class, 'destroy'])->name('exit-clearances.destroy')->middleware('crud.permissions:assets.exit-clearances');
    Route::patch('/exit-clearances/{exitClearance}/items/{item}',           [ExitClearanceController::class, 'resolveItem'])->name('exit-clearances.items.resolve')->middleware('crud.permissions:assets.exit-clearances');

    // ── Misuse Detection ──────────────────────────────────────────────────────
    Route::get('/misuse-flags',                          [MisuseFlagController::class, 'index'])->name('misuse-flags.index');
    Route::post('/misuse-flags',                         [MisuseFlagController::class, 'store'])->name('misuse-flags.store')->middleware('crud.permissions:assets.misuse-flags');
    Route::get('/misuse-flags/{misuseFlag}',             [MisuseFlagController::class, 'show'])->name('misuse-flags.show');
    Route::patch('/misuse-flags/{misuseFlag}',           [MisuseFlagController::class, 'update'])->name('misuse-flags.update')->middleware('crud.permissions:assets.misuse-flags');
    Route::delete('/misuse-flags/{misuseFlag}',          [MisuseFlagController::class, 'destroy'])->name('misuse-flags.destroy')->middleware('crud.permissions:assets.misuse-flags');
    Route::post('/misuse-detection/run',                 [MisuseFlagController::class, 'runDetection'])->name('misuse-detection.run')->middleware('crud.permissions:assets.misuse-flags');
    Route::get('/misuse-detection-rules',                [MisuseFlagController::class, 'rules'])->name('misuse-detection-rules.index');
    Route::post('/misuse-detection-rules',               [MisuseFlagController::class, 'storeRule'])->name('misuse-detection-rules.store')->middleware('crud.permissions:assets.misuse-flags');
    Route::patch('/misuse-detection-rules/{rule}',       [MisuseFlagController::class, 'updateRule'])->name('misuse-detection-rules.update')->middleware('crud.permissions:assets.misuse-flags');
    Route::delete('/misuse-detection-rules/{rule}',      [MisuseFlagController::class, 'destroyRule'])->name('misuse-detection-rules.destroy')->middleware('crud.permissions:assets.misuse-flags');

    // ── Document Extractions ──────────────────────────────────────────────────
    Route::get('/document-extractions',                                     [DocumentExtractionController::class, 'index'])->name('document-extractions.index');
    Route::get('/document-extractions/create',                              [DocumentExtractionController::class, 'create'])->name('document-extractions.create');
    Route::post('/document-extractions',                                    [DocumentExtractionController::class, 'store'])->name('document-extractions.store')->middleware('crud.permissions:assets.document-extractions');
    Route::get('/document-extractions/{documentExtraction}',                [DocumentExtractionController::class, 'show'])->name('document-extractions.show');
    Route::post('/document-extractions/{documentExtraction}/confirm',       [DocumentExtractionController::class, 'confirm'])->name('document-extractions.confirm')->middleware('crud.permissions:assets.document-extractions');
    Route::post('/document-extractions/{documentExtraction}/reject',        [DocumentExtractionController::class, 'reject'])->name('document-extractions.reject')->middleware('crud.permissions:assets.document-extractions');

    // ── Utilization Reports ───────────────────────────────────────────────────
    Route::get('/utilization-reports',                      [UtilizationReportController::class, 'index'])->name('utilization-reports.index');
    Route::get('/utilization-reports/create',               [UtilizationReportController::class, 'create'])->name('utilization-reports.create');
    Route::post('/utilization-reports',                     [UtilizationReportController::class, 'store'])->name('utilization-reports.store')->middleware('crud.permissions:assets.utilization-reports');
    Route::get('/utilization-reports/{utilizationReport}',  [UtilizationReportController::class, 'show'])->name('utilization-reports.show');
    Route::get('/utilization-dashboard',                    [UtilizationReportController::class, 'dashboard'])->name('utilization-dashboard.index');

    // ── Compliance ────────────────────────────────────────────────────────────
    Route::get('/compliance-dashboard',                  [ComplianceController::class, 'dashboard'])->name('compliance-dashboard.index');
    Route::get('/compliance-rules',                      [ComplianceController::class, 'rulesIndex'])->name('compliance-rules.index');
    Route::post('/compliance-rules',                     [ComplianceController::class, 'storeRule'])->name('compliance-rules.store')->middleware('crud.permissions:assets.compliance-rules');
    Route::patch('/compliance-rules/{rule}',             [ComplianceController::class, 'updateRule'])->name('compliance-rules.update')->middleware('crud.permissions:assets.compliance-rules');
    Route::delete('/compliance-rules/{rule}',            [ComplianceController::class, 'destroyRule'])->name('compliance-rules.destroy')->middleware('crud.permissions:assets.compliance-rules');
    Route::get('/compliance-runs',                       [ComplianceController::class, 'runsIndex'])->name('compliance-runs.index');
    Route::post('/compliance-runs',                      [ComplianceController::class, 'storeRun'])->name('compliance-runs.store')->middleware('crud.permissions:assets.compliance-rules');
    Route::get('/compliance-runs/{complianceRun}',       [ComplianceController::class, 'showRun'])->name('compliance-runs.show');

    // ── Procurement ───────────────────────────────────────────────────────────
    Route::get('/procurement',                                          [PurchaseRequestController::class, 'index'])->name('procurement.index');
    Route::get('/procurement/create',                                   [PurchaseRequestController::class, 'create'])->name('procurement.create');
    Route::post('/procurement',                                         [PurchaseRequestController::class, 'store'])->name('procurement.store')->middleware('crud.permissions:assets.procurement');
    Route::get('/procurement/{purchaseRequest}',                        [PurchaseRequestController::class, 'show'])->name('procurement.show');
    Route::patch('/procurement/{purchaseRequest}',                      [PurchaseRequestController::class, 'update'])->name('procurement.update')->middleware('crud.permissions:assets.procurement');
    Route::delete('/procurement/{purchaseRequest}',                     [PurchaseRequestController::class, 'destroy'])->name('procurement.destroy')->middleware('crud.permissions:assets.procurement');
    Route::post('/procurement/{purchaseRequest}/submit',                [PurchaseRequestController::class, 'submit'])->name('procurement.submit')->middleware('crud.permissions:assets.procurement');
    Route::post('/procurement/{purchaseRequest}/deliver',               [PurchaseRequestController::class, 'deliver'])->name('procurement.deliver')->middleware('crud.permissions:assets.procurement');
    Route::post('/procurement/{purchaseRequest}/create-assets',         [PurchaseRequestController::class, 'createAssets'])->name('procurement.create-assets')->middleware('crud.permissions:assets.procurement');

    // ── Barcode / QR: scan-to-find + printable labels ─────────────────────────
    Route::middleware('crud.permissions:assets.view')->group(function () {
        Route::get('/scan',                             [AssetBarcodeController::class, 'scanPage'])->name('scan');
        Route::post('/scan/resolve',                    [AssetBarcodeController::class, 'resolve'])->name('scan.resolve');
        Route::get('/labels/sheet',                     [AssetBarcodeController::class, 'bulkLabels'])->name('labels.sheet');
        Route::get('/labels/{asset}',                   [AssetBarcodeController::class, 'label'])->name('labels.show');
        Route::post('/labels/{asset}/generate-barcode', [AssetBarcodeController::class, 'generateBarcode'])->name('labels.generate')->middleware('crud.permissions:assets.edit');
    });

    // ── Bulk import ───────────────────────────────────────────────────────────
    Route::get('/import',           [AssetImportController::class, 'create'])->name('import.index');
    Route::get('/import/template',  [AssetImportController::class, 'template'])->name('import.template');
    Route::post('/import',          [AssetImportController::class, 'store'])->name('import.store')->middleware('crud.permissions:assets.create');

    // ── Physical Audit / Stock-take ───────────────────────────────────────────
    Route::get('/audits',              [AssetAuditController::class, 'index'])->name('audits.index');
    Route::get('/audits/create',       [AssetAuditController::class, 'create'])->name('audits.create');
    Route::get('/audits/{audit:uuid}', [AssetAuditController::class, 'show'])->name('audits.show');
    Route::middleware('crud.permissions:assets.audits')->group(function () {
        Route::post('/audits',                       [AssetAuditController::class, 'store'])->name('audits.store');
        Route::post('/audits/{audit:uuid}/scan',     [AssetAuditController::class, 'scan'])->name('audits.scan');
        Route::post('/audits/{audit:uuid}/complete', [AssetAuditController::class, 'complete'])->name('audits.complete');
        Route::delete('/audits/{audit:uuid}',        [AssetAuditController::class, 'destroy'])->name('audits.destroy');
    });

    // ── Work Orders ───────────────────────────────────────────────────────────
    Route::get('/work-orders',               [AssetWorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('/work-orders/create',        [AssetWorkOrderController::class, 'create'])->name('work-orders.create');
    Route::get('/work-orders/{workOrder}',   [AssetWorkOrderController::class, 'show'])->name('work-orders.show');
    Route::middleware('crud.permissions:assets.work-orders')->group(function () {
        Route::post('/work-orders',                                 [AssetWorkOrderController::class, 'store'])->name('work-orders.store');
        Route::patch('/work-orders/{workOrder}',                    [AssetWorkOrderController::class, 'update'])->name('work-orders.update');
        Route::post('/work-orders/{workOrder}/tasks',               [AssetWorkOrderController::class, 'addTask'])->name('work-orders.tasks.store');
        Route::post('/work-orders/{workOrder}/tasks/{task:uuid}/toggle', [AssetWorkOrderController::class, 'toggleTask'])->name('work-orders.tasks.toggle');
        Route::delete('/work-orders/{workOrder}/tasks/{task:uuid}',  [AssetWorkOrderController::class, 'removeTask'])->name('work-orders.tasks.destroy');
        Route::post('/work-orders/{workOrder}/parts',               [AssetWorkOrderController::class, 'addPart'])->name('work-orders.parts.store');
        Route::delete('/work-orders/{workOrder}/parts/{part:uuid}', [AssetWorkOrderController::class, 'removePart'])->name('work-orders.parts.destroy');
        Route::post('/work-orders/{workOrder}/labor',               [AssetWorkOrderController::class, 'setLabor'])->name('work-orders.labor');
        Route::post('/work-orders/{workOrder}/start',               [AssetWorkOrderController::class, 'start'])->name('work-orders.start');
        Route::post('/work-orders/{workOrder}/hold',                [AssetWorkOrderController::class, 'hold'])->name('work-orders.hold');
        Route::post('/work-orders/{workOrder}/resume',              [AssetWorkOrderController::class, 'resume'])->name('work-orders.resume');
        Route::post('/work-orders/{workOrder}/complete',            [AssetWorkOrderController::class, 'complete'])->name('work-orders.complete');
        Route::post('/work-orders/{workOrder}/cancel',              [AssetWorkOrderController::class, 'cancel'])->name('work-orders.cancel');
    });

    // ── Spare Parts / MRO Inventory ───────────────────────────────────────────
    Route::prefix('parts')->name('parts.')->group(function () {
        Route::get('/',          [AssetPartController::class, 'index'])->name('index');
        Route::get('/create',    [AssetPartController::class, 'create'])->name('create');
        Route::get('/low-stock', [AssetPartController::class, 'lowStock'])->name('low-stock');
        Route::get('/{part}',    [AssetPartController::class, 'show'])->name('show');
        Route::middleware('crud.permissions:assets.parts')->group(function () {
            Route::post('/',              [AssetPartController::class, 'store'])->name('store');
            Route::patch('/{part}',       [AssetPartController::class, 'update'])->name('update');
            Route::post('/{part}/receive', [AssetPartController::class, 'receive'])->name('receive');
            Route::post('/{part}/issue',   [AssetPartController::class, 'issue'])->name('issue');
            Route::post('/{part}/adjust',  [AssetPartController::class, 'adjust'])->name('adjust');
        });
    });

    // ── Approvals (assignment / procurement queue) ────────────────────────────
    Route::get('/approvals',                    [AssetApprovalController::class, 'index'])->name('approvals.index');
    Route::get('/approvals/pending',            [AssetApprovalController::class, 'pending'])->name('approvals.pending');
    Route::get('/approvals/{approval:uuid}',    [AssetApprovalController::class, 'show'])->name('approvals.show');
    Route::post('/approvals/{approval:uuid}/act', [AssetApprovalController::class, 'act'])->name('approvals.act')->middleware('crud.permissions:assets.approvals');

    // ── Asset show/update (wildcard — must stay last) ─────────────────────────
    Route::get('/{asset}',   [AssetController::class, 'show'])->name('show');
    Route::patch('/{asset}', [AssetController::class, 'update'])->name('update')->middleware('crud.permissions:assets.edit');

    // ── Per-asset sub-resources (also after wildcard is fine — more segments) ──
    Route::post('/{asset}/assign', [AssetAssignmentController::class, 'store'])->name('assign')->middleware('crud.permissions:assets.assign');
    // Handing it back. Assets could be issued and never returned: the only way to release one was
    // to assign it to somebody else, so a laptop handed in at the end of a project stayed on its
    // holder's record — and on the exit-clearance report.
    Route::post('/{asset}/assignments/{assignment}/return', [AssetAssignmentController::class, 'returnAsset'])
        ->name('assignment.return')
        ->middleware('crud.permissions:assets.assignment.return');
    Route::get('/{asset}/maintenance/{schedule}',           [AssetMaintenanceController::class, 'show'])->name('maintenance.show');
    Route::post('/{asset}/maintenance',                     [AssetMaintenanceController::class, 'store'])->name('maintenance.store')->middleware('crud.permissions:assets.maintenance');
    Route::post('/{asset}/maintenance/{schedule}/complete', [AssetMaintenanceController::class, 'complete'])->name('maintenance.complete')->middleware('crud.permissions:assets.maintenance');
    Route::post('/{asset}/incidents',                       [AssetIncidentController::class, 'store'])->name('incidents.store')->middleware('crud.permissions:assets.incidents');
    Route::patch('/{asset}/incidents/{incident}/resolve',   [AssetIncidentController::class, 'resolve'])->name('incidents.resolve')->middleware('crud.permissions:assets.incidents');
    Route::post('/{asset}/movements',                       [AssetMovementController::class, 'store'])->name('movements.store')->middleware('crud.permissions:assets.movements');
    Route::post('/{asset}/performance',                     [AssetPerformanceController::class, 'store'])->name('performance.store')->middleware('crud.permissions:assets.performance');
});
