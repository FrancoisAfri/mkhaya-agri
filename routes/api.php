<?php

use App\Core\Auth\Controllers\AuthController;
use App\Modules\CRM\Http\Controllers\Api\CrmApiController;
use App\Modules\Fleet\Controllers\MkhayaMoveApiController;

use App\Modules\Operations\DocumentManagement\Http\Controllers\Api\DocumentApiController;
use Illuminate\Support\Facades\Route;



// Manufacturing integrations (MFG-022): devices and gateways post here, signed with the
// integration's secret. No session; the integration names its company.
Route::post('/manufacturing/integrations/{uuid}/webhook', [\App\Modules\Operations\Manufacturing\Http\Controllers\IntegrationController::class, 'webhook'])
    ->middleware('throttle:600,1')->name('api.manufacturing.integrations.webhook');

// Agricultural production: a farm gateway posts its sensors' readings, collects the commands
// waiting for its farm and reports what the equipment did, with its own secret. No session.
Route::post('/production/gateways/{uuid}/readings', [\App\Modules\Operations\Manufacturing\Http\Controllers\Api\ProductionGatewayController::class, 'readings'])
    ->middleware('throttle:600,1')->name('api.production.gateways.readings');
Route::get('/production/gateways/{uuid}/commands', [\App\Modules\Operations\Manufacturing\Http\Controllers\Api\ProductionGatewayController::class, 'commands'])
    ->middleware('throttle:600,1')->name('api.production.gateways.commands');
Route::post('/production/gateways/{uuid}/commands/{command}', [\App\Modules\Operations\Manufacturing\Http\Controllers\Api\ProductionGatewayController::class, 'report'])
    ->middleware('throttle:600,1')->name('api.production.gateways.report');

// Time & Attendance clock devices: biometric readers, access-control gates and kiosks post clock
// events here with their own secret. No session; the device names its site. The /agri/ path is kept
// for devices set up before attendance moved into Time & Attendance.
Route::post('/attendance/devices/{uuid}/clock', [\App\Modules\HR\Hr\Attendance\Http\Controllers\ClockDeviceController::class, 'receive'])
    ->middleware('throttle:120,1')->name('api.attendance.devices.clock');
Route::post('/agri/devices/{uuid}/clock', [\App\Modules\HR\Hr\Attendance\Http\Controllers\ClockDeviceController::class, 'receive'])
    ->middleware('throttle:120,1')->name('api.agri.devices.clock');
// Phase 2 §8.3: cold-room and silo sensors, through a registered sensor gateway.
Route::post('/agri/devices/{uuid}/readings', [\App\Modules\Agri\Http\Controllers\PackhouseController::class, 'sensorReadings'])
    ->middleware('throttle:120,1')->name('api.agri.devices.readings');
// Phase 2 §14: telematics — machine position, engine hours and odometer from a registered device.
Route::post('/agri/devices/{uuid}/telematics', [\App\Modules\Agri\Http\Controllers\IntegrationController::class, 'telematics'])
    ->middleware('throttle:120,1')->name('api.agri.devices.telematics');
// Grain: a farm weighbridge posts each weighing; first and second weighings are paired into load tickets.
Route::post('/agri/devices/{uuid}/weighings', [\App\Modules\Agri\Http\Controllers\GrainController::class, 'deviceWeighings'])
    ->middleware('throttle:240,1')->name('api.agri.devices.weighings');
// Phase 2 §16C: WhatsApp / SMS / voice messages to the farm assistant, signed by the messaging provider bridge.
Route::post('/agri/messages/{channel}', [\App\Modules\Agri\Http\Controllers\MessagingController::class, 'inbound'])
    ->middleware('throttle:240,1')->name('api.agri.messages');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');


use App\Core\Role\Controllers\RoleController;
use App\Core\Permission\Controllers\PermissionController;

Route::middleware(['auth:sanctum', 'crud.permissions', 'permission:manage roles'])->group(function () {
    Route::apiResource('roles', RoleController::class);
    Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
});

Route::middleware(['auth:sanctum', 'crud.permissions', 'permission:manage permissions'])->group(function () {
    Route::get('permissions', [PermissionController::class, 'index']);
});

// Policy Risk Profiles (PHASE 6)
Route::group([], function () {
    require base_path('routes/modules/policies-risk-profiles.php');
});

// Policy Exceptions, Investigations, Copilot (PHASES 7-9)
Route::middleware('auth:sanctum')->group(function () {
    require base_path('routes/policies-exceptions-investigations-copilot.php');
});

// ── Mkhaya Move shuttle API (service-to-service token auth) ─────────────────
Route::get('/mkhaya-move/health',            [MkhayaMoveApiController::class, 'health'])->middleware('throttle:60,1')->name('api.mkhaya-move.health');
Route::post('/mkhaya-move/shuttle-bookings', [MkhayaMoveApiController::class, 'bookShuttle'])->middleware('throttle:60,1')->name('api.mkhaya-move.shuttle.book');

