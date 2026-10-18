<?php

use App\Http\Controllers\ModuleHubController;
use App\Modules\Operations\Manufacturing\Http\Controllers\BomController;
use App\Modules\Operations\Manufacturing\Http\Controllers\CostingController;
use App\Modules\Operations\Manufacturing\Http\Controllers\DashboardController;
use App\Modules\Operations\Manufacturing\Http\Controllers\InspectionController;
use App\Modules\Operations\Manufacturing\Http\Controllers\InspectionPlanController;
use App\Modules\Operations\Manufacturing\Http\Controllers\MaintenanceController;
use App\Modules\Operations\Manufacturing\Http\Controllers\NonconformanceController;
use App\Modules\Operations\Manufacturing\Http\Controllers\PlanningController;
use App\Modules\Operations\Manufacturing\Http\Controllers\ProductController;
use App\Modules\Operations\Manufacturing\Http\Controllers\ProductionOrderController;
use App\Modules\Operations\Manufacturing\Http\Controllers\RoutingController;
use App\Modules\Operations\Manufacturing\Http\Controllers\ShopFloorController;
use App\Modules\Operations\Manufacturing\Http\Controllers\TraceabilityController;
use App\Modules\Operations\Manufacturing\Http\Controllers\SetupController;
use App\Modules\Operations\Manufacturing\Http\Controllers\WhereUsedController;
use App\Modules\Operations\Manufacturing\Http\Controllers\WorkforceController;
use App\Modules\Operations\Manufacturing\Http\Controllers\FulfilmentController;
use App\Modules\Operations\Manufacturing\Http\Controllers\LossController;
use App\Modules\Operations\Manufacturing\Http\Controllers\ReportsController;
use App\Modules\Operations\Manufacturing\Http\Controllers\NotificationController;
use App\Modules\Operations\Manufacturing\Http\Controllers\AssistantController;
use App\Modules\Operations\Manufacturing\Http\Controllers\AccountingController;
use App\Modules\Operations\Manufacturing\Http\Controllers\AuditController;
use App\Modules\Operations\Manufacturing\Http\Controllers\MultiFactoryController;
use App\Modules\Operations\Manufacturing\Http\Controllers\IntegrationController;
use App\Modules\Operations\Manufacturing\Support\ManufacturingHubs;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Manufacturing & Production
|--------------------------------------------------------------------------
|
| Gated by module entitlement (module.active) and hub tier (module.tier); tenant data is isolated
| by row-level security underneath both. Every hub group declared in ManufacturingHubs gets its
| landing from the loop below -- derived from the config rather than listed by hand, because a
| group left out of a hand-written list is a hub that silently never renders and cards reachable
| only from it become reachable from nowhere.
|
| Route binding is on uuid throughout: an internal id never appears in a URL.
*/

