<?php

use App\Http\Controllers\ModuleHubController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\AccountingController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\AdjustmentController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\AssistantController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\BatchController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\CountController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\LossController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\MobileController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\QualityController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\RecallController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\ReturnsController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\DashboardController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\InboundController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\InboundSupportController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\LabelController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\LocationController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\MovesController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\OutboundController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\GovernanceController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\InsightController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\IntegrationController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\PlanningController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\WorkflowController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\ProductionController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\ProductController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\ProtectionController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\PutawayController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\StockController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\StockSetupController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\WarehouseController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\WorkController;
use App\Modules\Operations\WarehouseManagement\Support\WarehouseHubs;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Warehouse Management & Intelligence
|--------------------------------------------------------------------------
|
| Gated by module entitlement and hub tier; tenant data is isolated by row-level security under
| both. Hub landings come from the loop over WarehouseHubs, so a declared group always renders.
| Routes bind on uuid: an internal id never appears in a URL.
*/

Route::middleware(['module.active:warehouse_management', 'module.tier:warehouse_management', \App\Modules\Operations\WarehouseManagement\Http\Middleware\MalformedIdentifierIsNotFound::class])
    ->prefix('warehouse')->name('warehouse.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        foreach (array_keys(WarehouseHubs::hubs()) as $group) {
            Route::get('/'.$group, [ModuleHubController::class, 'show'])
                ->defaults('module', 'warehouse_management')
                ->defaults('group', $group)
                ->name('hub.'.$group);
        }

        // ── WMS-002/003/004/005: warehouses and the floor inside them ─────────────────────────
        Route::prefix('layout')->name('layout.')->group(function () {
            Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
            Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
            Route::patch('/warehouses/{uuid}', [WarehouseController::class, 'update'])->name('warehouses.update');
            // WMS-240/241: versioned policies.
            Route::get('/warehouses/{uuid}/policies', [\App\Modules\Operations\WarehouseManagement\Http\Controllers\PolicyController::class, 'show'])->name('warehouses.policies');
            Route::post('/warehouses/{uuid}/policies', [\App\Modules\Operations\WarehouseManagement\Http\Controllers\PolicyController::class, 'draft'])->name('warehouses.policies.draft');
            Route::post('/warehouses/{uuid}/policies/{version}', [\App\Modules\Operations\WarehouseManagement\Http\Controllers\PolicyController::class, 'act'])->name('warehouses.policies.act');
            Route::delete('/warehouses/{uuid}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
            Route::post('/warehouses/{uuid}/sync', [WarehouseController::class, 'sync'])->name('warehouses.sync');
            Route::post('/warehouses/{uuid}/staff', [WarehouseController::class, 'storeStaff'])->name('warehouses.staff.store');
            Route::delete('/warehouses/{uuid}/staff/{staff}', [WarehouseController::class, 'destroyStaff'])->name('warehouses.staff.destroy');

            Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
            Route::post('/warehouses/{warehouse}/locations', [LocationController::class, 'store'])->name('locations.store');
            Route::post('/warehouses/{warehouse}/locations/generate', [LocationController::class, 'generate'])->name('locations.generate');
            Route::patch('/warehouses/{warehouse}/locations/{uuid}', [LocationController::class, 'update'])->name('locations.update');
            Route::post('/warehouses/{warehouse}/locations/{uuid}/status', [LocationController::class, 'status'])->name('locations.status');
            Route::delete('/warehouses/{warehouse}/locations/{uuid}', [LocationController::class, 'destroy'])->name('locations.destroy');

            Route::get('/labels', [LocationController::class, 'labels'])->name('labels');
            Route::get('/history', [LocationController::class, 'history'])->name('history');
        });

        // ── WMS-030..035: inbound -- expected receipts, receiving, appointments, returns ───────
        Route::prefix('inbound')->name('inbound.')->group(function () {
            Route::get('/expected', [InboundController::class, 'expected'])->name('expected');
            Route::post('/order-lines/{line}/link', [InboundController::class, 'linkLine'])->name('lines.link');
            Route::get('/orders/{po}/receive', [InboundController::class, 'receiveForm'])->name('receive.form');
            Route::post('/orders/{po}/receive', [InboundController::class, 'receive'])->name('receive');
            Route::get('/without-order', [InboundController::class, 'nonPoForm'])->name('nonpo.form');
            Route::post('/without-order', [InboundController::class, 'receiveWithoutOrder'])->name('nonpo.store');

            Route::get('/receipts', [InboundController::class, 'receipts'])->name('receipts.index');
            Route::get('/receipts/{uuid}', [InboundController::class, 'show'])->name('receipts.show');
            Route::post('/receipts/{uuid}/decide', [InboundController::class, 'decide'])->name('receipts.decide');
            Route::post('/receipts/{uuid}/attachments', [InboundController::class, 'attach'])->name('receipts.attach');
            Route::get('/receipts/{uuid}/attachments/{attachment}', [InboundController::class, 'download'])->name('receipts.download');
            Route::post('/discrepancies/{discrepancy}/resolve', [InboundController::class, 'resolve'])->name('discrepancies.resolve');

            Route::get('/appointments', [InboundSupportController::class, 'appointments'])->name('appointments.index');
            Route::post('/appointments', [InboundSupportController::class, 'book'])->name('appointments.store');
            Route::post('/appointments/{uuid}/move', [InboundSupportController::class, 'moveAppointment'])->name('appointments.move');

            Route::get('/suppliers', [InboundSupportController::class, 'scorecards'])->name('scorecards');

            Route::get('/returns', [InboundSupportController::class, 'returns'])->name('returns.index');
            Route::get('/returns/returnable', [InboundSupportController::class, 'returnable'])->name('returns.returnable');
            Route::post('/returns', [InboundSupportController::class, 'storeReturn'])->name('returns.store');
            Route::post('/returns/{uuid}/move', [InboundSupportController::class, 'moveReturn'])->name('returns.move');
        });

        // ── WMS-040..042: put-away and capacity ──────────────────────────────────────────────
        Route::prefix('floor')->name('floor.')->group(function () {
            Route::get('/putaway', [PutawayController::class, 'index'])->name('putaway.index');
            Route::post('/putaway', [PutawayController::class, 'store'])->name('putaway.store');
            Route::get('/putaway/{uuid}', [PutawayController::class, 'show'])->name('putaway.show');
            Route::post('/putaway/{uuid}', [PutawayController::class, 'act'])->name('putaway.act');
            Route::get('/capacity', [PutawayController::class, 'capacity'])->name('capacity');

            // ── WMS-050..053: moves, transfers and replenishment ─────────────────────────────
            Route::get('/move', [MovesController::class, 'binMove'])->name('move');
            Route::post('/move', [MovesController::class, 'doBinMove'])->name('move.store');
            Route::get('/transfers', [MovesController::class, 'transfers'])->name('transfers.index');
            Route::post('/transfers', [MovesController::class, 'storeTransfer'])->name('transfers.store');
            Route::post('/transfers/{uuid}', [MovesController::class, 'moveTransfer'])->name('transfers.move');
            Route::get('/replenishment', [MovesController::class, 'replenishment'])->name('replenishment.index');
            Route::get('/replenishment/bins', [MovesController::class, 'bins'])->name('replenishment.bins');
            Route::post('/replenishment/rules', [MovesController::class, 'saveRule'])->name('replenishment.rules.store');
            Route::patch('/replenishment/rules/{uuid}', [MovesController::class, 'saveRule'])->name('replenishment.rules.update');
            Route::post('/replenishment/generate', [MovesController::class, 'generate'])->name('replenishment.generate');
            Route::post('/replenishment/tasks/{uuid}', [MovesController::class, 'actTask'])->name('replenishment.tasks.act');
            Route::post('/replenishment/purchase-request', [MovesController::class, 'raiseRequest'])->name('replenishment.request');
        });

        // ── WMS-070..073: stocktakes, cycle counts, review and accuracy ─────────────────────
        Route::prefix('counts')->name('counts.')->group(function () {
            Route::get('/', [CountController::class, 'index'])->name('index');
            Route::post('/', [CountController::class, 'store'])->name('store');
            Route::get('/accuracy', [CountController::class, 'accuracy'])->name('accuracy');
            Route::post('/plans', [CountController::class, 'savePlan'])->name('plans.store');
            Route::patch('/plans/{uuid}', [CountController::class, 'savePlan'])->name('plans.update');
            Route::post('/plans/{uuid}/run', [CountController::class, 'runPlan'])->name('plans.run');
            Route::post('/classify', [CountController::class, 'classify'])->name('classify');
            Route::get('/{uuid}', [CountController::class, 'show'])->name('show');
            Route::post('/{uuid}/entries', [CountController::class, 'record'])->name('record');
            Route::post('/{uuid}', [CountController::class, 'act'])->name('act');
        });

        // ── WMS-080..083: returns, damage and wastage, quality control, recall ─────────────
        Route::prefix('returns')->name('returns.')->group(function () {
            Route::get('/', [ReturnsController::class, 'index'])->name('index');
            Route::get('/sources', [ReturnsController::class, 'sources'])->name('sources');
            Route::post('/', [ReturnsController::class, 'store'])->name('store');
            Route::get('/{uuid}', [ReturnsController::class, 'show'])->name('show');
            Route::post('/{uuid}', [ReturnsController::class, 'act'])->name('act');
        });
        Route::prefix('quality')->name('quality.')->group(function () {
            Route::get('/inspections', [QualityController::class, 'index'])->name('index');
            Route::post('/inspections/{uuid}/results', [QualityController::class, 'record'])->name('record');
            Route::post('/inspections/{uuid}/decision', [QualityController::class, 'decide'])->name('decide');
            Route::post('/hold', [QualityController::class, 'hold'])->name('hold');
            Route::post('/orders/{uuid}', [QualityController::class, 'inspectOrder'])->name('order');
            Route::get('/setup', [QualityController::class, 'setup'])->name('setup');
            Route::post('/plans', [QualityController::class, 'savePlan'])->name('plans.store');
            Route::patch('/plans/{uuid}', [QualityController::class, 'savePlan'])->name('plans.update');
            Route::post('/defects', [QualityController::class, 'saveDefect'])->name('defects.store');
            Route::patch('/defects/{id}', [QualityController::class, 'saveDefect'])->name('defects.update');
        });
        Route::prefix('losses')->name('losses.')->group(function () {
            Route::get('/', [LossController::class, 'index'])->name('index');
            Route::post('/recoveries', [LossController::class, 'openRecovery'])->name('recoveries.store');
            Route::post('/recoveries/{uuid}', [LossController::class, 'progressRecovery'])->name('recoveries.update');
            Route::post('/adjustments/{uuid}/evidence', [LossController::class, 'attachToAdjustment'])->name('evidence.store');
            Route::get('/evidence/{uuid}', [LossController::class, 'download'])->name('evidence.download');
        });
        Route::prefix('recalls')->name('recalls.')->group(function () {
            Route::get('/', [RecallController::class, 'index'])->name('index');
            Route::get('/lots', [RecallController::class, 'lots'])->name('lots');
            Route::post('/', [RecallController::class, 'store'])->name('store');
            Route::get('/{uuid}', [RecallController::class, 'show'])->name('show');
            Route::get('/{uuid}/report', [RecallController::class, 'report'])->name('report');
            Route::post('/{uuid}', [RecallController::class, 'act'])->name('act');
        });

        // ── WMS-090..093: batches, serial numbers, rotation, expiry ─────────────────────────
        Route::prefix('batches')->name('batches.')->group(function () {
            Route::get('/', [BatchController::class, 'index'])->name('index');
            Route::get('/serial', [BatchController::class, 'serial'])->name('serial');
            Route::get('/expiry', [BatchController::class, 'expiry'])->name('expiry');
            Route::get('/{uuid}', [BatchController::class, 'show'])->name('show');
        });
        Route::get('/outbound/orders/{uuid}/override-options', [BatchController::class, 'options'])->name('outbound.orders.override.options');
        Route::post('/outbound/orders/{uuid}/override', [BatchController::class, 'override'])->name('outbound.orders.override');

        // ── WMS-100..103: valuation, accounting events, reconciliation, cost reports ─────────
        Route::prefix('accounting')->name('accounting.')->group(function () {
            Route::get('/', [AccountingController::class, 'index'])->name('index');
            Route::get('/costs', [AccountingController::class, 'costs'])->name('costs');
            Route::post('/rules/{id}', [AccountingController::class, 'rule'])->name('rules.act');
            Route::post('/reconcile', [AccountingController::class, 'reconcile'])->name('reconcile');
            Route::post('/issues/{uuid}', [AccountingController::class, 'resolve'])->name('issues.resolve');
            Route::post('/landed', [AccountingController::class, 'storeLanded'])->name('landed.store');
            Route::post('/landed/{uuid}/post', [AccountingController::class, 'postLanded'])->name('landed.post');
            Route::post('/revaluations', [AccountingController::class, 'requestRevaluation'])->name('revaluations.store');
            Route::post('/revaluations/{uuid}', [AccountingController::class, 'decideRevaluation'])->name('revaluations.decide');
        });

        // ── WMS-110..114: Profit Protection Engine ───────────────────────────────────────────
        Route::prefix('protection')->name('protection.')->group(function () {
            Route::get('/', [ProtectionController::class, 'index'])->name('index');
            Route::post('/', [ProtectionController::class, 'store'])->name('store');
            Route::get('/rules', [ProtectionController::class, 'rules'])->name('rules');
            Route::patch('/rules/{id}', [ProtectionController::class, 'saveRule'])->name('rules.update');
            Route::post('/scan', [ProtectionController::class, 'scan'])->name('scan');
            Route::get('/{uuid}', [ProtectionController::class, 'show'])->name('show');
            Route::post('/{uuid}', [ProtectionController::class, 'act'])->name('act');
        });

        // ── WMS-120..124: forecasts and purchasing intelligence ──────────────────────────────
        Route::prefix('planning')->name('planning.')->group(function () {
            Route::get('/forecast', [PlanningController::class, 'forecast'])->name('forecast');
            Route::post('/forecast', [PlanningController::class, 'runForecast'])->name('forecast.run');
            Route::post('/forecast/{id}', [PlanningController::class, 'override'])->name('forecast.override');
            Route::get('/buying', [PlanningController::class, 'buying'])->name('buying');
            Route::get('/buying/compare/{uuid}', [PlanningController::class, 'compare'])->name('buying.compare');
            Route::post('/buying/request', [PlanningController::class, 'request'])->name('buying.request');
            Route::post('/buying/terms', [PlanningController::class, 'saveTerms'])->name('buying.terms');
            Route::get('/excess', [PlanningController::class, 'excess'])->name('excess');
        });

        // ── WMS-212: operational health ─────────────────────────────────────────────────────
        Route::get('/health', [\App\Modules\Operations\WarehouseManagement\Http\Controllers\HealthController::class, 'index'])->name('health');

        // ── WMS-200..203, 220: audit ledger, retention, emergency overrides, role templates ──
        Route::prefix('governance')->name('governance.')->group(function () {
            Route::get('/', [GovernanceController::class, 'index'])->name('index');
            Route::get('/audit.csv', [GovernanceController::class, 'export'])->name('export');
            Route::post('/settings', [GovernanceController::class, 'settings'])->name('settings');
            Route::post('/overrides', [GovernanceController::class, 'override'])->name('override');
            Route::post('/overrides/{id}', [GovernanceController::class, 'override'])->name('override.act');
        });

        // ── WMS-190..192: alerts and the approval workflow ───────────────────────────────────
        Route::get('/alerts', [WorkflowController::class, 'notifications'])->name('alerts.index');
        Route::post('/alerts/{event}', [WorkflowController::class, 'saveRule'])->name('alerts.rule');
        Route::prefix('approvals')->name('approvals.')->group(function () {
            Route::get('/', [WorkflowController::class, 'approvals'])->name('index');
            Route::post('/steps/{step}', [WorkflowController::class, 'decide'])->name('decide');
            Route::post('/policies/{type}', [WorkflowController::class, 'savePolicy'])->name('policy');
            Route::post('/delegations', [WorkflowController::class, 'delegate'])->name('delegate');
            Route::post('/delegations/{delegation}/revoke', [WorkflowController::class, 'revoke'])->name('revoke');
        });

        // ── §33: KPIs against a baseline ─────────────────────────────────────────────────────
        Route::get('/kpis', [\App\Modules\Operations\WarehouseManagement\Http\Controllers\KpiController::class, 'index'])->name('kpis');
        Route::post('/kpis', [\App\Modules\Operations\WarehouseManagement\Http\Controllers\KpiController::class, 'act'])->name('kpis.act');

        // ── WMS-180..185: dashboards and report exports ─────────────────────────────────────
        Route::get('/insights/{kind}', [InsightController::class, 'dashboard'])->name('insights.dashboard')->where('kind', 'inventory|suppliers|protection|executive');
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/', [InsightController::class, 'reports'])->name('index');
            Route::post('/', [InsightController::class, 'run'])->name('run');
            Route::get('/{uuid}', [InsightController::class, 'download'])->name('download');
        });

        // ── WMS-170..173: carriers, events and webhooks, connected systems, standards ────────
        Route::prefix('integrations')->name('integrations.')->group(function () {
            Route::get('/carriers', [IntegrationController::class, 'carriers'])->name('carriers');
            Route::post('/carriers', [IntegrationController::class, 'saveCarrier'])->name('carriers.store');
            Route::post('/carriers/{uuid}', [IntegrationController::class, 'saveCarrier'])->name('carriers.update');
            Route::get('/events', [IntegrationController::class, 'events'])->name('events');
            Route::post('/webhooks', [IntegrationController::class, 'webhook'])->name('webhooks.store');
            Route::post('/webhooks/{uuid}', [IntegrationController::class, 'webhook'])->name('webhooks.update');
            Route::post('/events/{eventId}/replay', [IntegrationController::class, 'replay'])->name('events.replay');
            Route::get('/systems', [IntegrationController::class, 'systems'])->name('systems');
            Route::post('/systems', [IntegrationController::class, 'systemAct'])->name('systems.store');
            Route::post('/systems/{uuid}', [IntegrationController::class, 'systemAct'])->name('systems.act');
            Route::get('/standards', [IntegrationController::class, 'standards'])->name('standards');
            Route::get('/standards/epcis', [IntegrationController::class, 'epcisDownload'])->name('standards.epcis');
            Route::post('/standards/gln/{warehouse}', [IntegrationController::class, 'saveGln'])->name('standards.gln');
        });

        // ── WMS-160..165: production materials, from Manufacturing's own orders ─────────────
        Route::prefix('production')->name('production.')->group(function () {
            Route::get('/', [ProductionController::class, 'board'])->name('board');
            Route::post('/{uuid}/pick', [ProductionController::class, 'pick'])->name('pick');
            Route::post('/put-away/{position}', [ProductionController::class, 'putAway'])->name('putaway');
        });

        // ── WMS-130..135: tasks, labour, picking and slotting advice, performance, simulation ──
        Route::prefix('work')->name('work.')->group(function () {
            Route::get('/board', [WorkController::class, 'board'])->name('board');
            Route::post('/board/assign', [WorkController::class, 'assign'])->name('assign');
            Route::get('/board/history', [WorkController::class, 'taskHistory'])->name('history');
            Route::get('/labour', [WorkController::class, 'labour'])->name('labour');
            Route::post('/labour/standards', [WorkController::class, 'standards'])->name('standards');
            Route::get('/slotting', [WorkController::class, 'slotting'])->name('slotting');
            Route::post('/slotting', [WorkController::class, 'slottingAct'])->name('slotting.act');
            Route::get('/performance', [WorkController::class, 'performance'])->name('performance');
            Route::get('/simulations', [WorkController::class, 'simulations'])->name('simulations');
            Route::post('/simulations', [WorkController::class, 'simulate'])->name('simulate');
        });

        // ── WMS-140..144: the warehouse assistant ─────────────────────────────────────────────
        Route::prefix('assistant')->name('assistant.')->group(function () {
            Route::get('/', [AssistantController::class, 'index'])->name('index');
            Route::post('/', [AssistantController::class, 'ask'])->name('ask');
            Route::post('/drafts', [AssistantController::class, 'draft'])->name('draft');
            Route::post('/drafts/{uuid}/withdraw', [AssistantController::class, 'withdraw'])->name('withdraw');
            Route::post('/answers/{uuid}/feedback', [AssistantController::class, 'feedback'])->name('feedback');
            Route::get('/quality', [AssistantController::class, 'quality'])->name('quality');
        });

        // ── WMS-150..154: the floor screen, offline work, labels and printers ────────────────
        Route::prefix('m')->name('mobile.')->group(function () {
            Route::get('/', [MobileController::class, 'floor'])->name('floor');
            Route::get('/bundle', [MobileController::class, 'bundle'])->name('bundle');
            Route::post('/sync', [MobileController::class, 'sync'])->name('sync');
            Route::get('/conflicts', [MobileController::class, 'conflicts'])->name('conflicts');
            Route::post('/conflicts/{id}', [MobileController::class, 'resolve'])->name('conflicts.resolve');
        });
        Route::prefix('labels')->name('labels.')->group(function () {
            Route::get('/setup', [LabelController::class, 'index'])->name('setup');
            Route::post('/templates', [LabelController::class, 'saveTemplate'])->name('templates.store');
            Route::post('/printers', [LabelController::class, 'savePrinter'])->name('printers.store');
            Route::post('/print', [LabelController::class, 'print'])->name('print');
        });

        // ── WMS-060..066: fulfilment -- orders, picking, packing, dispatch, service levels ──
        Route::prefix('outbound')->name('outbound.')->group(function () {
            Route::get('/orders', [OutboundController::class, 'index'])->name('orders.index');
            Route::post('/orders', [OutboundController::class, 'store'])->name('orders.store');
            Route::get('/orders/{uuid}', [OutboundController::class, 'show'])->name('orders.show');
            Route::post('/orders/{uuid}', [OutboundController::class, 'act'])->name('orders.act');
            Route::post('/orders/{uuid}/pack', [OutboundController::class, 'pack'])->name('orders.pack');
            Route::get('/orders/{uuid}/documents', [OutboundController::class, 'documents'])->name('orders.documents');
            Route::post('/orders/{uuid}/dispatch', [OutboundController::class, 'dispatch'])->name('orders.dispatch');
            Route::post('/shipments/{shipment}/delivered', [OutboundController::class, 'pod'])->name('shipments.pod');
            Route::post('/shipments/{shipment}/tracking', [OutboundController::class, 'tracking'])->name('shipments.tracking');
            Route::get('/shipments/{shipment}/pod', [OutboundController::class, 'podFile'])->name('shipments.pod.file');

            Route::get('/picking', [OutboundController::class, 'waves'])->name('waves.index');
            Route::post('/picking', [OutboundController::class, 'releaseWave'])->name('waves.store');
            Route::get('/picking/{uuid}', [OutboundController::class, 'wave'])->name('waves.show');
            Route::post('/picks/{uuid}', [OutboundController::class, 'confirmPick'])->name('picks.confirm');

            Route::get('/service-levels', [OutboundController::class, 'sla'])->name('sla');
        });

        // ── WMS-020..025: the ledger seen from the warehouse -- states, availability, history ──
        Route::prefix('stock')->name('stock.')->group(function () {
            Route::get('/overview', [StockController::class, 'index'])->name('index');
            Route::get('/movements', [StockController::class, 'movements'])->name('movements');
            Route::get('/states', [StockController::class, 'states'])->name('states');
            Route::get('/items/{uuid}', [StockController::class, 'show'])->name('show');

            Route::get('/adjustments', [AdjustmentController::class, 'index'])->name('adjustments.index');
            Route::post('/adjustments', [AdjustmentController::class, 'store'])->name('adjustments.store');
            Route::get('/adjustments/positions', [AdjustmentController::class, 'positions'])->name('adjustments.positions');
            Route::get('/adjustments/{uuid}', [AdjustmentController::class, 'show'])->name('adjustments.show');
            Route::post('/adjustments/{uuid}/decide', [AdjustmentController::class, 'decide'])->name('adjustments.decide');

            Route::get('/reason-codes', [StockSetupController::class, 'reasons'])->name('reasons.index');
            Route::post('/reason-codes', [StockSetupController::class, 'saveReason'])->name('reasons.store');
            Route::patch('/reason-codes/{uuid}', [StockSetupController::class, 'saveReason'])->name('reasons.update');

            Route::get('/clients', [StockSetupController::class, 'clients'])->name('clients.index');
            Route::post('/clients/access', [StockSetupController::class, 'grant'])->name('clients.grant');
            Route::delete('/clients/access', [StockSetupController::class, 'revoke'])->name('clients.revoke');
        });

        // ── WMS-010..013: the product master, units, packaging, barcodes and lifecycle ─────────
        Route::prefix('products')->name('products.')->group(function () {
            // Literal paths before /items/{uuid}, so "scan" or "search" is never read as a uuid.
            Route::get('/scan', [ProductController::class, 'scan'])->name('scan');
            Route::get('/resolve', [ProductController::class, 'resolve'])->name('resolve');
            Route::get('/labels', [ProductController::class, 'labels'])->name('labels');
            Route::get('/search', [ProductController::class, 'search'])->name('search');

            Route::get('/items', [ProductController::class, 'index'])->name('index');
            Route::post('/items', [ProductController::class, 'store'])->name('store');
            Route::get('/items/{uuid}', [ProductController::class, 'show'])->name('show');
            Route::patch('/items/{uuid}', [ProductController::class, 'update'])->name('update');
            Route::post('/items/{uuid}/status', [ProductController::class, 'status'])->name('status');
            Route::post('/items/{uuid}/packaging', [ProductController::class, 'storePackaging'])->name('packaging.store');
            Route::patch('/items/{uuid}/packaging/{packaging}', [ProductController::class, 'updatePackaging'])->name('packaging.update');
            Route::delete('/items/{uuid}/packaging/{packaging}', [ProductController::class, 'destroyPackaging'])->name('packaging.destroy');
            Route::post('/items/{uuid}/barcodes', [ProductController::class, 'storeBarcode'])->name('barcodes.store');
            Route::post('/items/{uuid}/barcodes/generate', [ProductController::class, 'generateBarcode'])->name('barcodes.generate');
            Route::delete('/items/{uuid}/barcodes/{barcode}', [ProductController::class, 'destroyBarcode'])->name('barcodes.destroy');
            Route::post('/items/{uuid}/identifiers', [ProductController::class, 'storeIdentifier'])->name('identifiers.store');
            Route::delete('/items/{uuid}/identifiers/{identifier}', [ProductController::class, 'destroyIdentifier'])->name('identifiers.destroy');
            Route::put('/items/{uuid}/bundle', [ProductController::class, 'bundle'])->name('bundle');
            Route::post('/items/{uuid}/substitutes', [ProductController::class, 'storeSubstitute'])->name('substitutes.store');
            Route::delete('/items/{uuid}/substitutes/{substitute}', [ProductController::class, 'destroySubstitute'])->name('substitutes.destroy');
        });
    });
