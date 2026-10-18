<?php

use App\Modules\Admin\Controllers\PlatformAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform-admins')->name('platform-admins.')->middleware('master.admin')->group(function () {
    Route::get('/',                      [PlatformAdminController::class, 'index'])->name('index');
    Route::post('/',                     [PlatformAdminController::class, 'store'])->name('store');
    Route::post('/{userId}/grant',       [PlatformAdminController::class, 'grant'])->name('grant');
    Route::delete('/{userId}/revoke',    [PlatformAdminController::class, 'revoke'])->name('revoke');
});