// MFG-025: the company's manufacturing package and an overdue account's read-only state apply to
// every route, however it is reached.
Route::middleware(['module.active:manufacturing', 'module.tier:manufacturing', \App\Modules\Operations\Manufacturing\Http\Middleware\EnforceManufacturingPackage::class])
    ->prefix('manufacturing')->name('manufacturing.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // ── Hub landings, one per declared group ──────────────────────────────────────────────
        foreach (array_keys(ManufacturingHubs::hubs()) as $group) {
            Route::get('/'.$group, [ModuleHubController::class, 'show'])
                ->defaults('module', 'manufacturing')
                ->defaults('group', $group)
                ->name('hub.'.$group);
        }

        // ── MFG-002: products and their engineering definition ───────────────────────────────
        Route::prefix('engineering')->name('engineering.')->group(function () {
            // Product categories: the list the category dropdown chooses from. Registered before
            // /products/{uuid} so "categories" is never read as a product's uuid.
            Route::post('/products/categories', [ProductController::class, 'storeCategory'])->name('categories.store');
            Route::patch('/products/categories/{uuid}', [ProductController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/products/categories/{uuid}', [ProductController::class, 'destroyCategory'])->name('categories.destroy');

            Route::get('/products', [ProductController::class, 'index'])->name('products.index');
            Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            Route::get('/products/{uuid}', [ProductController::class, 'show'])->name('products.show');
            Route::patch('/products/{uuid}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('/products/{uuid}', [ProductController::class, 'destroy'])->name('products.destroy');

            // Engineering change control
            Route::post('/products/{uuid}/versions', [ProductController::class, 'storeVersion'])
                ->name('products.versions.store');
            Route::post('/products/{uuid}/versions/{versionUuid}/approve', [ProductController::class, 'approveVersion'])
                ->name('products.versions.approve');

            // Drawings, work instructions, specifications
            Route::post('/products/{uuid}/documents', [ProductController::class, 'storeDocument'])
                ->name('products.documents.store');
            Route::delete('/products/{uuid}/documents/{documentUuid}', [ProductController::class, 'destroyDocument'])
                ->name('products.documents.destroy');

            // Approved substitutions
            Route::post('/products/{uuid}/substitutes', [ProductController::class, 'storeSubstitute'])
                ->name('products.substitutes.store');
            Route::post('/products/{uuid}/substitutes/{substituteUuid}/approve', [ProductController::class, 'approveSubstitute'])
                ->name('products.substitutes.approve');
            Route::delete('/products/{uuid}/substitutes/{substituteUuid}', [ProductController::class, 'destroySubstitute'])
                ->name('products.substitutes.destroy');

            // Quality standards and inspection plans
            Route::post('/products/{uuid}/quality', [ProductController::class, 'storeQualityRequirement'])
                ->name('products.quality.store');
            Route::delete('/products/{uuid}/quality/{requirementUuid}', [ProductController::class, 'destroyQualityRequirement'])
                ->name('products.quality.destroy');

            // ── Bills of materials ───────────────────────────────────────────────────────────
            Route::get('/boms', [BomController::class, 'index'])->name('boms.index');
            Route::post('/boms', [BomController::class, 'store'])->name('boms.store');
            Route::get('/boms/{uuid}', [BomController::class, 'show'])->name('boms.show');
            Route::patch('/boms/{uuid}', [BomController::class, 'update'])->name('boms.update');
            Route::delete('/boms/{uuid}', [BomController::class, 'destroy'])->name('boms.destroy');

            // Lines. Only ever on a draft -- the database refuses the rest.
            Route::post('/boms/{uuid}/lines', [BomController::class, 'storeLine'])->name('boms.lines.store');
            Route::patch('/boms/{uuid}/lines/{lineUuid}', [BomController::class, 'updateLine'])->name('boms.lines.update');
            Route::delete('/boms/{uuid}/lines/{lineUuid}', [BomController::class, 'destroyLine'])->name('boms.lines.destroy');
            Route::post('/boms/{uuid}/lines/{lineUuid}/alternates', [BomController::class, 'storeAlternate'])
                ->name('boms.lines.alternates.store');

            // Lifecycle
            Route::post('/boms/{uuid}/submit', [BomController::class, 'submit'])->name('boms.submit');
            Route::post('/boms/{uuid}/approve', [BomController::class, 'approve'])->name('boms.approve');
            Route::post('/boms/{uuid}/reject', [BomController::class, 'reject'])->name('boms.reject');
            Route::post('/boms/{uuid}/revise', [BomController::class, 'revise'])->name('boms.revise');
            Route::post('/boms/{uuid}/obsolete', [BomController::class, 'obsolete'])->name('boms.obsolete');

            // What it takes to make a quantity, and what changed between two revisions.
            Route::get('/boms/{uuid}/explode', [BomController::class, 'explode'])->name('boms.explode');
            Route::get('/boms/{uuid}/compare/{otherUuid}', [BomController::class, 'compare'])->name('boms.compare');

            // Impact analysis, asked from the component end.
            Route::get('/where-used', [WhereUsedController::class, 'index'])->name('where-used.index');

            // ── MFG-003: routings ────────────────────────────────────────────────────────────
            Route::get('/routings', [RoutingController::class, 'index'])->name('routings.index');
            Route::post('/routings', [RoutingController::class, 'store'])->name('routings.store');
            Route::get('/routings/{uuid}', [RoutingController::class, 'show'])->name('routings.show');
            Route::patch('/routings/{uuid}', [RoutingController::class, 'update'])->name('routings.update');
            Route::delete('/routings/{uuid}', [RoutingController::class, 'destroy'])->name('routings.destroy');
            Route::post('/routings/{uuid}/operations', [RoutingController::class, 'storeOperation'])->name('routings.operations.store');
            Route::patch('/routings/{uuid}/operations/{operationUuid}', [RoutingController::class, 'updateOperation'])->name('routings.operations.update');
            Route::delete('/routings/{uuid}/operations/{operationUuid}', [RoutingController::class, 'destroyOperation'])->name('routings.operations.destroy');
            Route::post('/routings/{uuid}/operations/{operationUuid}/alternates', [RoutingController::class, 'storeAlternate'])->name('routings.operations.alternates.store');
            Route::post('/routings/{uuid}/submit', [RoutingController::class, 'submit'])->name('routings.submit');
            Route::post('/routings/{uuid}/approve', [RoutingController::class, 'approve'])->name('routings.approve');
            Route::post('/routings/{uuid}/reject', [RoutingController::class, 'reject'])->name('routings.reject');
            Route::post('/routings/{uuid}/revise', [RoutingController::class, 'revise'])->name('routings.revise');
            Route::post('/routings/{uuid}/obsolete', [RoutingController::class, 'obsolete'])->name('routings.obsolete');
            Route::get('/routings/{uuid}/estimate', [RoutingController::class, 'estimate'])->name('routings.estimate');
        });

        // ── MFG-006: production orders ───────────────────────────────────────────────────────
        Route::prefix('production')->name('production.')->group(function () {
            Route::get('/orders', [ProductionOrderController::class, 'index'])->name('orders.index');
            Route::post('/orders', [ProductionOrderController::class, 'store'])->name('orders.store');
            Route::get('/orders/{uuid}', [ProductionOrderController::class, 'show'])->name('orders.show');
            Route::patch('/orders/{uuid}', [ProductionOrderController::class, 'update'])->name('orders.update');

            // Lifecycle. One route per step, so each authorizes exactly the step it takes.
            foreach (['plan', 'unplan', 'approve', 'reject', 'reopen', 'release', 'hold', 'resume', 'cancel', 'complete', 'close'] as $step) {
                Route::post("/orders/{uuid}/{$step}", [ProductionOrderController::class, $step])->name("orders.{$step}");
            }
            Route::post('/orders/{uuid}/send-to-quality', [ProductionOrderController::class, 'sendToQuality'])->name('orders.send-to-quality');
            Route::post('/orders/{uuid}/return-to-production', [ProductionOrderController::class, 'returnToProduction'])->name('orders.return-to-production');

            // Material
            Route::post('/orders/{uuid}/components/{componentUuid}/issue', [ProductionOrderController::class, 'issue'])->name('orders.components.issue');
            Route::post('/orders/{uuid}/components/{componentUuid}/return', [ProductionOrderController::class, 'returnMaterial'])->name('orders.components.return');

            // Operations and gates
            Route::post('/orders/{uuid}/operations/{operationUuid}/start', [ProductionOrderController::class, 'startOperation'])->name('orders.operations.start');
            Route::post('/orders/{uuid}/operations/{operationUuid}/pause', [ProductionOrderController::class, 'pauseOperation'])->name('orders.operations.pause');
            Route::post('/orders/{uuid}/operations/{operationUuid}/complete', [ProductionOrderController::class, 'completeOperation'])->name('orders.operations.complete');
            Route::post('/orders/{uuid}/operations/{operationUuid}/gate', [ProductionOrderController::class, 'recordGate'])->name('orders.operations.gate');

            // Output and losses
            Route::post('/orders/{uuid}/output', [ProductionOrderController::class, 'receiveOutput'])->name('orders.output');
            Route::post('/orders/{uuid}/loss', [ProductionOrderController::class, 'recordLoss'])->name('orders.loss');

            Route::get('/orders/{uuid}/job-card', [ShopFloorController::class, 'jobCard'])->name('orders.job-card');
        });

        // ── MFG-004/005/015: planning, MRP, suppliers ────────────────────────────────────────
        Route::prefix('planning')->name('planning.')->group(function () {
            Route::get('/demand', [PlanningController::class, 'demand'])->name('demand');
            Route::post('/forecasts', [PlanningController::class, 'storeForecast'])->name('forecasts.store');
            Route::post('/forecasts/{uuid}/approve', [PlanningController::class, 'approveForecast'])->name('forecasts.approve');
            Route::delete('/forecasts/{uuid}', [PlanningController::class, 'destroyForecast'])->name('forecasts.destroy');
            Route::get('/mrp', [PlanningController::class, 'mrp'])->name('mrp');
            Route::post('/mrp', [PlanningController::class, 'runMrp'])->name('mrp.run');
            Route::post('/mrp/approve', [PlanningController::class, 'approve'])->name('mrp.approve');
            Route::post('/mrp/recommendations/{uuid}', [PlanningController::class, 'decide'])->name('mrp.decide');
            Route::get('/schedule', [PlanningController::class, 'schedule'])->name('schedule');
            Route::post('/schedule/preview', [PlanningController::class, 'previewSchedule'])->name('schedule.preview');
            Route::post('/schedule', [PlanningController::class, 'applySchedule'])->name('schedule.apply');
            Route::get('/suppliers', [PlanningController::class, 'suppliers'])->name('suppliers');
            Route::get('/parameters', [PlanningController::class, 'parameters'])->name('parameters');
            Route::post('/parameters/{stockItemId}', [PlanningController::class, 'saveParameters'])->whereNumber('stockItemId')->name('parameters.save');
        });

        // ── MFG-007: shop floor ──────────────────────────────────────────────────────────────
        // JSON for the terminal (each write applied once per client key), pages for the rest.
        Route::prefix('floor')->name('floor.')->group(function () {
            Route::get('/', [ShopFloorController::class, 'terminal'])->name('terminal');
            Route::get('/queue', [ShopFloorController::class, 'queue'])->name('queue');
            Route::post('/scan', [ShopFloorController::class, 'scan'])->name('scan');
            Route::get('/operations/{uuid}', [ShopFloorController::class, 'show'])->name('operations.show');
            Route::post('/operations/{uuid}/start', [ShopFloorController::class, 'start'])->name('operations.start');
            Route::post('/operations/{uuid}/pause', [ShopFloorController::class, 'pause'])->name('operations.pause');
            Route::post('/operations/{uuid}/complete', [ShopFloorController::class, 'complete'])->name('operations.complete');
            Route::post('/operations/{uuid}/note', [ShopFloorController::class, 'note'])->name('operations.note');
            Route::post('/operations/{uuid}/assign', [ShopFloorController::class, 'assign'])->name('operations.assign');
            Route::post('/operations/{uuid}/components/{componentUuid}/issue', [ShopFloorController::class, 'issue'])->name('operations.issue');

            Route::get('/downtime', [ShopFloorController::class, 'downtime'])->name('downtime.index');
            Route::post('/downtime', [ShopFloorController::class, 'startDowntime'])->name('downtime.start');
            // Before /downtime/{uuid}/end so "reasons" is never read as a stoppage's uuid.
            Route::post('/downtime/reasons', [ShopFloorController::class, 'storeReason'])->name('downtime.reasons.store');
            Route::patch('/downtime/reasons/{uuid}', [ShopFloorController::class, 'updateReason'])->name('downtime.reasons.update');
            Route::post('/downtime/{uuid}/end', [ShopFloorController::class, 'endDowntime'])->name('downtime.end');

            Route::get('/escalations', [ShopFloorController::class, 'escalations'])->name('escalations.index');
            Route::post('/escalations', [ShopFloorController::class, 'escalate'])->name('escalations.store');
            Route::post('/escalations/{uuid}/acknowledge', [ShopFloorController::class, 'acknowledge'])->name('escalations.acknowledge');
            Route::post('/escalations/{uuid}/resolve', [ShopFloorController::class, 'resolveEscalation'])->name('escalations.resolve');
        });

        // ── MFG-009: quality ─────────────────────────────────────────────────────────────────
        Route::prefix('quality')->name('quality.')->group(function () {
            Route::get('/inspections', [InspectionController::class, 'index'])->name('inspections.index');
            // Registered before /inspections/{uuid} so neither word is read as an inspection's uuid.
            Route::post('/inspections/receive', [InspectionController::class, 'receive'])->name('inspections.receive');
            Route::post('/inspections/hold', [InspectionController::class, 'hold'])->name('inspections.hold');
            Route::get('/inspections/{uuid}', [InspectionController::class, 'show'])->name('inspections.show');
            Route::post('/inspections/{uuid}/record', [InspectionController::class, 'record'])->name('inspections.record');
            Route::post('/inspections/{uuid}/dispose', [InspectionController::class, 'dispose'])->name('inspections.dispose');

            Route::get('/plans', [InspectionPlanController::class, 'index'])->name('plans.index');
            Route::post('/plans', [InspectionPlanController::class, 'store'])->name('plans.store');
            Route::get('/plans/{uuid}', [InspectionPlanController::class, 'show'])->name('plans.show');
            Route::patch('/plans/{uuid}', [InspectionPlanController::class, 'update'])->name('plans.update');
            Route::post('/plans/{uuid}/characteristics', [InspectionPlanController::class, 'storeCharacteristic'])->name('plans.characteristics.store');
            Route::patch('/plans/{uuid}/characteristics/{characteristicUuid}', [InspectionPlanController::class, 'updateCharacteristic'])->name('plans.characteristics.update');
            Route::delete('/plans/{uuid}/characteristics/{characteristicUuid}', [InspectionPlanController::class, 'destroyCharacteristic'])->name('plans.characteristics.destroy');

            Route::get('/nonconformances', [NonconformanceController::class, 'index'])->name('ncrs.index');
            Route::post('/nonconformances', [NonconformanceController::class, 'store'])->name('ncrs.store');
            Route::get('/nonconformances/{uuid}', [NonconformanceController::class, 'show'])->name('ncrs.show');
            Route::patch('/nonconformances/{uuid}', [NonconformanceController::class, 'update'])->name('ncrs.update');
            Route::post('/nonconformances/{uuid}/actions', [NonconformanceController::class, 'storeAction'])->name('ncrs.actions.store');
            Route::post('/nonconformances/{uuid}/actions/{actionUuid}/complete', [NonconformanceController::class, 'completeAction'])->name('ncrs.actions.complete');
            Route::post('/nonconformances/{uuid}/submit', [NonconformanceController::class, 'submit'])->name('ncrs.submit');
            Route::post('/nonconformances/{uuid}/verify', [NonconformanceController::class, 'verify'])->name('ncrs.verify');
            Route::post('/nonconformances/{uuid}/void', [NonconformanceController::class, 'void'])->name('ncrs.void');
            Route::post('/nonconformances/{uuid}/sheq', [NonconformanceController::class, 'raiseInSheq'])->name('ncrs.sheq');

            // ── MFG-010: traceability and recall ─────────────────────────────────────────────
            Route::get('/traceability', [TraceabilityController::class, 'index'])->name('traceability');
            Route::get('/recalls', [TraceabilityController::class, 'recalls'])->name('recalls.index');
            Route::post('/recalls', [TraceabilityController::class, 'store'])->name('recalls.store');
            Route::get('/recalls/{uuid}', [TraceabilityController::class, 'show'])->name('recalls.show');
            Route::post('/recalls/{uuid}/hold', [TraceabilityController::class, 'hold'])->name('recalls.hold');
            Route::post('/recalls/{uuid}/release', [TraceabilityController::class, 'release'])->name('recalls.release');
            Route::post('/recalls/{uuid}/dispose', [TraceabilityController::class, 'dispose'])->name('recalls.dispose');
            Route::post('/recalls/{uuid}/actions', [TraceabilityController::class, 'record'])->name('recalls.actions.store');
            Route::post('/recalls/{uuid}/close', [TraceabilityController::class, 'close'])->name('recalls.close');
        });

        // ── MFG-016: workforce ───────────────────────────────────────────────────────────────
        Route::prefix('workforce')->name('workforce.')->group(function () {
            Route::get('/roster', [WorkforceController::class, 'roster'])->name('roster');
            Route::post('/roster', [WorkforceController::class, 'storeRoster'])->name('roster.store');
            Route::delete('/roster/{id}', [WorkforceController::class, 'destroyRoster'])->whereNumber('id')->name('roster.destroy');
            Route::get('/qualifications', [WorkforceController::class, 'qualifications'])->name('qualifications');
            Route::post('/qualifications', [WorkforceController::class, 'storeRequirement'])->name('qualifications.store');
            Route::delete('/qualifications/{uuid}', [WorkforceController::class, 'destroyRequirement'])->name('qualifications.destroy');
            Route::get('/labour', [WorkforceController::class, 'labour'])->name('labour');
            Route::get('/labour/hours.csv', [WorkforceController::class, 'hours'])->name('labour.hours');
        });

        // ── MFG-017: customer orders and subcontracting ──────────────────────────────────────
        Route::prefix('fulfilment')->name('fulfilment.')->group(function () {
            Route::get('/customer-orders', [FulfilmentController::class, 'customerOrders'])->name('customer-orders');
            Route::get('/customer-orders/quote', [FulfilmentController::class, 'quote'])->name('customer-orders.quote');
            Route::post('/customer-orders', [FulfilmentController::class, 'commit'])->name('customer-orders.commit');
            Route::post('/customer-orders/{uuid}/cancel', [FulfilmentController::class, 'cancelCommitment'])->name('customer-orders.cancel');
            Route::get('/subcontracting', [FulfilmentController::class, 'subcontracts'])->name('subcontracts.index');
            Route::post('/subcontracting', [FulfilmentController::class, 'storeSubcontract'])->name('subcontracts.store');
            Route::get('/subcontracting/{uuid}', [FulfilmentController::class, 'showSubcontract'])->name('subcontracts.show');
            Route::post('/subcontracting/{uuid}/send', [FulfilmentController::class, 'send'])->name('subcontracts.send');
            Route::post('/subcontracting/{uuid}/receive', [FulfilmentController::class, 'receive'])->name('subcontracts.receive');
            Route::post('/subcontracting/{uuid}/materials/{material}/return', [FulfilmentController::class, 'returnMaterial'])->whereNumber('material')->name('subcontracts.return');
            Route::post('/subcontracting/{uuid}/close', [FulfilmentController::class, 'close'])->name('subcontracts.close');
            Route::post('/subcontracting/{uuid}/cancel', [FulfilmentController::class, 'cancel'])->name('subcontracts.cancel');
        });

        // ── MFG-022: external integrations ───────────────────────────────────────────────────
        Route::prefix('integrations')->name('integrations.')->group(function () {
            Route::get('/connections', [IntegrationController::class, 'index'])->name('index');
            Route::post('/connections', [IntegrationController::class, 'store'])->name('store');
            Route::get('/connections/{uuid}', [IntegrationController::class, 'show'])->name('show');
            Route::patch('/connections/{uuid}', [IntegrationController::class, 'update'])->name('update');
            Route::post('/connections/{uuid}/mappings', [IntegrationController::class, 'map'])->name('map');
            Route::post('/connections/{uuid}/import', [IntegrationController::class, 'import'])->name('import');
            Route::get('/connections/{uuid}/export.csv', [IntegrationController::class, 'export'])->name('export');
            Route::post('/connections/{uuid}/{step}', [IntegrationController::class, 'act'])
                ->whereIn('step', ['validate', 'activate', 'pause', 'sync', 'rotate-secret'])->name('act');
            Route::post('/messages/{uuid}/{step}', [IntegrationController::class, 'event'])->whereIn('step', ['retry', 'discard'])->name('events.act');
        });

        // ── MFG-024: several factories ───────────────────────────────────────────────────────
        Route::prefix('factories')->name('factories.')->group(function () {
            Route::get('/transfers', [MultiFactoryController::class, 'transfers'])->name('transfers.index');
            Route::post('/transfers', [MultiFactoryController::class, 'store'])->name('transfers.store');
            Route::get('/transfers/{uuid}', [MultiFactoryController::class, 'show'])->name('transfers.show');
            Route::post('/transfers/{uuid}/{step}', [MultiFactoryController::class, 'act'])->whereIn('step', ['approve', 'ship', 'receive', 'cancel'])->name('transfers.act');
            Route::get('/network', [MultiFactoryController::class, 'network'])->name('network');
            Route::post('/network/warehouses', [MultiFactoryController::class, 'assignWarehouse'])->name('network.warehouses');
            Route::post('/network/products/{uuid}', [MultiFactoryController::class, 'restrictProduct'])->name('network.products');
        });

        // ── MFG-023: audit trail ─────────────────────────────────────────────────────────────
        Route::get('/reports/audit-trail', [AuditController::class, 'index'])->name('audit.index');

        // ── MFG-021: accounting controls ─────────────────────────────────────────────────────
        Route::prefix('accounting')->name('accounting.')->group(function () {
            Route::get('/treatments', [AccountingController::class, 'rules'])->name('rules');
            Route::post('/treatments/{id}/{step}', [AccountingController::class, 'ruleAction'])->whereNumber('id')->whereIn('step', ['update', 'approve', 'suspend'])->name('rules.act');
            Route::get('/reconciliation', [AccountingController::class, 'reconciliation'])->name('reconciliation');
            Route::post('/exceptions/{uuid}/resolve', [AccountingController::class, 'resolve'])->name('exceptions.resolve');
            Route::get('/transactions', [AccountingController::class, 'transactions'])->name('transactions');
            Route::post('/movements/{movement}/reverse', [AccountingController::class, 'reverse'])->name('movements.reverse');
        });

        // ── MFG-019: the AI manufacturing assistant ─────────────────────────────────────────
        Route::prefix('assistant')->name('assistant.')->group(function () {
            Route::get('/ask', [AssistantController::class, 'index'])->name('index');
            Route::post('/ask', [AssistantController::class, 'ask'])->middleware('throttle:20,1')->name('ask');
            Route::get('/recommendations', [AssistantController::class, 'recommendations'])->name('recommendations');
            Route::post('/recommendations/{uuid}/draft', [AssistantController::class, 'draft'])->name('draft');
            Route::post('/actions/{uuid}/{decision}', [AssistantController::class, 'decide'])->whereIn('decision', ['approve', 'reject', 'complete'])->name('decide');
        });

        // ── MFG-020: notifications and escalations ──────────────────────────────────────────
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/rules', [NotificationController::class, 'rules'])->name('rules');
            Route::post('/rules', [NotificationController::class, 'saveRule'])->name('rules.save');
            Route::delete('/rules/{id}', [NotificationController::class, 'destroyRule'])->whereNumber('id')->name('rules.destroy');
            Route::get('/log', [NotificationController::class, 'log'])->name('log');
            Route::post('/run', [NotificationController::class, 'runNow'])->name('run');
        });

        // ── MFG-018: management dashboards ───────────────────────────────────────────────────
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/executive', [ReportsController::class, 'executive'])->name('executive');
            Route::get('/planning', [ReportsController::class, 'planning'])->name('planning');
            Route::get('/inventory', [ReportsController::class, 'inventory'])->name('inventory');
            Route::get('/costs', [ReportsController::class, 'costs'])->name('costs');
            Route::get('/quality', [ReportsController::class, 'quality'])->name('quality');
        });

        // ── MFG-012: loss prevention ─────────────────────────────────────────────────────────
        Route::prefix('loss')->name('loss.')->group(function () {
            Route::get('/findings', [LossController::class, 'index'])->name('findings.index');
            Route::post('/findings/scan', [LossController::class, 'scan'])->name('findings.scan');
            Route::get('/findings/{uuid}', [LossController::class, 'show'])->name('findings.show');
            Route::post('/findings/{uuid}/{step}', [LossController::class, 'act'])
                ->whereIn('step', ['assign', 'investigate', 'note', 'verify', 'dismiss', 'plan', 'result', 'approve', 'close'])->name('findings.act');
            Route::get('/rules', [LossController::class, 'rules'])->name('rules');
            Route::post('/rules/{category}', [LossController::class, 'updateRule'])->name('rules.update');
        });

        // ── MFG-013: maintenance and OEE ─────────────────────────────────────────────────────
        Route::prefix('maintenance')->name('maintenance.')->group(function () {
            Route::get('/machines', [MaintenanceController::class, 'machines'])->name('machines.index');
            Route::post('/fault-codes', [MaintenanceController::class, 'storeFaultCode'])->name('fault-codes.store');
            Route::get('/machines/{uuid}', [MaintenanceController::class, 'show'])->name('machines.show');
            Route::patch('/machines/{uuid}', [MaintenanceController::class, 'updateProfile'])->name('machines.update');
            Route::post('/machines/{uuid}/hold', [MaintenanceController::class, 'hold'])->name('machines.hold');
            Route::post('/machines/{uuid}/release', [MaintenanceController::class, 'release'])->name('machines.release');
            Route::post('/machines/{uuid}/breakdown', [MaintenanceController::class, 'breakdown'])->name('machines.breakdown');
            Route::post('/machines/{uuid}/rules', [MaintenanceController::class, 'storeRule'])->name('rules.store');
            Route::post('/rules/{ruleUuid}/toggle', [MaintenanceController::class, 'toggleRule'])->name('rules.toggle');
            Route::get('/preventive', [MaintenanceController::class, 'preventive'])->name('preventive');
            Route::post('/preventive/generate', [MaintenanceController::class, 'generate'])->name('preventive.generate');
            Route::get('/oee', [MaintenanceController::class, 'oee'])->name('oee');
            Route::post('/oee/{centreId}', [MaintenanceController::class, 'oeeSettings'])->whereNumber('centreId')->name('oee.settings');
        });

        // ── MFG-011: costing and profitability ───────────────────────────────────────────────
        Route::prefix('costing')->name('costing.')->group(function () {
            Route::get('/standards', [CostingController::class, 'standards'])->name('standards.index');
            Route::post('/standards', [CostingController::class, 'calculate'])->name('standards.calculate');
            Route::get('/standards/{uuid}', [CostingController::class, 'show'])->name('standards.show');
            Route::post('/standards/{uuid}/approve', [CostingController::class, 'approve'])->name('standards.approve');
            Route::get('/variances', [CostingController::class, 'variances'])->name('variances');
            Route::get('/profitability', [CostingController::class, 'profitability'])->name('profitability');
        });

        // ── MFG-001: the factory environment ─────────────────────────────────────────────────
        Route::prefix('setup')->name('setup.')->group(function () {
            Route::get('/factories', [SetupController::class, 'factories'])->name('factories');
            Route::post('/factories', [SetupController::class, 'storeFactory'])->name('factories.store');
            Route::patch('/factories/{uuid}', [SetupController::class, 'updateFactory'])->name('factories.update');
            Route::delete('/factories/{uuid}', [SetupController::class, 'destroyFactory'])->name('factories.destroy');

            Route::post('/factories/{uuid}/departments', [SetupController::class, 'storeDepartment'])
                ->name('departments.store');
            Route::post('/factories/{uuid}/lines', [SetupController::class, 'storeProductionLine'])
                ->name('lines.store');

            // Who works at which plant (MFG-001.09). Confines a person to what they are given.
            Route::post('/factories/{uuid}/access', [SetupController::class, 'storeAccess'])
                ->name('factories.access.store');
            Route::delete('/factories/{uuid}/access/{accessUuid}', [SetupController::class, 'destroyAccess'])
                ->name('factories.access.destroy');

            Route::get('/work-centres', [SetupController::class, 'workCentres'])->name('work-centres');
            Route::post('/work-centres', [SetupController::class, 'storeWorkCentre'])->name('work-centres.store');
            Route::patch('/work-centres/{uuid}', [SetupController::class, 'updateWorkCentre'])->name('work-centres.update');
            Route::delete('/work-centres/{uuid}', [SetupController::class, 'destroyWorkCentre'])->name('work-centres.destroy');
            Route::post('/work-centres/{uuid}/workstations', [SetupController::class, 'storeWorkstation'])
                ->name('workstations.store');
            Route::post('/work-centres/{uuid}/machines', [SetupController::class, 'attachMachine'])
                ->name('machines.attach');
            Route::delete('/work-centres/{uuid}/machines/{assetId}', [SetupController::class, 'detachMachine'])
                ->name('machines.detach');

            Route::get('/shifts', [SetupController::class, 'shifts'])->name('shifts');
            Route::post('/shifts', [SetupController::class, 'storeShift'])->name('shifts.store');
            Route::patch('/shifts/{uuid}', [SetupController::class, 'updateShift'])->name('shifts.update');
            Route::delete('/shifts/{uuid}', [SetupController::class, 'destroyShift'])->name('shifts.destroy');
            Route::post('/shutdowns', [SetupController::class, 'storeShutdown'])->name('shutdowns.store');
            Route::delete('/shutdowns/{uuid}', [SetupController::class, 'destroyShutdown'])->name('shutdowns.destroy');

            Route::get('/units', [SetupController::class, 'units'])->name('units');
            Route::post('/units', [SetupController::class, 'storeUnit'])->name('units.store');
            Route::patch('/units/{uuid}', [SetupController::class, 'updateUnit'])->name('units.update');
            Route::post('/units/install', [SetupController::class, 'installReferenceData'])->name('units.install');
            Route::post('/units/conversions', [SetupController::class, 'storeConversion'])->name('units.conversions.store');
            Route::delete('/units/conversions/{id}', [SetupController::class, 'destroyConversion'])
                ->whereNumber('id')->name('units.conversions.destroy');

            Route::get('/numbering', [SetupController::class, 'numbering'])->name('numbering');
            Route::post('/numbering', [SetupController::class, 'storeSequence'])->name('numbering.store');
            Route::patch('/numbering/{uuid}', [SetupController::class, 'updateSequence'])->name('numbering.update');

            Route::get('/policies', [SetupController::class, 'policies'])->name('policies');
            Route::post('/policies', [SetupController::class, 'saveFactoryPolicy'])->name('policies.save');
            Route::post('/policies/limits', [SetupController::class, 'storeApprovalLimit'])->name('policies.limits.store');
            Route::delete('/policies/limits/{uuid}', [SetupController::class, 'destroyApprovalLimit'])
                ->name('policies.limits.destroy');
        });
    });
