<?php

use App\Modules\Fleet\Controllers\FleetAIController;
use App\Modules\Fleet\Controllers\FleetBookingApprovalQueueController;
use App\Modules\Fleet\Controllers\FleetMyBookingController;
use App\Modules\Fleet\Controllers\FleetMyDashboardController;
use App\Modules\Fleet\Controllers\FleetAnalyticsController;
use App\Modules\Fleet\Controllers\FleetIntegrationController;
use App\Modules\Fleet\Controllers\FleetAlertsController;
use App\Modules\Fleet\Controllers\FleetBookingController;
use App\Modules\Fleet\Controllers\FleetCardController;
use App\Modules\Fleet\Controllers\FleetDashboardController;
use App\Modules\Fleet\Controllers\FleetDocumentController;
use App\Modules\Fleet\Controllers\FleetDriverController;
use App\Modules\Fleet\Controllers\FleetFuelController;
use App\Modules\Fleet\Controllers\FleetJobCardApprovalController;
use App\Modules\Fleet\Controllers\FleetJobCardController;
use App\Modules\Fleet\Controllers\FleetJobCardReportController;
use App\Modules\Fleet\Controllers\FleetJobCardSetupController;
use App\Modules\Fleet\Controllers\FleetMaintenanceController;
use App\Modules\Fleet\Controllers\FleetMobileController;
use App\Modules\Fleet\Controllers\FleetProjectController;
use App\Modules\Fleet\Controllers\FleetReportController;
use App\Modules\Fleet\Controllers\FleetSecurityController;
use App\Modules\Fleet\Controllers\FleetSetupController;
use App\Modules\Fleet\Controllers\FleetSettingsController;
use App\Modules\Fleet\Controllers\FleetVehicleController;
use App\Http\Controllers\ModuleHubController;
use Illuminate\Support\Facades\Route;

