<?php

use App\Modules\Admin\Controllers\SystemRestorationController;
use Illuminate\Support\Facades\Route;

Route::prefix('restoration')->name('restoration.')->middleware('platform.admin')->group(function () {

    Route::get('/', [SystemRestorationController::class, 'index'])->name('index');

    Route::post('/{modelKey}/{id}/restore',  [SystemRestorationController::class, 'restore'])->name('restore');
    Route::post('/bulk-restore',             [SystemRestorationController::class, 'bulkRestore'])->name('bulk-restore');

    Route::delete('/{modelKey}/{id}',         [SystemRestorationController::class, 'forceDelete'])->name('force-delete');
    Route::delete('/bulk-force-delete',       [SystemRestorationController::class, 'bulkForceDelete'])->name('bulk-force-delete');
});
