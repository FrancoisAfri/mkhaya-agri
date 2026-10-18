<?php

use App\Core\Permission\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');
Route::post('/permissions/store', [PermissionController::class, 'store'])->name('permissions.store');
Route::put('/permissions/update/{id}', [PermissionController::class, 'update'])->name('permissions.update');
Route::delete('/permissions/delete/{id}', [PermissionController::class, 'destroy'])->name('permissions.destroy');
