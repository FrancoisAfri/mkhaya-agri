<?php

use App\Modules\BI\Http\Controllers\BIController;
use Illuminate\Support\Facades\Route;

/*
| Business Intelligence — a paid module. Each visual is also checked against the module that owns
| its data, so a company only ever sees dashboards built from what it subscribes to.
*/
// The platform-wide switch (BI for every company, or a paid add-on) — platform administrators only,
// outside the company gate: a platform administrator need not be working inside a company.
Route::get('/bi/platform', [BIController::class, 'platform'])->name('bi.platform');
Route::post('/bi/platform', [BIController::class, 'savePlatform'])->name('bi.platform.save');

Route::middleware(['module.active:business_intelligence'])->prefix('bi')->name('bi.')->group(function () {
    Route::get('/', [BIController::class, 'index'])->name('index');
    Route::post('/', [BIController::class, 'store'])->name('store');
    Route::post('/query', [BIController::class, 'query'])->middleware('throttle:600,1')->name('query');
    Route::get('/export/visual', [BIController::class, 'exportVisual'])->middleware('throttle:60,1')->name('export.visual');
    Route::get('/export/{key}', [BIController::class, 'exportDashboard'])->where('key', '(t:[a-z0-9_]+|[0-9a-f-]{36})')->middleware('throttle:30,1')->name('export.dashboard');
    Route::post('/schedules', [BIController::class, 'storeSchedule'])->name('schedules.store');
    Route::post('/schedules/{id}/toggle', [BIController::class, 'toggleSchedule'])->whereNumber('id')->name('schedules.toggle');
    Route::post('/schedules/{id}/send', [BIController::class, 'sendSchedule'])->whereNumber('id')->middleware('throttle:10,1')->name('schedules.send');
    Route::delete('/schedules/{id}', [BIController::class, 'destroySchedule'])->whereNumber('id')->name('schedules.destroy');
    Route::post('/bookmarks', [BIController::class, 'bookmark'])->name('bookmarks');
    Route::get('/t/{key}', [BIController::class, 'template'])->where('key', '[a-z0-9_]+')->name('template');
    Route::get('/{uuid}', [BIController::class, 'show'])->whereUuid('uuid')->name('show');
    Route::put('/{uuid}', [BIController::class, 'update'])->whereUuid('uuid')->name('update');
    Route::delete('/{uuid}', [BIController::class, 'destroy'])->whereUuid('uuid')->name('destroy');
});
