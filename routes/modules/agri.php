<?php

use App\Modules\Agri\Http\Controllers\AgriAssistantController;
use App\Modules\Agri\Http\Controllers\PrecisionCropController;
use App\Modules\Agri\Http\Controllers\WorkOrderController;
use App\Modules\Agri\Http\Controllers\AgriDashboardController;
use App\Modules\Agri\Http\Controllers\AgriReportController;
use App\Modules\Agri\Http\Controllers\AgriSettingsController;
use App\Modules\Agri\Http\Controllers\CropController;
use App\Modules\Agri\Http\Controllers\FarmAlertController;
use App\Modules\Agri\Http\Controllers\FarmFinanceController;
use App\Modules\Agri\Http\Controllers\FarmFinanceIntelligenceController;
use App\Modules\Agri\Http\Controllers\FarmInventoryController;
use App\Modules\Agri\Http\Controllers\FarmStockController;
use App\Modules\Agri\Http\Controllers\FarmTaskController;
use App\Modules\Agri\Http\Controllers\LivestockController;
use App\Modules\Agri\Http\Controllers\MachineFleetController;
use App\Modules\Agri\Http\Controllers\PackhouseController;
use App\Modules\Agri\Http\Controllers\SalesController;
use App\Modules\Agri\Http\Controllers\GrainController;
use App\Modules\Agri\Http\Controllers\MarketController;
use App\Modules\Agri\Http\Controllers\SafetyController;
use App\Modules\Agri\Http\Controllers\InsightsController;
use App\Modules\Agri\Http\Controllers\IntegrationController;
use App\Modules\Agri\Http\Controllers\TwinController;
use App\Modules\Agri\Http\Controllers\OutgrowerController;
use App\Modules\Agri\Http\Controllers\MessagingController;
use App\Modules\Agri\Http\Controllers\WeatherController;
use App\Modules\Agri\Http\Controllers\MachineryController;
use App\Modules\Agri\Http\Controllers\MyWorkController;
use App\Modules\Agri\Http\Controllers\FarmController;
use App\Modules\Agri\Http\Controllers\FarmSetupController;
use App\Modules\Agri\Http\Controllers\FieldMapController;
use App\Modules\Agri\Http\Controllers\SatelliteController;
use App\Modules\Agri\Http\Controllers\BenchmarkController;
use App\Modules\Agri\Http\Controllers\TraceController;
use App\Modules\Agri\Http\Controllers\CarbonController;
use App\Modules\Agri\Http\Controllers\CertificationController;
use App\Modules\Agri\Http\Controllers\ExportController;
use App\Modules\Agri\Http\Controllers\ResidueController;
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
Route::middleware(['module.active:agri', 'module.tier:agri', \App\Modules\Agri\Http\Middleware\FarmTimeInput::class])
    ->prefix('agri')->name('agri.')
    ->group(function () {
        Route::get('/', [AgriDashboardController::class, 'index'])->name('dashboard');

        // Hub landings, one per AgriHubs group — the sidebar's four farm hubs.
        foreach (\App\Modules\Agri\Support\AgriHubs::hubs() as $group => $hub) {
            Route::get('/'.basename($hub['path']), [\App\Http\Controllers\ModuleHubController::class, 'show'])
                ->defaults('module', 'agri')->defaults('group', $group)->name('hub.'.$group)
                // Every farm role can view farms; someone with none gets a refusal, not an empty hub.
                ->middleware('can:'.\App\Modules\Agri\Support\RoleCatalog::VIEW_FARMS);
        }

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

        // Field maps: draw, import, edit and export boundaries; management zones; soil on the map.
        Route::get('/field-map', [FieldMapController::class, 'home'])->name('field-map');
        Route::get('/farms/{farm}/map', [FieldMapController::class, 'show'])->name('farms.map');
        Route::post('/farms/{farm}/map/check', [FieldMapController::class, 'check'])->name('farms.map.check');
        Route::put('/farms/{farm}/map/boundary', [FieldMapController::class, 'farmBoundary'])->name('farms.map.boundary');
        Route::post('/farms/{farm}/map/fields', [FieldMapController::class, 'storeField'])->name('farms.map.fields.store');
        Route::put('/farms/{farm}/map/fields/{field}/boundary', [FieldMapController::class, 'fieldBoundary'])->name('farms.map.fields.boundary');
        Route::post('/farms/{farm}/map/fields/{field}/zones', [FieldMapController::class, 'storeZone'])->name('farms.map.zones.store');
        Route::patch('/farms/{farm}/map/fields/{field}/zones/{zone}', [FieldMapController::class, 'updateZone'])->name('farms.map.zones.update');
        Route::delete('/farms/{farm}/map/fields/{field}/zones/{zone}', [FieldMapController::class, 'destroyZone'])->name('farms.map.zones.destroy');
        Route::post('/farms/{farm}/map/locations', [FieldMapController::class, 'storeLocation'])->name('farms.map.locations.store');
        Route::patch('/farms/{farm}/map/locations/{location}', [FieldMapController::class, 'moveLocation'])->name('farms.map.locations.move');
        Route::post('/farms/{farm}/map/imports', [FieldMapController::class, 'upload'])->name('farms.map.imports.store');
        Route::post('/farms/{farm}/map/imports/{import}/apply', [FieldMapController::class, 'apply'])->name('farms.map.imports.apply');
        Route::delete('/farms/{farm}/map/imports/{import}', [FieldMapController::class, 'discard'])->name('farms.map.imports.discard');
        Route::get('/farms/{farm}/map/export/{format}', [FieldMapController::class, 'export'])->whereIn('format', ['geojson', 'kml'])->name('farms.map.export');

        Route::post('/farms/{farm}/access', [FarmSetupController::class, 'grant'])->name('farms.access.store');
        Route::patch('/farms/{farm}/access/{membership}', [FarmSetupController::class, 'updateMembership'])->name('farms.access.update');
        Route::delete('/farms/{farm}/access/{membership}', [FarmSetupController::class, 'revoke'])->name('farms.access.destroy');

        // ── Catalogues: crop types, varieties, seasons, species, breeds ─────────────────────
        Route::get('/settings', [AgriSettingsController::class, 'index'])->name('settings.index');
        Route::post('/settings/{kind}', [AgriSettingsController::class, 'store'])->name('settings.store');
        Route::patch('/settings/{kind}/{uuid}', [AgriSettingsController::class, 'update'])->name('settings.update');

        // ── Module B: crops ──────────────────────────────────────────────────────────────────

        // Phase 2 §3: precision crop and production management.
        Route::post('/fields/{field}/split', [PrecisionCropController::class, 'split'])->name('fields.split');
        Route::post('/fields/{field}/soil-tests', [PrecisionCropController::class, 'soilTest'])->name('fields.soil-tests.store');
        Route::get('/soil-tests/{test}/report', [PrecisionCropController::class, 'soilReport'])->name('soil-tests.report');

        // ── Module C: livestock ──────────────────────────────────────────────────────────────
        // Phase 2 §4

        // ── Module D: farm stores ────────────────────────────────────────────────────────────
        Route::get('/stock', [FarmStockController::class, 'index'])->name('stock.index');
        Route::post('/stock/store', [FarmStockController::class, 'openStore'])->name('stock.store.open');
        Route::post('/stock/items', [FarmStockController::class, 'storeItem'])->name('stock.items.store');
        Route::post('/stock/receive', [FarmStockController::class, 'receive'])->name('stock.receive');
        Route::post('/stock/sell', [FarmStockController::class, 'sell'])->name('stock.sell');
        Route::post('/stock/requisitions', [FarmStockController::class, 'requisition'])->name('stock.requisitions.store');
        // Phase 2 §5: stock control and purchasing.
        Route::get('/stock/control', [FarmInventoryController::class, 'index'])->name('stock.control.home');
        Route::get('/stock/control/{tab}', [FarmInventoryController::class, 'index'])->whereIn('tab', FarmInventoryController::TABS)->name('stock.control');
        Route::post('/stock/stores', [FarmInventoryController::class, 'addStore'])->name('stock.stores.store');
        Route::patch('/stock/stores/{store}', [FarmInventoryController::class, 'updateStore'])->name('stock.stores.update');
        Route::post('/stock/item-settings', [FarmInventoryController::class, 'itemSetting'])->name('stock.item-settings.store');
        Route::post('/stock/transfers', [FarmInventoryController::class, 'transfer'])->name('stock.transfers.store');
        Route::post('/stock/transfers/{uuid}', [FarmInventoryController::class, 'transferStep'])->name('stock.transfers.step');
        Route::post('/stock/holds', [FarmInventoryController::class, 'hold'])->name('stock.holds.store');
        Route::post('/stock/chemicals', [FarmInventoryController::class, 'saveChemical'])->name('stock.chemicals.store');
        Route::post('/stock/chemicals/{chemical}', [FarmInventoryController::class, 'saveChemical'])->name('stock.chemicals.update');
        Route::get('/stock/chemicals/{chemical}/{which}', [FarmInventoryController::class, 'chemicalFile'])->whereIn('which', ['label', 'sds'])->name('stock.chemicals.file');
        Route::post('/stock/disputes', [FarmInventoryController::class, 'dispute'])->name('stock.disputes.store');
        Route::post('/stock/disputes/{dispute}', [FarmInventoryController::class, 'settleDispute'])->name('stock.disputes.settle');
        Route::get('/stock/suppliers/{supplier}/evidence', [FarmInventoryController::class, 'supplierEvidence'])->whereUuid('supplier')->name('stock.suppliers.evidence');

        // ── Module E: finance ────────────────────────────────────────────────────────────────
        Route::get('/finance', [FarmFinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/export', [FarmFinanceController::class, 'export'])->name('finance.export');
        Route::post('/finance/transactions', [FarmFinanceController::class, 'storeTransaction'])->name('finance.transactions.store');
        Route::post('/finance/transactions/{transaction}/reverse', [FarmFinanceController::class, 'reverse'])->name('finance.transactions.reverse');
        Route::post('/finance/budgets', [FarmFinanceController::class, 'storeBudget'])->name('finance.budgets.store');
        Route::delete('/finance/budgets/{line}', [FarmFinanceController::class, 'destroyBudget'])->name('finance.budgets.destroy');
        // Phase 2 §6: budgets approval, cost reports, cash flow, reconciliation and export.
        Route::get('/finance/intelligence', [FarmFinanceIntelligenceController::class, 'index'])->name('finance.intelligence.home');
        Route::get('/finance/intelligence/{tab}', [FarmFinanceIntelligenceController::class, 'index'])->whereIn('tab', FarmFinanceIntelligenceController::TABS)->name('finance.intelligence');
        Route::post('/finance/budgets/{line}/submit', [FarmFinanceIntelligenceController::class, 'submitBudget'])->name('finance.budgets.submit');
        Route::post('/finance/budgets/{line}/decide', [FarmFinanceIntelligenceController::class, 'decideBudget'])->name('finance.budgets.decide');
        Route::post('/finance/budgets/{line}/revise', [FarmFinanceIntelligenceController::class, 'reviseBudget'])->name('finance.budgets.revise');
        Route::post('/finance/revisions/{revision}', [FarmFinanceIntelligenceController::class, 'decideRevision'])->name('finance.revisions.decide');
        Route::post('/finance/loans', [FarmFinanceIntelligenceController::class, 'storeLoan'])->name('finance.loans.store');
        Route::post('/finance/loans/{loan}/close', [FarmFinanceIntelligenceController::class, 'closeLoan'])->name('finance.loans.close');
        Route::post('/finance/capex', [FarmFinanceIntelligenceController::class, 'storeCapex'])->name('finance.capex.store');
        Route::patch('/finance/capex/{capex}', [FarmFinanceIntelligenceController::class, 'updateCapex'])->name('finance.capex.update');
        Route::get('/finance/journals/{journal}/trace', [FarmFinanceIntelligenceController::class, 'trace'])->whereNumber('journal')->name('finance.journals.trace');
        Route::post('/finance/exports', [FarmFinanceIntelligenceController::class, 'export'])->name('finance.exports.store');
        Route::post('/finance/exports/{export}/acknowledge', [FarmFinanceIntelligenceController::class, 'acknowledge'])->name('finance.exports.acknowledge');

        // ── Module F: machinery ──────────────────────────────────────────────────────────────
        Route::get('/machinery', [MachineryController::class, 'index'])->name('machinery.index');
        Route::post('/machinery', [MachineryController::class, 'store'])->name('machinery.store');
        Route::patch('/machinery/{machine}', [MachineryController::class, 'update'])->name('machinery.update');
        Route::post('/machinery/{machine}/usage', [MachineryController::class, 'logUse'])->name('machinery.usage');
        Route::post('/machinery/{machine}/services', [MachineryController::class, 'service'])->name('machinery.services');
        Route::post('/machinery/{machine}/dispose', [MachineryController::class, 'dispose'])->name('machinery.dispose');
        // Phase 2 §16C: link a number for WhatsApp / SMS / voice.
        Route::post('/messaging/link', [MessagingController::class, 'link'])->name('messaging.link');

        // Phase 2 §16D: outgrowers.
        Route::get('/outgrowers', [OutgrowerController::class, 'index'])->name('outgrowers.index');
        Route::post('/outgrowers', [OutgrowerController::class, 'storeGrower'])->name('outgrowers.store');
        Route::post('/outgrowers/{grower}/contracts', [OutgrowerController::class, 'storeContract'])->name('outgrowers.contracts.store');
        Route::post('/outgrowers/{grower}/settle', [OutgrowerController::class, 'settle'])->name('outgrowers.settle');
        Route::post('/outgrower-contracts/{contract}/approve', [OutgrowerController::class, 'approveContract'])->name('outgrowers.contracts.approve');
        Route::post('/outgrower-contracts/{contract}/advances', [OutgrowerController::class, 'advance'])->name('outgrowers.advances.store');
        Route::post('/outgrower-contracts/{contract}/inspections', [OutgrowerController::class, 'inspect'])->name('outgrowers.inspections.store');
        Route::post('/outgrower-contracts/{contract}/collections', [OutgrowerController::class, 'collect'])->name('outgrowers.collections.store');
        Route::post('/outgrower-settlements/{settlement}/approve', [OutgrowerController::class, 'approveSettlement'])->name('outgrowers.settlements.approve');

        // Satellite crop health: NDVI per field and zone fetched from the organisation's provider.
        Route::get('/crop-health', [SatelliteController::class, 'index'])->name('satellite.index');
        Route::post('/crop-health/fetch', [SatelliteController::class, 'fetch'])->name('satellite.fetch');
        Route::get('/crop-health/images/{imagery}', [SatelliteController::class, 'image'])->name('satellite.image');
        Route::get('/crop-health/settings', [SatelliteController::class, 'settings'])->name('satellite.settings');
        Route::put('/crop-health/settings/{provider}', [SatelliteController::class, 'save'])->name('satellite.settings.save');
        Route::post('/crop-health/settings/{provider}/check', [SatelliteController::class, 'check'])->name('satellite.settings.check');
        Route::post('/crop-health/drop-rules', [SatelliteController::class, 'saveDropRule'])->name('satellite.drop-rules.store');
        Route::patch('/crop-health/drop-rules/{rule}', [SatelliteController::class, 'saveDropRule'])->name('satellite.drop-rules.update');
        Route::delete('/crop-health/drop-rules/{rule}', [SatelliteController::class, 'deleteDropRule'])->name('satellite.drop-rules.destroy');
        Route::get('/farms/{farm}/ndvi', [SatelliteController::class, 'farmLayers'])->name('farms.ndvi');

        // Peer benchmarks (opt-in, anonymised), consumer trace tokens and labels, carbon registry tracking.
        Route::get('/benchmarks', [BenchmarkController::class, 'index'])->name('benchmarks.index');
        Route::post('/benchmarks/opt-in', [BenchmarkController::class, 'optIn'])->name('benchmarks.opt-in');
        Route::get('/trace', [TraceController::class, 'index'])->name('trace.index');
        Route::post('/trace/settings', [TraceController::class, 'settings'])->name('trace.settings');
        Route::post('/trace/lots/{lot}', [TraceController::class, 'issue'])->name('trace.issue');
        Route::patch('/trace/tokens/{token}', [TraceController::class, 'update'])->name('trace.update');
        Route::post('/trace/tokens/{token}/revoke', [TraceController::class, 'revoke'])->name('trace.revoke');
        Route::get('/trace/labels', [TraceController::class, 'labels'])->name('trace.labels');
        Route::get('/carbon', [CarbonController::class, 'index'])->name('carbon.index');
        Route::post('/carbon/projects/{record}/register', [CarbonController::class, 'register'])->name('carbon.register');
        Route::patch('/carbon/registrations/{registration}', [CarbonController::class, 'update'])->name('carbon.update');
        Route::post('/carbon/registrations/{registration}/periods', [CarbonController::class, 'period'])->name('carbon.periods.store');
        Route::patch('/carbon/periods/{period}', [CarbonController::class, 'updatePeriod'])->name('carbon.periods.update');
        Route::post('/carbon/periods/{period}/monitoring', [CarbonController::class, 'monitoring'])->name('carbon.monitoring.store');
        Route::post('/carbon/registrations/{registration}/issuances', [CarbonController::class, 'issuance'])->name('carbon.issuances.store');
        Route::post('/carbon/issuances/{issuance}/transactions', [CarbonController::class, 'transaction'])->name('carbon.transactions.store');
        Route::post('/carbon/registrations/{registration}/documents', [CarbonController::class, 'document'])->name('carbon.documents.store');
        Route::get('/carbon/documents/{document}', [CarbonController::class, 'downloadDocument'])->name('carbon.documents.show');
        Route::post('/carbon/registrations/{registration}/exports', [CarbonController::class, 'export'])->name('carbon.exports.store');
        Route::get('/carbon/exports/{export}', [CarbonController::class, 'downloadExport'])->name('carbon.exports.show');

        // Residues and MRLs (dispatch gate), certification checklists with the farm's self-assessment, export document packs.
        Route::get('/residues', [ResidueController::class, 'index'])->name('residues.home');
        Route::get('/residues/{tab}', [ResidueController::class, 'index'])->whereIn('tab', ResidueController::TABS)->name('residues');
        Route::post('/residues/tests', [ResidueController::class, 'storeTest'])->name('residues.tests.store');
        Route::get('/residues/tests/{test}/certificate', [ResidueController::class, 'certificate'])->name('residues.tests.certificate');
        Route::post('/residues/lots/{lot}/markets', [ResidueController::class, 'lotMarkets'])->name('residues.lots.markets');
        Route::post('/residues/plans/{plan}/markets', [ResidueController::class, 'planMarkets'])->name('residues.plans.markets');
        Route::post('/residues/limits', [ResidueController::class, 'saveLimit'])->name('residues.limits.store');
        Route::patch('/residues/limits/{limit}', [ResidueController::class, 'saveLimit'])->name('residues.limits.update');
        Route::delete('/residues/limits/{limit}', [ResidueController::class, 'deleteLimit'])->name('residues.limits.destroy');
        Route::post('/residues/starter-set', [ResidueController::class, 'starter'])->name('residues.starter');
        Route::post('/residues/import', [ResidueController::class, 'import'])->name('residues.import');
        Route::post('/residues/restrictions', [ResidueController::class, 'saveRestriction'])->name('residues.restrictions.store');
        Route::delete('/residues/restrictions/{restriction}', [ResidueController::class, 'deleteRestriction'])->name('residues.restrictions.destroy');
        Route::post('/residues/markets', [ResidueController::class, 'saveMarket'])->name('residues.markets.store');
        Route::patch('/residues/markets/{market}', [ResidueController::class, 'saveMarket'])->name('residues.markets.update');
        Route::post('/residues/dispatches/{dispatch}/override', [ResidueController::class, 'override'])->whereUuid('dispatch')->name('residues.override');
        Route::get('/certification', [CertificationController::class, 'index'])->name('certification.index');
        Route::post('/certification/install', [CertificationController::class, 'install'])->name('certification.install');
        Route::post('/certification/farms/{farm}/adopt', [CertificationController::class, 'adopt'])->name('certification.adopt');
        Route::post('/certification/farms/{farm}/answers', [CertificationController::class, 'answer'])->name('certification.answer');
        Route::get('/certification/farms/{farm}/pdf', [CertificationController::class, 'pdf'])->name('certification.pdf');
        Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');
        Route::post('/exports/dispatches/{dispatch}', [ExportController::class, 'save'])->whereUuid('dispatch')->name('exports.save');
        Route::post('/exports/consignments/{consignment}/lines', [ExportController::class, 'lines'])->name('exports.lines');
        Route::post('/exports/consignments/{consignment}/generate', [ExportController::class, 'generate'])->name('exports.generate');
        Route::post('/exports/farms/{farm}/codes', [ExportController::class, 'saveCode'])->name('exports.codes.store');
        Route::delete('/exports/codes/{code}', [ExportController::class, 'deleteCode'])->name('exports.codes.destroy');

        // Phase 2 §16: the farm map, crop imagery, the decision simulator.
        Route::get('/twin', [TwinController::class, 'index'])->name('twin.home');
        Route::get('/twin/{tab}', [TwinController::class, 'index'])->whereIn('tab', TwinController::TABS)->name('twin');
        Route::post('/twin/imagery', [TwinController::class, 'importImagery'])->name('twin.imagery.store');
        Route::post('/twin/flags/{flag}', [TwinController::class, 'validateFlag'])->name('twin.flags.validate');
        Route::post('/twin/simulations', [TwinController::class, 'simulate'])->name('twin.simulations.store');
        Route::post('/twin/simulations/{simulation}/adopt', [TwinController::class, 'adopt'])->name('twin.simulations.adopt');

        // Phase 2 §14: integration messages, the failed queue and reconciliation.
        Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
        Route::post('/integrations/events/{event}/retry', [IntegrationController::class, 'retry'])->name('integrations.retry');

        // Phase 2 §13: role dashboards with labelled figures and exports.
        Route::get('/insights', [InsightsController::class, 'index'])->name('insights.home');
        Route::get('/insights/{role}', [InsightsController::class, 'index'])->whereIn('role', array_keys(\App\Modules\Agri\Services\FarmDashboards::ROLES))->name('insights');
        Route::get('/insights/{role}/{section}/export', [InsightsController::class, 'export'])->name('insights.export');
        Route::get('/outcomes', [InsightsController::class, 'outcomes'])->name('outcomes.index');
        Route::post('/outcomes', [InsightsController::class, 'snapshot'])->name('outcomes.store');

        // Phase 2 §12: compliance and safety are SHEQ's (legal register, compliance schemes, inspections);
        // the farm reports dangers into it and opens the SHEQ inspection that gates a job.
        Route::post('/safety/report', [SafetyController::class, 'report'])->name('safety.report');
        Route::get('/work-orders/{order}/safety/{template}', [SafetyController::class, 'inspect'])->name('work-orders.safety');

        // Phase 2 §11: weather, weather rules, environment and sustainability.
        Route::get('/weather', [WeatherController::class, 'index'])->name('weather.home');
        Route::get('/weather/{tab}', [WeatherController::class, 'index'])->whereIn('tab', WeatherController::TABS)->name('weather');
        Route::post('/weather/refresh', [WeatherController::class, 'refresh'])->name('weather.refresh');
        Route::post('/weather/rules', [WeatherController::class, 'saveRule'])->name('weather.rules.store');
        Route::post('/weather/rules/{rule}', [WeatherController::class, 'saveRule'])->name('weather.rules.update');
        Route::post('/weather/rules/{rule}/approve', [WeatherController::class, 'approveRule'])->name('weather.rules.approve');
        Route::post('/weather/rules/{rule}/retire', [WeatherController::class, 'retireRule'])->name('weather.rules.retire');
        Route::post('/environment', [WeatherController::class, 'storeRecord'])->name('environment.store');
        Route::get('/environment/{environmentRecord}/evidence', [WeatherController::class, 'evidence'])->name('environment.evidence');

        // Phase 2 §9: supply contracts, market prices, marketplace. Customers, price lists, quotes,
        // orders and disputes are Accounting's (finance/accounting/*).
        Route::get('/sales', [SalesController::class, 'index'])->name('sales.home');
        Route::get('/sales/{tab}', [SalesController::class, 'index'])->whereIn('tab', SalesController::TABS)->name('sales');
        Route::post('/sales/contracts', [SalesController::class, 'storeContract'])->name('sales.contracts.store');
        Route::post('/sales/contracts/{contract}/status', [SalesController::class, 'contractStatus'])->name('sales.contracts.status');
        Route::post('/sales/contracts/{contract}/amend', [SalesController::class, 'amend'])->name('sales.contracts.amend');
        Route::post('/sales/contracts/{contract}/orders', [SalesController::class, 'contractOrder'])->name('sales.contracts.orders');
        Route::post('/sales/amendments/{amendment}', [SalesController::class, 'decideAmendment'])->name('sales.amendments.decide');
        Route::post('/sales/prices', [SalesController::class, 'storePrice'])->name('sales.prices.store');
        Route::post('/sales/listings', [SalesController::class, 'storeListing'])->name('sales.listings.store');
        Route::post('/sales/requests', [SalesController::class, 'storeRequest'])->name('sales.requests.store');

        // Grain load by load: field log, weighbridge, tickets, bins, lost loads, buyers' settlements, contract balances.
        Route::get('/grain', [GrainController::class, 'index'])->name('grain.home');
        Route::get('/grain/{tab}', [GrainController::class, 'index'])->whereIn('tab', GrainController::TABS)->name('grain');
        Route::post('/grain/loads/dispatch', [GrainController::class, 'dispatchLoad'])->name('grain.dispatch');
        Route::post('/grain/loads', [GrainController::class, 'capture'])->name('grain.capture');
        Route::post('/grain/weighings', [GrainController::class, 'weighing'])->name('grain.weighings.store');
        Route::post('/grain/tickets/{ticket}/assess', [GrainController::class, 'assess'])->name('grain.tickets.assess');
        Route::post('/grain/tickets/{ticket}/void', [GrainController::class, 'void'])->name('grain.tickets.void');
        Route::post('/grain/tickets/{ticket}/photos', [GrainController::class, 'photos'])->name('grain.tickets.photos');
        Route::get('/grain/photos/{photo}', [GrainController::class, 'photo'])->name('grain.photos.show');
        Route::post('/grain/weighbridges', [GrainController::class, 'registerWeighbridge'])->name('grain.weighbridges.store');
        Route::post('/grain/settlements', [GrainController::class, 'storeSettlement'])->name('grain.settlements.store');
        Route::post('/grain/settlements/import', [GrainController::class, 'importSettlement'])->name('grain.settlements.import');
        Route::post('/grain/settlements/{settlement}/match', [GrainController::class, 'matchSettlement'])->name('grain.settlements.match');
        Route::post('/grain/settlements/{settlement}/post', [GrainController::class, 'postSettlement'])->name('grain.settlements.post');

        // Market prices per contract month (feeds and typed in), the hedging register, price position and valuation.
        Route::get('/markets', [MarketController::class, 'index'])->name('markets.home');
        Route::get('/markets/{tab}', [MarketController::class, 'index'])->whereIn('tab', MarketController::TABS)->name('markets');
        Route::post('/markets/prices', [MarketController::class, 'storePrice'])->name('markets.prices.store');
        Route::post('/markets/feeds', [MarketController::class, 'storeFeed'])->name('markets.feeds.store');
        Route::patch('/markets/feeds/{feed}', [MarketController::class, 'storeFeed'])->name('markets.feeds.update');
        Route::post('/markets/feeds/{feed}/fetch', [MarketController::class, 'fetchFeed'])->name('markets.feeds.fetch');
        Route::post('/markets/hedges', [MarketController::class, 'storeHedge'])->name('markets.hedges.store');
        Route::post('/markets/hedges/mark', [MarketController::class, 'markHedges'])->name('markets.hedges.mark');
        Route::post('/markets/hedges/{hedge}/close', [MarketController::class, 'closeHedge'])->name('markets.hedges.close');

        // Phase 2 §8: post-harvest — lots, packhouse, storage, dispatch, traceability, recalls.

        // Phase 2 §7: bookings, pre-start checks, faults and repairs, warranty, documents, fuel and trips, risk.
        Route::get('/machinery/fleet', [MachineFleetController::class, 'index'])->name('machinery.fleet.home');
        Route::get('/machinery/fleet/{tab}', [MachineFleetController::class, 'index'])->whereIn('tab', MachineFleetController::TABS)->name('machinery.fleet');
        Route::post('/machinery/bookings', [MachineFleetController::class, 'requestBooking'])->name('machinery.bookings.store');
        Route::post('/machinery/bookings/{uuid}', [MachineFleetController::class, 'decideBooking'])->name('machinery.bookings.decide');
        Route::get('/machinery/{machine}/affected', [MachineFleetController::class, 'affected'])->name('machinery.affected');
        Route::get('/machinery/{machine}/costs', [MachineFleetController::class, 'costs'])->name('machinery.costs');
        Route::post('/machinery/{machine}/out-of-service', [MachineFleetController::class, 'takeOut'])->name('machinery.out-of-service');
        Route::post('/machinery/{machine}/prestart', [MachineFleetController::class, 'prestart'])->name('machinery.prestart');
        Route::post('/machinery/{machine}/faults', [MachineFleetController::class, 'reportFault'])->name('machinery.faults.store');
        Route::post('/machinery/faults/{fault}/repair', [MachineFleetController::class, 'openRepair'])->name('machinery.faults.repair');
        Route::post('/machinery/faults/{fault}/return', [MachineFleetController::class, 'returnToService'])->name('machinery.faults.return');
        Route::post('/machinery/{machine}/claims', [MachineFleetController::class, 'claim'])->name('machinery.claims.store');
        Route::post('/machinery/claims/{claim}', [MachineFleetController::class, 'settleClaim'])->name('machinery.claims.settle');
        Route::post('/machinery/{machine}/documents', [MachineFleetController::class, 'document'])->name('machinery.documents.store');
        Route::get('/machinery/documents/{document}', [MachineFleetController::class, 'downloadDocument'])->name('machinery.documents.show');
        Route::post('/machinery/{machine}/fuel', [MachineFleetController::class, 'issueFuel'])->name('machinery.fuel');
        Route::post('/machinery/{machine}/trips', [MachineFleetController::class, 'trip'])->name('machinery.trips');

        // ── Module G: tasks ──────────────────────────────────────────────────────────────────
        Route::get('/tasks', [FarmTaskController::class, 'index'])->name('tasks.index');
        Route::post('/tasks', [FarmTaskController::class, 'store'])->name('tasks.store');
        Route::patch('/tasks/{task}', [FarmTaskController::class, 'update'])->name('tasks.update');
        Route::post('/tasks/{task}/transition', [FarmTaskController::class, 'transition'])->name('tasks.transition');
        Route::get('/my-work', [MyWorkController::class, 'index'])->name('my-work');
        Route::post('/my-work/sync', [MyWorkController::class, 'sync'])->name('my-work.sync');

        // ── Module J: documents — farm records are Document Management documents (FarmDocuments). ─

        // ── Module I: alerts and reports ─────────────────────────────────────────────────────
        Route::get('/alerts', [FarmAlertController::class, 'index'])->name('alerts.index');
        Route::post('/alerts/scan', [FarmAlertController::class, 'scan'])->name('alerts.scan');
        Route::post('/alerts/{alert}/acknowledge', [FarmAlertController::class, 'acknowledge'])->name('alerts.acknowledge');
        Route::get('/reports', [AgriReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{report}/export', [AgriReportController::class, 'export'])->name('reports.export');

        // ── Phase 2 · workforce: people, rosters, timesheets, piecework and the payroll handoff are
        // Time & Attendance's (routes/modules/attendance.php); the farm keeps its labour analytics.
        Route::get('/workforce/analytics', [\App\Modules\Agri\Http\Controllers\WorkforceAnalyticsController::class, 'analytics'])->name('workforce.analytics');

        // ── Phase 2 · work orders ────────────────────────────────────────────────────────────
        Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('work-orders.index');
        Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('work-orders.store');
        Route::get('/work-orders/{order}', [WorkOrderController::class, 'show'])->name('work-orders.show');
        Route::patch('/work-orders/{order}', [WorkOrderController::class, 'update'])->name('work-orders.update');
        Route::post('/work-orders/{order}/workers', [WorkOrderController::class, 'addWorker'])->name('work-orders.workers');
        Route::post('/work-orders/{order}/materials', [WorkOrderController::class, 'addMaterial'])->name('work-orders.materials');
        Route::post('/work-orders/{order}/machines', [WorkOrderController::class, 'bookMachine'])->name('work-orders.machines');
        Route::post('/work-orders/{order}/transition', [WorkOrderController::class, 'transition'])->name('work-orders.transition');
        Route::post('/work-orders/{order}/log', [WorkOrderController::class, 'log'])->name('work-orders.log');
        Route::get('/work-orders/{order}/log/{log}/photo', [WorkOrderController::class, 'photo'])->name('work-orders.photo');
        Route::post('/work-orders/{order}/log/{log}/resolve', [WorkOrderController::class, 'resolveIssue'])->name('work-orders.resolve');
        Route::post('/work-orders/{order}/schedule', [WorkOrderController::class, 'schedule'])->name('work-orders.schedule');

        // ── Module H: assistant (read-only) ──────────────────────────────────────────────────
        Route::get('/assistant', [AgriAssistantController::class, 'index'])->name('assistant.index');
        Route::post('/assistant/ask', [AgriAssistantController::class, 'ask'])->name('assistant.ask');
        Route::post('/assistant/report', [AgriAssistantController::class, 'saveReport'])->name('assistant.report');
        Route::post('/assistant/drafts/{draft}', [AgriAssistantController::class, 'decide'])->name('assistant.drafts.decide');
        Route::post('/assistant/references', [AgriAssistantController::class, 'saveReference'])->name('assistant.references.store');
        Route::post('/assistant/references/{reference}', [AgriAssistantController::class, 'saveReference'])->name('assistant.references.update');
        Route::post('/assistant/references/{reference}/approve', [AgriAssistantController::class, 'approveReference'])->name('assistant.references.approve');
    });

/*
| Crops, livestock and the packhouse are production, so they live in Manufacturing & Production
| (/manufacturing/agriculture/...), beside the production cycles, Command Centre and intelligence.
| They stay the farm's registers — same farm permissions, same farm access, same route names — and
| Farm Operations' hubs keep shortcuts to them. Each is in one place only; the old /agri/ addresses
| redirect.
*/
Route::middleware(['module.active:agri', 'module.tier:agri', \App\Modules\Agri\Http\Middleware\FarmTimeInput::class])
    ->prefix('manufacturing/agriculture')->name('agri.')
    ->group(function () {
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
        Route::get('/crops/intelligence', [PrecisionCropController::class, 'index'])->name('crops.precision.home');
        Route::get('/crops/intelligence/{tab}', [PrecisionCropController::class, 'index'])->whereIn('tab', PrecisionCropController::TABS)->name('crops.precision');
        Route::post('/crops/plans/{plan}/stages', [PrecisionCropController::class, 'stage'])->name('crops.stages.store');
        Route::get('/crops/rotation', [PrecisionCropController::class, 'rotation'])->name('crops.rotation');
        Route::post('/crops/input-templates', [PrecisionCropController::class, 'saveTemplate'])->name('crops.templates.store');
        Route::patch('/crops/input-templates/{template}', [PrecisionCropController::class, 'saveTemplate'])->name('crops.templates.update');
        Route::post('/crops/input-templates/{template}/approve', [PrecisionCropController::class, 'approveTemplate'])->name('crops.templates.approve');
        Route::post('/crops/input-templates/{template}/retire', [PrecisionCropController::class, 'retireTemplate'])->name('crops.templates.retire');
        Route::post('/crops/inputs/{input}/correct', [PrecisionCropController::class, 'correctInput'])->name('crops.inputs.correct');
        Route::post('/crops/scouting', [PrecisionCropController::class, 'observe'])->name('crops.scouting.store');
        Route::get('/crops/scouting/photos/{photo}', [PrecisionCropController::class, 'photo'])->name('crops.scouting.photo');
        Route::post('/crops/scouting/{observation}/suggest', [PrecisionCropController::class, 'suggest'])->name('crops.scouting.suggest');
        Route::post('/crops/scouting/{observation}/review', [PrecisionCropController::class, 'review'])->name('crops.scouting.review');
        Route::post('/crops/scouting/{observation}/treatment', [PrecisionCropController::class, 'treat'])->name('crops.scouting.treat');
        Route::post('/crops/scouting/{observation}/close', [PrecisionCropController::class, 'closeObservation'])->name('crops.scouting.close');
        Route::post('/crops/scouting-thresholds', [PrecisionCropController::class, 'saveThreshold'])->name('crops.thresholds.store');
        Route::patch('/crops/scouting-thresholds/{threshold}', [PrecisionCropController::class, 'saveThreshold'])->name('crops.thresholds.update');
        Route::post('/crops/irrigation/zones', [PrecisionCropController::class, 'saveZone'])->name('crops.zones.store');
        Route::patch('/crops/irrigation/zones/{zone}', [PrecisionCropController::class, 'saveZone'])->name('crops.zones.update');
        Route::post('/crops/irrigation/events', [PrecisionCropController::class, 'planIrrigation'])->name('crops.irrigation.store');
        Route::post('/crops/irrigation/events/{event}', [PrecisionCropController::class, 'recordIrrigation'])->name('crops.irrigation.record');
        Route::post('/crops/irrigation/moisture', [PrecisionCropController::class, 'moisture'])->name('crops.moisture.store');
        Route::post('/crops/irrigation/problems', [PrecisionCropController::class, 'irrigationProblem'])->name('crops.irrigation-problems.store');
        Route::post('/crops/irrigation/problems/{exception}/resolve', [PrecisionCropController::class, 'resolveIrrigationProblem'])->name('crops.irrigation-problems.resolve');
        Route::post('/crops/irrigation/recommendations/{recommendation}/plan', [PrecisionCropController::class, 'planRecommendation'])->name('crops.irrigation-recommendations.plan');
        Route::post('/crops/irrigation/recommendations/{recommendation}/dismiss', [PrecisionCropController::class, 'dismissRecommendation'])->name('crops.irrigation-recommendations.dismiss');
        Route::patch('/crops/irrigation/coefficients/{coefficient}', [PrecisionCropController::class, 'saveCoefficient'])->name('crops.coefficients.update');
        Route::post('/crops/irrigation/balance', [PrecisionCropController::class, 'refreshBalance'])->name('crops.irrigation-balance.refresh');
        Route::post('/crops/plans/{plan}/forecasts', [PrecisionCropController::class, 'forecast'])->name('crops.forecasts.store');
        Route::post('/crops/commitments', [PrecisionCropController::class, 'commit'])->name('crops.commitments.store');
        Route::post('/crops/commitments/{commitment}/deliver', [PrecisionCropController::class, 'deliver'])->name('crops.commitments.deliver');
        Route::post('/crops/overheads', [PrecisionCropController::class, 'saveOverhead'])->name('crops.overheads.store');
        Route::patch('/crops/overheads/{rule}', [PrecisionCropController::class, 'saveOverhead'])->name('crops.overheads.update');
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
        Route::post('/livestock/animals/{animal}/breeding', [LivestockController::class, 'breeding'])->name('livestock.animals.breeding');
        Route::post('/livestock/animals/{animal}/birth', [LivestockController::class, 'birth'])->name('livestock.animals.birth');
        Route::post('/livestock/animals/{animal}/wean', [LivestockController::class, 'wean'])->name('livestock.animals.wean');
        Route::post('/livestock/production', [LivestockController::class, 'produce'])->name('livestock.production.store');
        Route::post('/livestock/production/{lot}', [LivestockController::class, 'production'])->name('livestock.production.update');
        Route::post('/livestock/feedings', [LivestockController::class, 'feed'])->name('livestock.feedings.store');
        Route::post('/livestock/rations', [LivestockController::class, 'saveRation'])->name('livestock.rations.store');
        Route::patch('/livestock/rations/{ration}', [LivestockController::class, 'saveRation'])->name('livestock.rations.update');
        Route::post('/livestock/programmes', [LivestockController::class, 'saveProgramme'])->name('livestock.programmes.store');
        Route::patch('/livestock/programmes/{programme}', [LivestockController::class, 'saveProgramme'])->name('livestock.programmes.update');
        Route::post('/livestock/health/{record}/clear', [LivestockController::class, 'clear'])->name('livestock.health.clear');
        Route::get('/packhouse', [PackhouseController::class, 'index'])->name('packhouse.home');
        Route::get('/packhouse/{tab}', [PackhouseController::class, 'index'])->whereIn('tab', PackhouseController::TABS)->name('packhouse');
        Route::post('/packhouse/operations', [PackhouseController::class, 'operate'])->name('packhouse.operations.store');
        Route::post('/packhouse/standards', [PackhouseController::class, 'saveStandard'])->name('packhouse.standards.store');
        Route::post('/packhouse/standards/{standard}', [PackhouseController::class, 'saveStandard'])->name('packhouse.standards.update');
        Route::post('/packhouse/lots/{lot}/move', [PackhouseController::class, 'moveLot'])->name('packhouse.lots.move');
        Route::get('/packhouse/lots/{lot}/trace', [PackhouseController::class, 'trace'])->name('packhouse.lots.trace');
        Route::post('/packhouse/lots/{lot}/recall', [PackhouseController::class, 'recall'])->name('packhouse.lots.recall');
        Route::post('/packhouse/lots/{lot}/write-off', [PackhouseController::class, 'writeOff'])->name('packhouse.lots.write-off');
        Route::post('/packhouse/lots/{lot}/release', [PackhouseController::class, 'release'])->name('packhouse.lots.release');
        Route::post('/packhouse/stores/{store}/range', [PackhouseController::class, 'storeRange'])->name('packhouse.stores.range');
        Route::post('/packhouse/stores/{store}/readings', [PackhouseController::class, 'reading'])->name('packhouse.stores.readings');
        Route::post('/packhouse/readings/{reading}/correct', [PackhouseController::class, 'correct'])->name('packhouse.readings.correct');
        Route::post('/packhouse/orders/{order}/pick', [PackhouseController::class, 'pick'])->whereUuid('order')->name('packhouse.orders.pick');
        Route::post('/packhouse/dispatches/{dispatch}/verify', [PackhouseController::class, 'verify'])->whereUuid('dispatch')->name('packhouse.dispatches.verify');
        Route::post('/packhouse/dispatches/{dispatch}/dispatch', [PackhouseController::class, 'dispatch'])->whereUuid('dispatch')->name('packhouse.dispatches.dispatch');
        Route::post('/packhouse/dispatches/{dispatch}/deliver', [PackhouseController::class, 'deliver'])->whereUuid('dispatch')->name('packhouse.dispatches.deliver');
    });

Route::middleware('auth')->get('/agri/{area}/{rest?}', fn (string $area, ?string $rest = null) => redirect('/manufacturing/agriculture/'.$area.($rest ? '/'.$rest : '').(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301))
    ->whereIn('area', ['crops', 'livestock', 'packhouse'])->where('rest', '.*')->name('agri.moved-to-manufacturing');
