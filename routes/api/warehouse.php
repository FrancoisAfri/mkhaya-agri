<?php

use App\Modules\Operations\WarehouseManagement\Http\Controllers\Api\EventApiController;
use App\Modules\Operations\WarehouseManagement\Http\Controllers\Api\OrderApiController;
use App\Modules\Operations\WarehouseManagement\Http\Middleware\Idempotent;
use App\Modules\Operations\WarehouseManagement\Http\Middleware\RequireTenant;
use Illuminate\Support\Facades\Route;

/*
| Warehouse Management & Intelligence API (WMS-060). Sanctum tokens; the company comes from the
| token's user and a token without one is refused; module entitlement applies as on the web.
| Version 1 (WMS-172): rate-limited per token, retriable commands honour Idempotency-Key. The
| contract is resources/api/warehouse-v1.yaml, served at /api/v1/warehouse/openapi.yaml.
*/
Route::get('api/v1/warehouse/openapi.yaml', fn () => response()->file(resource_path('api/warehouse-v1.yaml'), ['Content-Type' => 'application/yaml']))
    ->middleware('throttle:60,1')->name('api.warehouse.openapi');

Route::middleware([\App\Modules\Operations\WarehouseManagement\Http\Middleware\MalformedIdentifierIsNotFound::class, 'api', 'auth:sanctum', \App\Modules\Operations\WarehouseManagement\Http\Middleware\RefuseInactiveAccount::class, RequireTenant::class, 'module.active:warehouse_management', 'throttle:warehouse-api', Idempotent::class,
    \App\Modules\Operations\WarehouseManagement\Http\Middleware\AuditApiCommands::class])
    ->prefix('api/v1/warehouse')->name('api.warehouse.')
    ->group(function () {
        Route::post('/orders', [OrderApiController::class, 'store'])->name('orders.store');
        Route::get('/orders/{ref}', [OrderApiController::class, 'show'])->name('orders.show');
        Route::post('/orders/{ref}/cancel', [OrderApiController::class, 'cancel'])->name('orders.cancel');
        Route::get('/stock', [OrderApiController::class, 'stock'])->name('stock');

        Route::get('/event-types', [EventApiController::class, 'types'])->name('events.types');
        Route::get('/events', [EventApiController::class, 'feed'])->name('events.feed');
        Route::post('/events/{eventId}/replay', [EventApiController::class, 'replay'])->name('events.replay');
        Route::get('/webhooks', [EventApiController::class, 'webhooks'])->name('webhooks.index');
        Route::post('/webhooks', [EventApiController::class, 'subscribe'])->name('webhooks.store');
        Route::patch('/webhooks/{uuid}', [EventApiController::class, 'change'])->name('webhooks.update');
        Route::post('/webhooks/{uuid}/rotate', [EventApiController::class, 'rotate'])->name('webhooks.rotate');
        Route::delete('/webhooks/{uuid}', [EventApiController::class, 'unsubscribe'])->name('webhooks.destroy');
        Route::get('/movements', [EventApiController::class, 'movements'])->name('movements');
        Route::get('/reservations', [EventApiController::class, 'reservations'])->name('reservations');
        Route::get('/shipments/{number}', [EventApiController::class, 'shipment'])->name('shipments.show');
        Route::post('/shipments/{number}/tracking', [EventApiController::class, 'track'])->name('shipments.tracking');
        Route::get('/returns', [EventApiController::class, 'returns'])->name('returns');
        Route::get('/epcis/events', [EventApiController::class, 'epcis'])->name('epcis');
    });
