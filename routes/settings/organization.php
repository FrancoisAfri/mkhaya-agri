<?php

use App\Core\CompanyUnits\Controllers\CompanyUnitsController;
use Illuminate\Support\Facades\Route;

Route::prefix('organization')->name('organization.')->group(function () {
    Route::get('/',              [CompanyUnitsController::class, 'index'])->name('index');
    Route::post('/store',             [CompanyUnitsController::class, 'store'])->name('store');
    Route::put('/{id}',          [CompanyUnitsController::class, 'update'])->name('update');
    Route::post('/{id}/move',    [CompanyUnitsController::class, 'move'])->name('move');
    Route::post('/reorder',      [CompanyUnitsController::class, 'reorder'])->name('reorder');
    Route::delete('/delete/{id}',       [CompanyUnitsController::class, 'destroy'])->name('destroy');
});
