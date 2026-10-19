<?php

use App\Modules\Agri\Http\Controllers\AgriAssistantController;
use App\Modules\Agri\Http\Controllers\AgriDashboardController;
use App\Modules\Agri\Http\Controllers\AgriReportController;
use App\Modules\Agri\Http\Controllers\AgriSettingsController;
use App\Modules\Agri\Http\Controllers\CropController;
use App\Modules\Agri\Http\Controllers\FarmAlertController;
use App\Modules\Agri\Http\Controllers\FarmDocumentController;
use App\Modules\Agri\Http\Controllers\FarmFinanceController;
use App\Modules\Agri\Http\Controllers\FarmStockController;
use App\Modules\Agri\Http\Controllers\FarmTaskController;
use App\Modules\Agri\Http\Controllers\LivestockController;
use App\Modules\Agri\Http\Controllers\MachineryController;
use App\Modules\Agri\Http\Controllers\MyWorkController;
use App\Modules\Agri\Http\Controllers\FarmController;
use App\Modules\Agri\Http\Controllers\FarmSetupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mkhaya Agri
|--------------------------------------------------------------------------
|
| Gated by module entitlement (module.active) and hub tier (module.tier); each action then checks
| its agri.* permission, and every farm-owned model is confined to the farms the user may reach,
| so a farm outside the user's scope is a 404 rather than a 403 that confirms it exists.
|
| Route binding is on uuid throughout.
*/
Route::middleware(['module.active:agri', 'module.tier:agri'])
    ->prefix('agri')->name('agri.')
    ->group(function () {
        Route::get('/', [AgriDashboardController::class, 'index'])->name('dashboard');

        // ── Module A: farms and what lives under them ────────────────────────────────────────
        Route::get('/farms', [FarmController::class, 'index'])->name('farms.index');
        Route::post('/farms', [FarmController::class, 'store'])->name('farms.store');
        Route::get('/farms/{farm}', [FarmController::class, 'show'])->name('farms.show');
        Route::patch('/farms/{farm}', [FarmController::class, 'update'])->name('farms.update');
        Route::post('/farms/{farm}/status', [FarmController::class, 'status'])->name('farms.status');
        Route::delete('/farms/{farm}', [FarmController::class, 'destroy'])->name('farms.destroy');

        Route::post('/farms/{farm}/fields', [FarmSetupController::class, 'storeField'])->name('farms.fields.store');
        Route::patch('/farms/{farm}/fields/{field}', [FarmSetupController::class, 'updateField'])->name('farms.fields.update');

        Route::post('/farms/{farm}/locations', [FarmSetupController::class, 'storeLocation'])->name('farms.locations.store');
        Route::patch('/farms/{farm}/locations/{location}', [FarmSetupController::class, 'updateLocation'])->name('farms.locations.update');

        Route::post('/farms/{farm}/access', [FarmSetupController::class, 'grant'])->name('farms.access.store');
        Route::patch('/farms/{farm}/access/{membership}', [FarmSetupController::class, 'updateMembership'])->name('farms.access.update');
        Route::delete('/farms/{farm}/access/{membership}', [FarmSetupController::class, 'revoke'])->name('farms.access.destroy');

        // ── Catalogues: crop types, varieties, seasons, species, breeds ─────────────────────
        Route::get('/settings', [AgriSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/{kind}', [AgriSettingsController::class, 'store'])->name('settings.store');
        Route::patch('/settings/{kind}/{uuid}', [AgriSettingsController::class, 'update'])->name('settings.update');

        // ── Module B: crops ──────────────────────────────────────────────────────────────────
        Route::get('/crops', [CropController::class, 'index'])->name('crops.index');
        Route::post('/crops/plans', [CropController::class, 'storePlan'])->name('crops.plans.store');
        Route::get('/crops/plans/{plan}', [CropController::class, 'show'])->name('crops.plans.show');
        Route::patch('/crops/plans/{plan}', [CropController::class, 'updatePlan'])->name('crops.plans.update');
        Route::post('/crops/plans/{plan}/transition', [CropController::class, 'transition'])->name('crops.plans.transition');
        Route::post('/crops/plans/{plan}/harvests', [CropController::class, 'storeHarvest'])->name('crops.harvests.store');
        Route::post('/crops/activities', [CropController::class, 'storeActivity'])->name('crops.activities.store');
        Route::post('/crops/activities/{activity}/status', [CropController::class, 'activityStatus'])->name('crops.activities.status');
        Route::post('/crops/activities/{activity}/inputs', [CropController::class, 'storeInput'])->name('crops.inputs.store');
        Route::delete('/crops/activities/{activity}/inputs/{input}', [CropController::class, 'destroyInput'])->name('crops.inputs.destroy');
        Route::post('/crops/observations', [CropController::class, 'storeObservation'])->name('crops.observations.store');
        Route::patch('/crops/observations/{observation}', [CropController::class, 'updateObservation'])->name('crops.observations.update');

        // ── Module C: livestock ──────────────────────────────────────────────────────────────
        Route::get('/livestock', [LivestockController::class, 'index'])->name('livestock.index');
        Route::post('/livestock/groups', [LivestockController::class, 'storeGroup'])->name('livestock.groups.store');
        Route::get('/livestock/groups/{group}', [LivestockController::class, 'showGroup'])->name('livestock.groups.show');
        Route::patch('/livestock/groups/{group}', [LivestockController::class, 'updateGroup'])->name('livestock.groups.update');
        Route::post('/livestock/groups/{group}/count', [LivestockController::class, 'countChange'])->name('livestock.groups.count');
        Route::post('/livestock/animals', [LivestockController::class, 'storeAnimal'])->name('livestock.animals.store');
        Route::get('/livestock/animals/{animal}', [LivestockController::class, 'showAnimal'])->name('livestock.animals.show');
        Route::patch('/livestock/animals/{animal}', [LivestockController::class, 'updateAnimal'])->name('livestock.animals.update');
        Route::post('/livestock/animals/{animal}/move', [LivestockController::class, 'move'])->name('livestock.animals.move');
        Route::post('/livestock/animals/{animal}/exit', [LivestockController::class, 'exit'])->name('livestock.animals.exit');
        Route::post('/livestock/animals/{animal}/events', [LivestockController::class, 'event'])->name('livestock.animals.events');
        Route::post('/livestock/health', [LivestockController::class, 'treat'])->name('livestock.health.store');
        Route::post('/livestock/weights', [LivestockController::class, 'weigh'])->name('livestock.weights.store');

        // ── Module D: farm stores ────────────────────────────────────────────────────────────
        Route::get('/stock', [FarmStockController::class, 'index'])->name('stock.index');
        Route::post('/stock/store', [FarmStockController::class, 'openStore'])->name('stock.store.open');
        Route::post('/stock/items', [FarmStockController::class, 'storeItem'])->name('stock.items.store');
        Route::post('/stock/receive', [FarmStockController::class, 'receive'])->name('stock.receive');
        Route::post('/stock/sell', [FarmStockController::class, 'sell'])->name('stock.sell');
        Route::post('/stock/requisitions', [FarmStockController::class, 'requisition'])->name('stock.requisitions.store');

        // ── Module E: finance ────────────────────────────────────────────────────────────────
        Route::get('/finance', [FarmFinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/export', [FarmFinanceController::class, 'export'])->name('finance.export');
        Route::post('/finance/transactions', [FarmFinanceController::class, 'storeTransaction'])->name('finance.transactions.store');
        Route::post('/finance/transactions/{transaction}/reverse', [FarmFinanceController::class, 'reverse'])->name('finance.transactions.reverse');
        Route::post('/finance/budgets', [FarmFinanceController::class, 'storeBudget'])->name('finance.budgets.store');
        Route::delete('/finance/budgets/{line}', [FarmFinanceController::class, 'destroyBudget'])->name('finance.budgets.destroy');

        // ── Module F: machinery ──────────────────────────────────────────────────────────────
        Route::get('/machinery', [MachineryController::class, 'index'])->name('machinery.index');
        Route::post('/machinery', [MachineryController::class, 'store'])->name('machinery.store');
        Route::patch('/machinery/{machine}', [MachineryController::class, 'update'])->name('machinery.update');
        Route::post('/machinery/{machine}/usage', [MachineryController::class, 'logUse'])->name('machinery.usage');
        Route::post('/machinery/{machine}/services', [MachineryController::class, 'service'])->name('machinery.services');
        Route::post('/machinery/{machine}/dispose', [MachineryController::class, 'dispose'])->name('machinery.dispose');

        // ── Module G: tasks ──────────────────────────────────────────────────────────────────
        Route::get('/tasks', [FarmTaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [FarmTaskController::class, 'store'])->name('tasks.store');
        Route::patch('/tasks/{task}', [FarmTaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/transition', [FarmTaskController::class, 'transition'])->name('tasks.transition');
        Route::get('/my-work', [MyWorkController::class, 'index'])->name('my-work');

        // ── Module J: documents ──────────────────────────────────────────────────────────────
        Route::get('/documents', [FarmDocumentController::class, 'index'])->name('documents.index');
        Route::post('/documents', [FarmDocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}/download', [FarmDocumentController::class, 'download'])->name('documents.download');
        Route::patch('/documents/{document}', [FarmDocumentController::class, 'update'])->name('documents.update');

        // ── Module I: alerts and reports ─────────────────────────────────────────────────────
        Route::get('/alerts', [FarmAlertController::class, 'index'])->name('alerts.index');
        Route::post('/alerts/scan', [FarmAlertController::class, 'scan'])->name('alerts.scan');
        Route::post('/alerts/{alert}/acknowledge', [FarmAlertController::class, 'acknowledge'])->name('alerts.acknowledge');
        Route::get('/reports', [AgriReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{report}/export', [AgriReportController::class, 'export'])->name('reports.export');

        // ── Module H: assistant (read-only) ──────────────────────────────────────────────────
        Route::get('/assistant', [AgriAssistantController::class, 'index'])->name('assistant.index');
        Route::post('/assistant/ask', [AgriAssistantController::class, 'ask'])->name('assistant.ask');
    });