Route::middleware(['module.active:fleet_management', 'module.tier:fleet_management'])->prefix('fleet')->name('fleet.')->group(function () {

    // ── Navigation hubs — role-filtered feature-card landing pages ────────────
    foreach (['my-fleet-hub' => 'my_fleet', 'approvals-hub' => 'approvals', 'manage-hub' => 'manage', 'insights-hub' => 'insights'] as $hubPath => $group) {
        Route::get("/{$hubPath}", [ModuleHubController::class, 'show'])
            ->defaults('module', 'fleet_management')->defaults('group', $group)->name('hub.' . $group);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('/', [FleetDashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('crud.permissions:fleet.dashboard.view');

    // ── My Dashboard (self-service, all employees) ────────────────────────────
    Route::get('/my-dashboard', [FleetMyDashboardController::class, 'index'])->name('my-dashboard');

    // ── My Bookings — self-service booking creation (eligible drivers only) ────
    Route::get('/my-bookings/create', [FleetMyBookingController::class, 'create'])->name('my-bookings.create');

    // ── Approval Queue ────────────────────────────────────────────────────────
    Route::prefix('approvals')->name('approvals.')->group(function () {
        Route::get('/',                                          [FleetBookingApprovalQueueController::class, 'index'])->name('index');
        Route::patch('/steps/{approval}/approve',                [FleetBookingApprovalQueueController::class, 'approve'])->name('steps.approve');
        Route::patch('/steps/{approval}/reject',                 [FleetBookingApprovalQueueController::class, 'reject'])->name('steps.reject');
        Route::post('/delegations',                              [FleetBookingApprovalQueueController::class, 'storeDelegation'])->name('delegations.store');
        Route::delete('/delegations/{delegation}',               [FleetBookingApprovalQueueController::class, 'destroyDelegation'])->name('delegations.destroy');
    });

    // ── Vehicles ──────────────────────────────────────────────────────────────
    Route::prefix('vehicles')->name('vehicles.')->group(function () {
        Route::get('/',                [FleetVehicleController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.vehicles.view');
        Route::post('/',               [FleetVehicleController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.vehicles.create');
        Route::get('/export',              [FleetVehicleController::class, 'export'])->name('export')->middleware('crud.permissions:fleet.vehicles.view');
        Route::post('/import',             [FleetVehicleController::class, 'import'])->name('import')->middleware('crud.permissions:fleet.vehicles.import');
        Route::get('/pending-approvals',   [FleetVehicleController::class, 'pendingApprovals'])->name('pending-approvals')->middleware('crud.permissions:fleet.vehicles.approve');
        Route::patch('/{vehicle}/approve', [FleetVehicleController::class, 'approveVehicle'])->name('approve')->middleware('crud.permissions:fleet.vehicles.approve');
        Route::patch('/{vehicle}/reject',  [FleetVehicleController::class, 'rejectVehicle'])->name('reject')->middleware('crud.permissions:fleet.vehicles.approve');
        Route::get('/{vehicle}',           [FleetVehicleController::class, 'show'])->name('show')->middleware('crud.permissions:fleet.vehicles.view');
        Route::patch('/{vehicle}',     [FleetVehicleController::class, 'update'])->name('update')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::patch('/{vehicle}/status',    [FleetVehicleController::class, 'changeStatus'])->name('change-status')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::post('/{vehicle}/dispose',    [FleetVehicleController::class, 'dispose'])->name('dispose')->middleware('crud.permissions:fleet.vehicles.dispose');
        Route::post('/{vehicle}/images',     [FleetVehicleController::class, 'uploadImage'])->name('images.upload')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::delete('/images/{image}',     [FleetVehicleController::class, 'deleteImage'])->name('images.delete')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::post('/{vehicle}/keys',       [FleetVehicleController::class, 'storeKey'])->name('keys.store')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::delete('/keys/{key}',         [FleetVehicleController::class, 'deleteKey'])->name('keys.delete')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::post('/{vehicle}/notes',                    [FleetVehicleController::class, 'storeNote'])->name('notes.store')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::patch('/notes/{note}',                      [FleetVehicleController::class, 'updateNote'])->name('notes.update')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::delete('/notes/{note}',                     [FleetVehicleController::class, 'destroyNote'])->name('notes.destroy')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::post('/{vehicle}/reminders',                [FleetVehicleController::class, 'storeReminder'])->name('reminders.store')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::patch('/reminders/{reminder}',              [FleetVehicleController::class, 'updateReminder'])->name('reminders.update')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::delete('/reminders/{reminder}',             [FleetVehicleController::class, 'destroyReminder'])->name('reminders.destroy')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::post('/{vehicle}/fire-extinguishers',       [FleetVehicleController::class, 'storeFireExtinguisher'])->name('fire-extinguishers.store')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::patch('/fire-extinguishers/{extinguisher}', [FleetVehicleController::class, 'updateFireExtinguisher'])->name('fire-extinguishers.update')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::delete('/fire-extinguishers/{extinguisher}',[FleetVehicleController::class, 'destroyFireExtinguisher'])->name('fire-extinguishers.destroy')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::post('/{vehicle}/general-costs',       [FleetVehicleController::class, 'storeGeneralCost'])->name('general-costs.store')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::patch('/general-costs/{generalCost}',  [FleetVehicleController::class, 'updateGeneralCost'])->name('general-costs.update')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::delete('/general-costs/{generalCost}', [FleetVehicleController::class, 'destroyGeneralCost'])->name('general-costs.destroy')->middleware('crud.permissions:fleet.vehicles.edit');
        Route::delete('/{vehicle}',                        [FleetVehicleController::class, 'destroy'])->name('destroy')->middleware('crud.permissions:fleet.vehicles.delete');
    });

    // ── Drivers ───────────────────────────────────────────────────────────────
    Route::prefix('drivers')->name('drivers.')->group(function () {
        Route::get('/',                              [FleetDriverController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.drivers.view');
        Route::post('/',                             [FleetDriverController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.drivers.create');
        Route::patch('/{driver}',                    [FleetDriverController::class, 'update'])->name('update')->middleware('crud.permissions:fleet.drivers.edit');
        Route::delete('/{driver}',                   [FleetDriverController::class, 'destroy'])->name('destroy')->middleware('crud.permissions:fleet.drivers.delete');
        Route::patch('/hr-people/{personId}/eligible', [FleetDriverController::class, 'toggleEligible'])->name('eligible.toggle')->middleware('crud.permissions:fleet.drivers.edit');
    });

    // ── Bookings ──────────────────────────────────────────────────────────────
    Route::prefix('bookings')->name('bookings.')->group(function () {
        Route::get('/',                                      [FleetBookingController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.bookings.view');
        Route::post('/',                                     [FleetBookingController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.bookings.create');
        Route::get('/available-vehicles',                    [FleetBookingController::class, 'availableVehicles'])->name('available-vehicles')->middleware('crud.permissions:fleet.bookings.view');
        Route::post('/bulk-approve',                         [FleetBookingController::class, 'bulkApprove'])->name('bulk-approve')->middleware('crud.permissions:fleet.bookings.approve');
        Route::patch('/{booking}/approve',                   [FleetBookingController::class, 'approve'])->name('approve')->middleware('crud.permissions:fleet.bookings.approve');
        Route::patch('/{booking}/security-check',            [FleetBookingController::class, 'securityCheck'])->name('security-check')->middleware('crud.permissions:fleet.bookings.manage');
        Route::post('/{booking}/collect',                    [FleetBookingController::class, 'collect'])->name('collect')->middleware('crud.permissions:fleet.bookings.collect');
        Route::patch('/{booking}/mark-returned',             [FleetBookingController::class, 'markReturned'])->name('mark-returned')->middleware('crud.permissions:fleet.bookings.collect');
        Route::post('/{booking}/return-vehicle',             [FleetBookingController::class, 'returnVehicle'])->name('return-vehicle')->middleware('crud.permissions:fleet.bookings.collect');
        Route::post('/{booking}/reset-approval-steps',       [FleetBookingController::class, 'resetApprovalSteps'])->name('reset-approval-steps')->middleware('crud.permissions:fleet.bookings.approve');
        Route::patch('/{booking}/check-out',                 [FleetBookingController::class, 'checkOut'])->name('check-out')->middleware('crud.permissions:fleet.bookings.manage');
        Route::patch('/{booking}/check-in',                  [FleetBookingController::class, 'checkIn'])->name('check-in')->middleware('crud.permissions:fleet.bookings.check-in');
        Route::patch('/{booking}/cancel',                    [FleetBookingController::class, 'cancel'])->name('cancel')->middleware('crud.permissions:fleet.bookings.cancel');
        Route::post('/{booking}/inspections',                [FleetBookingController::class, 'storeInspection'])->name('inspections.store')->middleware('crud.permissions:fleet.bookings.manage');
        Route::post('/{booking}/tollgates',                  [FleetBookingController::class, 'logTollgate'])->name('tollgates.store')->middleware('crud.permissions:fleet.bookings.manage');
        Route::post('/{booking}/incidents',                  [FleetBookingController::class, 'reportIncident'])->name('incidents.store')->middleware('crud.permissions:fleet.bookings.manage');
    });

    // ── Fleet Cards ───────────────────────────────────────────────────────────
    Route::prefix('cards')->name('cards.')->group(function () {
        Route::get('/',                                       [FleetCardController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.cards.view');
        Route::post('/',                                      [FleetCardController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.cards.create');
        Route::patch('/{card}',                               [FleetCardController::class, 'update'])->name('update')->middleware('crud.permissions:fleet.cards.edit');
        Route::delete('/{card}',                              [FleetCardController::class, 'destroy'])->name('destroy')->middleware('crud.permissions:fleet.cards.delete');
        Route::post('/{card}/transactions',                   [FleetCardController::class, 'storeTransaction'])->name('transactions.store')->middleware('crud.permissions:fleet.cards.transactions.create');
        Route::patch('/transactions/{transaction}/flag',      [FleetCardController::class, 'flagTransaction'])->name('transactions.flag')->middleware('crud.permissions:fleet.cards.transactions.flag');
        Route::patch('/transactions/{transaction}/reconcile', [FleetCardController::class, 'reconcileTransaction'])->name('transactions.reconcile')->middleware('crud.permissions:fleet.cards.transactions.reconcile');
    });

    // ── Documents ─────────────────────────────────────────────────────────────
    Route::prefix('documents')->name('documents.')->group(function () {
        Route::get('/',                          [FleetDocumentController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.documents.view');
        Route::post('/',                         [FleetDocumentController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.documents.create');
        Route::patch('/{document}',              [FleetDocumentController::class, 'update'])->name('update')->middleware('crud.permissions:fleet.documents.edit');
        Route::delete('/{document}',             [FleetDocumentController::class, 'destroy'])->name('destroy')->middleware('crud.permissions:fleet.documents.delete');
        Route::get('/{document}/download',       [FleetDocumentController::class, 'download'])->name('download')->middleware('crud.permissions:fleet.documents.view');
    });

    // ── Maintenance ───────────────────────────────────────────────────────────
    Route::prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('/',                [FleetMaintenanceController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.maintenance.view');
        Route::post('/',               [FleetMaintenanceController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.maintenance.create');
        Route::patch('/{maintenance}', [FleetMaintenanceController::class, 'update'])->name('update')->middleware('crud.permissions:fleet.maintenance.edit');
        Route::delete('/{maintenance}',[FleetMaintenanceController::class, 'destroy'])->name('destroy')->middleware('crud.permissions:fleet.maintenance.delete');
    });

    // ── Fuel ─────────────────────────────────────────────────────────────────
    Route::prefix('fuel')->name('fuel.')->group(function () {
        Route::get('/',         [FleetFuelController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.fuel.view');
        Route::post('/',        [FleetFuelController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.fuel.create');
        Route::delete('/{fuel}',[FleetFuelController::class, 'destroy'])->name('destroy')->middleware('crud.permissions:fleet.fuel.delete');
    });

    // ── Reports (FR-6) ───────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',                                              [FleetReportController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.reports.view');
        Route::get('/generate/{key}',                               [FleetReportController::class, 'generate'])->name('generate')->middleware('crud.permissions:fleet.reports.view');
        Route::get('/export/{key}/{format}',                        [FleetReportController::class, 'export'])->name('export')->middleware('crud.permissions:fleet.reports.export');
        Route::get('/scheduled',                                    [FleetReportController::class, 'scheduledReports'])->name('scheduled.index')->middleware('crud.permissions:fleet.reports.schedule');
        Route::post('/scheduled',                                   [FleetReportController::class, 'storeScheduledReport'])->name('scheduled.store')->middleware('crud.permissions:fleet.reports.schedule');
        Route::patch('/scheduled/{scheduledReport}',                [FleetReportController::class, 'updateScheduledReport'])->name('scheduled.update')->middleware('crud.permissions:fleet.reports.schedule');
        Route::delete('/scheduled/{scheduledReport}',               [FleetReportController::class, 'destroyScheduledReport'])->name('scheduled.destroy')->middleware('crud.permissions:fleet.reports.schedule');
    });

    // ── Alerts ────────────────────────────────────────────────────────────────
    Route::get('/alerts', [FleetAlertsController::class, 'index'])->name('alerts')->middleware('crud.permissions:fleet.vehicles.view');

    // ── Security check ────────────────────────────────────────────────────────
    Route::get('/security-check', [FleetSecurityController::class, 'index'])->name('security-check')->middleware('crud.permissions:fleet.bookings.manage');
    Route::post('/security-check/{booking}/inspection', [FleetSecurityController::class, 'storeInspection'])->name('security-check.inspection')->middleware('crud.permissions:fleet.bookings.manage');
    Route::post('/security-check/{booking}/turn-back', [FleetSecurityController::class, 'turnBack'])->name('security-check.turn-back')->middleware('crud.permissions:fleet.bookings.manage');

    // ── Projects ──────────────────────────────────────────────────────────────
    Route::prefix('projects')->name('projects.')->middleware('crud.permissions:fleet.bookings.manage')->group(function () {
        Route::get('/',          [FleetProjectController::class, 'index'])->name('index');
        Route::post('/',         [FleetProjectController::class, 'store'])->name('store');
        Route::patch('/{id}',    [FleetProjectController::class, 'update'])->name('update');
        Route::delete('/{id}',   [FleetProjectController::class, 'destroy'])->name('destroy');
    });

    // ── Setup (makes, models, types, incident types, safes, approval steps) ──
    Route::prefix('setup')->name('setup.')->middleware('crud.permissions:fleet.settings.edit')->group(function () {
        Route::get('/',                              [FleetSetupController::class, 'index'])->name('index');
        Route::post('/makes',                        [FleetSetupController::class, 'storeMake'])->name('makes.store');
        Route::patch('/makes/{id}',                  [FleetSetupController::class, 'updateMake'])->name('makes.update');
        Route::delete('/makes/{id}',                 [FleetSetupController::class, 'destroyMake'])->name('makes.destroy');
        Route::post('/models',                       [FleetSetupController::class, 'storeModel'])->name('models.store');
        Route::patch('/models/{id}',                 [FleetSetupController::class, 'updateModel'])->name('models.update');
        Route::delete('/models/{id}',                [FleetSetupController::class, 'destroyModel'])->name('models.destroy');
        Route::post('/vehicle-types',                [FleetSetupController::class, 'storeVehicleType'])->name('vehicle-types.store');
        Route::patch('/vehicle-types/{id}',          [FleetSetupController::class, 'updateVehicleType'])->name('vehicle-types.update');
        Route::delete('/vehicle-types/{id}',         [FleetSetupController::class, 'destroyVehicleType'])->name('vehicle-types.destroy');
        Route::post('/fuel-types',                   [FleetSetupController::class, 'storeFuelType'])->name('fuel-types.store');
        Route::patch('/fuel-types/{id}',             [FleetSetupController::class, 'updateFuelType'])->name('fuel-types.update');
        Route::delete('/fuel-types/{id}',            [FleetSetupController::class, 'destroyFuelType'])->name('fuel-types.destroy');
        Route::post('/incident-types',               [FleetSetupController::class, 'storeIncidentType'])->name('incident-types.store');
        Route::patch('/incident-types/{id}',         [FleetSetupController::class, 'updateIncidentType'])->name('incident-types.update');
        Route::delete('/incident-types/{id}',        [FleetSetupController::class, 'destroyIncidentType'])->name('incident-types.destroy');
        Route::post('/key-safes',                    [FleetSetupController::class, 'storeKeySafe'])->name('key-safes.store');
        Route::patch('/key-safes/{id}',              [FleetSetupController::class, 'updateKeySafe'])->name('key-safes.update');
        Route::delete('/key-safes/{id}',             [FleetSetupController::class, 'destroyKeySafe'])->name('key-safes.destroy');
        Route::post('/filling-stations',             [FleetSetupController::class, 'storeFillingStation'])->name('filling-stations.store');
        Route::patch('/filling-stations/{id}',       [FleetSetupController::class, 'updateFillingStation'])->name('filling-stations.update');
        Route::delete('/filling-stations/{id}',      [FleetSetupController::class, 'destroyFillingStation'])->name('filling-stations.destroy');
        Route::post('/card-types',                   [FleetSetupController::class, 'storeCardType'])->name('card-types.store');
        Route::patch('/card-types/{id}',             [FleetSetupController::class, 'updateCardType'])->name('card-types.update');
        Route::delete('/card-types/{id}',            [FleetSetupController::class, 'destroyCardType'])->name('card-types.destroy');
        Route::post('/licence-types',                [FleetSetupController::class, 'storeLicenceType'])->name('licence-types.store');
        Route::patch('/licence-types/{id}',          [FleetSetupController::class, 'updateLicenceType'])->name('licence-types.update');
        Route::delete('/licence-types/{id}',         [FleetSetupController::class, 'destroyLicenceType'])->name('licence-types.destroy');
        Route::post('/approval-steps',               [FleetSetupController::class, 'storeApprovalStep'])->name('approval-steps.store');
        Route::patch('/approval-steps/{id}',         [FleetSetupController::class, 'updateApprovalStep'])->name('approval-steps.update');
        Route::delete('/approval-steps/{id}',        [FleetSetupController::class, 'destroyApprovalStep'])->name('approval-steps.destroy');
    });

    // ── BI Analytics ─────────────────────────────────────────────────────────
    Route::get('/analytics', [FleetAnalyticsController::class, 'index'])->name('analytics')->middleware('crud.permissions:fleet.reports.view');

    // ── Settings ──────────────────────────────────────────────────────────────
    Route::get('/settings',  [FleetSettingsController::class, 'index'])->name('settings')->middleware('crud.permissions:fleet.settings.view');
    Route::patch('/settings',[FleetSettingsController::class, 'update'])->name('settings.update')->middleware('crud.permissions:fleet.settings.edit');

    // ── Integrations (FR-7) ───────────────────────────────────────────────────
    Route::prefix('integrations')->name('integrations.')->middleware('crud.permissions:fleet.settings.edit')->group(function () {
        Route::get('/',                    [FleetIntegrationController::class, 'index'])->name('index');
        Route::post('/',                   [FleetIntegrationController::class, 'store'])->name('store');
        Route::patch('/{id}',              [FleetIntegrationController::class, 'update'])->name('update');
        Route::patch('/{id}/toggle',       [FleetIntegrationController::class, 'toggleActive'])->name('toggle');
        Route::post('/{id}/test',          [FleetIntegrationController::class, 'testConnection'])->name('test');
    });

    // ── FR-5 Mobile / QR / Notifications ─────────────────────────────────────
    Route::get('/vehicles/{vehicle}/qr',                   [FleetMobileController::class, 'vehicleQr'])->name('vehicles.qr')->middleware('crud.permissions:fleet.vehicles.view');
    Route::get('/mobile/inspection/{bookingId}',            [FleetMobileController::class, 'mobileInspection'])->name('mobile.inspection')->middleware('crud.permissions:fleet.bookings.manage');
    Route::get('/notifications/preferences',               [FleetMobileController::class, 'notificationPreferences'])->name('notifications.preferences');
    Route::post('/notifications/preferences',              [FleetMobileController::class, 'saveNotificationPreferences'])->name('notifications.preferences.save');
    Route::post('/push/subscribe',                         [FleetMobileController::class, 'subscribePush'])->name('push.subscribe');
    Route::post('/push/unsubscribe',                       [FleetMobileController::class, 'unsubscribePush'])->name('push.unsubscribe');

    // ── Job cards / workshop ──────────────────────────────────────────────────
    Route::prefix('job-cards')->name('job-cards.')->group(function () {
        // Setup must precede /{jobCard} so the literal segments aren't swallowed.
        Route::prefix('setup')->name('setup.')->group(function () {
            Route::get('/',                              [FleetJobCardSetupController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::post('/settings',                     [FleetJobCardSetupController::class, 'updateSettings'])->name('settings')->middleware('crud.permissions:fleet.job_cards.configure');

            Route::post('/service-types',                [FleetJobCardSetupController::class, 'storeServiceType'])->name('service-types.store')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::patch('/service-types/{serviceType}', [FleetJobCardSetupController::class, 'updateServiceType'])->name('service-types.update')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::delete('/service-types/{serviceType}',[FleetJobCardSetupController::class, 'destroyServiceType'])->name('service-types.destroy')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::post('/service-types/{serviceType}/toggle', [FleetJobCardSetupController::class, 'toggleServiceType'])->name('service-types.toggle')->middleware('crud.permissions:fleet.job_cards.configure');

            Route::post('/part-categories',              [FleetJobCardSetupController::class, 'storeCategory'])->name('part-categories.store')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::patch('/part-categories/{category}',  [FleetJobCardSetupController::class, 'updateCategory'])->name('part-categories.update')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::delete('/part-categories/{category}', [FleetJobCardSetupController::class, 'destroyCategory'])->name('part-categories.destroy')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::post('/part-categories/{category}/toggle', [FleetJobCardSetupController::class, 'toggleCategory'])->name('part-categories.toggle')->middleware('crud.permissions:fleet.job_cards.configure');

            Route::post('/parts',                        [FleetJobCardSetupController::class, 'storePart'])->name('parts.store')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::patch('/parts/{part}',                [FleetJobCardSetupController::class, 'updatePart'])->name('parts.update')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::delete('/parts/{part}',               [FleetJobCardSetupController::class, 'destroyPart'])->name('parts.destroy')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::post('/parts/{part}/toggle',          [FleetJobCardSetupController::class, 'togglePart'])->name('parts.toggle')->middleware('crud.permissions:fleet.job_cards.configure');

            Route::post('/approval-steps',               [FleetJobCardSetupController::class, 'storeStep'])->name('approval-steps.store')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::patch('/approval-steps/{step}',       [FleetJobCardSetupController::class, 'updateStep'])->name('approval-steps.update')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::delete('/approval-steps/{step}',      [FleetJobCardSetupController::class, 'destroyStep'])->name('approval-steps.destroy')->middleware('crud.permissions:fleet.job_cards.configure');
            Route::post('/approval-steps/{step}/toggle', [FleetJobCardSetupController::class, 'toggleStep'])->name('approval-steps.toggle')->middleware('crud.permissions:fleet.job_cards.configure');
        });

        Route::get('/approvals',                         [FleetJobCardApprovalController::class, 'index'])->name('approvals')->middleware('crud.permissions:fleet.job_cards.approve');

        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('/',                              [FleetJobCardReportController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.job_cards.view');
            Route::get('/{report}',                      [FleetJobCardReportController::class, 'show'])->name('show')->middleware('crud.permissions:fleet.job_cards.view');
            Route::get('/{report}/export',               [FleetJobCardReportController::class, 'export'])->name('export')->middleware('crud.permissions:fleet.job_cards.view');
        });

        Route::get('/my-cards',                          [FleetJobCardController::class, 'myCards'])->name('my-cards');
        Route::get('/search',                            [FleetJobCardController::class, 'search'])->name('search')->middleware('crud.permissions:fleet.job_cards.view');
        Route::get('/create',                            [FleetJobCardController::class, 'create'])->name('create')->middleware('crud.permissions:fleet.job_cards.create');

        Route::get('/',                                  [FleetJobCardController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.job_cards.view');
        Route::post('/',                                 [FleetJobCardController::class, 'store'])->name('store')->middleware('crud.permissions:fleet.job_cards.create');

        Route::get('/{jobCard}',                         [FleetJobCardController::class, 'show'])->name('show')->middleware('crud.permissions:fleet.job_cards.view');
        Route::patch('/{jobCard}',                       [FleetJobCardController::class, 'update'])->name('update')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::delete('/{jobCard}',                      [FleetJobCardController::class, 'destroy'])->name('destroy')->middleware('crud.permissions:fleet.job_cards.delete');
        Route::get('/{jobCard}/history',                 [FleetJobCardController::class, 'history'])->name('history')->middleware('crud.permissions:fleet.job_cards.view');
        Route::get('/{jobCard}/print',                   [FleetJobCardController::class, 'print'])->name('print')->middleware('crud.permissions:fleet.job_cards.view');

        // Lifecycle
        Route::post('/{jobCard}/submit',                 [FleetJobCardController::class, 'submit'])->name('submit')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::post('/{jobCard}/start',                  [FleetJobCardController::class, 'start'])->name('start')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::post('/{jobCard}/mechanic-feedback',      [FleetJobCardController::class, 'mechanicFeedback'])->name('mechanic-feedback');
        Route::post('/{jobCard}/close',                  [FleetJobCardController::class, 'close'])->name('close')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::post('/{jobCard}/cancel',                 [FleetJobCardController::class, 'cancel'])->name('cancel')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::post('/{jobCard}/approve',                [FleetJobCardApprovalController::class, 'approve'])->name('approve')->middleware('crud.permissions:fleet.job_cards.approve');
        Route::post('/{jobCard}/reject',                 [FleetJobCardApprovalController::class, 'reject'])->name('reject')->middleware('crud.permissions:fleet.job_cards.approve');

        // Parts, notes, images, documents
        Route::post('/{jobCard}/parts',                  [FleetJobCardController::class, 'addPart'])->name('parts.store')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::delete('/{jobCard}/parts/{part}',         [FleetJobCardController::class, 'removePart'])->name('parts.destroy')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::post('/{jobCard}/notes',                  [FleetJobCardController::class, 'addNote'])->name('notes.store');
        Route::delete('/{jobCard}/notes/{note}',         [FleetJobCardController::class, 'deleteNote'])->name('notes.destroy')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::post('/{jobCard}/images',                 [FleetJobCardController::class, 'addImage'])->name('images.store');
        Route::delete('/{jobCard}/images/{image}',       [FleetJobCardController::class, 'deleteImage'])->name('images.destroy')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::get('/{jobCard}/images/{image}/download', [FleetJobCardController::class, 'downloadImage'])->name('images.download')->middleware('crud.permissions:fleet.job_cards.view');
        Route::post('/{jobCard}/documents',              [FleetJobCardController::class, 'addDocument'])->name('documents.store');
        Route::delete('/{jobCard}/documents/{document}', [FleetJobCardController::class, 'deleteDocument'])->name('documents.destroy')->middleware('crud.permissions:fleet.job_cards.edit');
        Route::get('/{jobCard}/documents/{document}/download', [FleetJobCardController::class, 'downloadDocument'])->name('documents.download')->middleware('crud.permissions:fleet.job_cards.view');
    });

    // ── FR-4 AI ───────────────────────────────────────────────────────────────
    Route::prefix('ai')->name('ai.')->group(function () {
        Route::get('/',                                   [FleetAIController::class, 'index'])->name('index')->middleware('crud.permissions:fleet.ai.view');
        Route::get('/providers',                          [FleetAIController::class, 'providers'])->name('providers')->middleware('crud.permissions:fleet.ai.view');
        // Chat (FR-4.4)
        Route::post('/chat',                              [FleetAIController::class, 'chat'])->name('chat')->middleware('crud.permissions:fleet.ai.chat');
        Route::get('/sessions',                           [FleetAIController::class, 'sessions'])->name('sessions')->middleware('crud.permissions:fleet.ai.view');
        Route::get('/sessions/{sessionId}/history',       [FleetAIController::class, 'history'])->name('history')->middleware('crud.permissions:fleet.ai.view');
        Route::delete('/sessions/{sessionId}',            [FleetAIController::class, 'deleteSession'])->name('session.delete')->middleware('crud.permissions:fleet.ai.view');
        Route::post('/actions',                           [FleetAIController::class, 'executeAction'])->name('actions')->middleware('crud.permissions:fleet.ai.chat');
        // Predictions (FR-4.1)
        Route::get('/predictions',                        [FleetAIController::class, 'predictions'])->name('predictions')->middleware('crud.permissions:fleet.ai.view');
        Route::post('/predictions/run',                   [FleetAIController::class, 'runPrediction'])->name('predictions.run')->middleware('crud.permissions:fleet.ai.chat');
        // Damage detection (FR-4.2)
        Route::post('/analyze-photo',                     [FleetAIController::class, 'analyzePhoto'])->name('analyze-photo')->middleware('crud.permissions:fleet.ai.chat');
        Route::post('/compare-photos',                    [FleetAIController::class, 'comparePhotos'])->name('compare-photos')->middleware('crud.permissions:fleet.ai.chat');
        Route::patch('/analyses/{analysis}/confirm',      [FleetAIController::class, 'confirmAnalysis'])->name('analyses.confirm')->middleware('crud.permissions:fleet.ai.chat');
        // Anomalies (FR-4.3)
        Route::get('/anomalies',                          [FleetAIController::class, 'anomalies'])->name('anomalies')->middleware('crud.permissions:fleet.ai.view');
        Route::patch('/anomalies/{anomaly}/review',       [FleetAIController::class, 'reviewAnomaly'])->name('anomalies.review')->middleware('crud.permissions:fleet.ai.chat');
    });
});