// ── Payroll payout provider webhook (shared-secret auth) ────────────────────
Route::post('/payroll/payouts/{provider}/webhook', [\App\Modules\HR\Payroll\Controllers\Bureau\PayoutWebhookController::class, 'handle'])->middleware('throttle:120,1')->name('api.payroll.payouts.webhook');

// ── CRM REST API (Sanctum token auth) ──────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('crm')->name('api.crm.')->group(function () {
    Route::get('/{entity}',         [CrmApiController::class, 'index'])->name('index');
    Route::post('/{entity}',        [CrmApiController::class, 'store'])->name('store');
    Route::get('/{entity}/{id}',    [CrmApiController::class, 'show'])->whereNumber('id')->name('show');
    Route::patch('/{entity}/{id}',  [CrmApiController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('/{entity}/{id}', [CrmApiController::class, 'destroy'])->whereNumber('id')->name('destroy');
});

//Route::middleware('auth:sanctum')->group(function () {
//    Route::get('/hr/people', [HRPersonController::class, 'index'])
//        ->middleware('permission:view hr people');
//
//    Route::post('/hr/people', [HRPersonController::class, 'store'])
//        ->middleware('permission:create hr people');
//
//    // Admin-only route
//    Route::delete('/users/{id}', [UserController::class, 'destroy'])
//        ->middleware('role:admin');
//});

// ── Project Management API (/api/v1/pm) ────────────────────────────────────
require __DIR__ . '/api/pm.php';

// ── SHEQ API (/api/v1/sheq) — offline sync for field devices ───────────────
require __DIR__ . '/api/sheq.php';

// Workflow & Automation — versioned API (PRS §21)
require __DIR__ . '/api/workflow.php';

// ── Mkhaya Agri public farm API (/api/agri/v1) — Sanctum tokens per API client ──
require __DIR__ . '/api/agri.php';

// ── Leave Bureau REST API (Sanctum token; the client company is named per request) ──

/*
 * SCIM 2.0 provisioning (RFC 7644, §9.1).
 *
 * The tenant is taken from the bearer token by `scim.token` and never from the request, so a
 * directory connector can only ever act on the company whose token it holds.
 *
 * On `api` rather than `web`: these are machine calls from an identity provider with no session and
 * no CSRF token, and they must not be redirected to a login page when unauthenticated.
 */
Route::middleware([\App\Http\Middleware\AuthenticateScimToken::class])
    ->prefix('scim/v2')->name('scim.')
    ->group(function () {
        Route::get('/Users', [\App\Core\Auth\Controllers\ScimController::class, 'index'])->name('users.index');
        Route::post('/Users', [\App\Core\Auth\Controllers\ScimController::class, 'store'])->name('users.store');
        Route::get('/Users/{id}', [\App\Core\Auth\Controllers\ScimController::class, 'show'])->name('users.show');
        Route::put('/Users/{id}', [\App\Core\Auth\Controllers\ScimController::class, 'replace'])->name('users.replace');
        Route::patch('/Users/{id}', [\App\Core\Auth\Controllers\ScimController::class, 'patch'])->name('users.patch');
        Route::delete('/Users/{id}', [\App\Core\Auth\Controllers\ScimController::class, 'destroy'])->name('users.destroy');
    });

/*
 * ── DOCUMENT MANAGEMENT API ───────────────────────────────────────────────────
 *
 * The repository as a service, so a module does not build its own document storage. Every endpoint
 * runs through the same services, policies and visibility scope as the screens — a second doorway
 * into one building rather than a second building.
 *
 * Gated on module entitlement as well as authentication: a company that has not bought Document
 * Management must not be able to reach it through an API token either.
 */
Route::middleware(['auth:sanctum', 'module.active:document_management'])
    ->prefix('document-management')
    ->name('api.documents.')
    ->group(function () {
        Route::get('/documents', [DocumentApiController::class, 'index'])->name('index');
        Route::post('/documents', [DocumentApiController::class, 'store'])->name('store');
        Route::get('/documents/{document}', [DocumentApiController::class, 'show'])->name('show');
        Route::get('/documents/{document}/download', [DocumentApiController::class, 'download'])->name('download');
        Route::get('/documents/{document}/history', [DocumentApiController::class, 'history'])->name('history');
        Route::post('/documents/{document}/versions', [DocumentApiController::class, 'storeVersion'])->name('versions.store');
        Route::put('/documents/{document}/expiry', [DocumentApiController::class, 'setExpiry'])->name('expiry');
        Route::post('/documents/{document}/archive', [DocumentApiController::class, 'archive'])->name('archive');
        Route::post('/documents/{document}/request-approval', [DocumentApiController::class, 'requestApproval'])->name('approval');

        Route::get('/folders', [DocumentApiController::class, 'folders'])->name('folders.index');
        Route::post('/folders', [DocumentApiController::class, 'storeFolder'])->name('folders.store');
    });
