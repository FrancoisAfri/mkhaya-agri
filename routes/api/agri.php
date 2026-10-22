<?php

use App\Modules\Agri\Api\ApiResources;
use App\Modules\Agri\Http\Controllers\Api\FarmApiController;
use App\Modules\Agri\Http\Middleware\AgriApiAbility;
use App\Modules\Agri\Http\Middleware\AgriApiGuard;
use App\Modules\Agri\Http\Middleware\AgriApiIdempotency;
use Illuminate\Support\Facades\Route;

/*
| The public farm API, version 1 (/api/agri/v1). Sanctum tokens issued from Farm Operations → API
| access, each bound to one organisation and acting as its own service user; the tenant comes from
| that user, never the request. Each endpoint names the ability it needs. Rate-limited per token,
| every call logged, POSTs honour Idempotency-Key, errors are RFC 9457 problem documents. The
| contract is generated from these routes and served at /api/agri/v1/openapi.json.
*/
Route::get('/agri/v1/openapi.json', [FarmApiController::class, 'openapi'])->middleware('throttle:60,1')->name('api.agri.v1.openapi');

Route::middleware(['auth:sanctum', AgriApiGuard::class, AgriApiIdempotency::class])
    ->prefix('agri/v1')->name('api.agri.v1.')
    ->group(function () {
        Route::get('/me', [FarmApiController::class, 'me'])->name('me');

        foreach (ApiResources::definitions() as $key => $d) {
            Route::get('/'.$d['path'], [FarmApiController::class, 'index'])->defaults('resource', $key)
                ->middleware(AgriApiAbility::class.':'.$d['ability'])->name($key.'.index');
            Route::get('/'.$d['path'].'/{id}', [FarmApiController::class, 'show'])->defaults('resource', $key)
                ->middleware(AgriApiAbility::class.':'.$d['ability'])->name($key.'.show');
        }
        Route::get('/lots/{id}/trace', [FarmApiController::class, 'trace'])->middleware(AgriApiAbility::class.':harvests:read')->name('lots.trace');
        Route::get('/stock', [FarmApiController::class, 'stock'])->middleware(AgriApiAbility::class.':stock:read')->name('stock.index');
        Route::get('/finance/summary', [FarmApiController::class, 'financeSummary'])->middleware(AgriApiAbility::class.':finance:read')->name('finance.summary');

        Route::post('/field-activities', [FarmApiController::class, 'storeActivity'])->middleware(AgriApiAbility::class.':activities:write')->name('field-activities.store');
        Route::post('/animal-events', [FarmApiController::class, 'storeAnimalEvent'])->middleware(AgriApiAbility::class.':livestock:write')->name('animal-events.store');
        Route::post('/work-orders/{id}/status', [FarmApiController::class, 'workOrderStatus'])->middleware(AgriApiAbility::class.':work_orders:write')->name('work-orders.status');

        Route::get('/webhook-events', [FarmApiController::class, 'webhookEvents'])->name('webhooks.events');
        Route::get('/webhooks', [FarmApiController::class, 'webhooks'])->middleware(AgriApiAbility::class.':webhooks:manage')->name('webhooks.index');
        Route::post('/webhooks', [FarmApiController::class, 'subscribe'])->middleware(AgriApiAbility::class.':webhooks:manage')->name('webhooks.store');
        Route::delete('/webhooks/{id}', [FarmApiController::class, 'unsubscribe'])->middleware(AgriApiAbility::class.':webhooks:manage')->name('webhooks.destroy');
    });
