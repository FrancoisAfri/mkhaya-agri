<?php

use App\Core\Role\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::get('/roles', [RoleController::class, 'index'])->name('role.list');
Route::post('/role/store', [RoleController::class, 'store'])->name('role.store');
Route::put('/role/{id}', [RoleController::class, 'update'])->name('role.update');
Route::delete('/role/{id}', [RoleController::class, 'destroy'])->name('role.destroy');
Route::post('/role/{id}/permissions', [RoleController::class, 'syncPermissions'])->name('role.permissions');
